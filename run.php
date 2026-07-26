<?php

declare(strict_types=1);

use AMIListener\AMIListener;

require_once __DIR__ . '/vendor/autoload.php';


$configFileName = __DIR__ . '/config.ini';

if (!is_file($configFileName) || !is_readable($configFileName)) {
    fwrite(STDERR, "config.ini не знайдено або не читається: $configFileName\n");
    exit(1);
}

$config = parse_ini_file($configFileName, true);

if ($config === false) {
    fwrite(STDERR, "Не вдалося розпарсити config.ini. Перевірте синтаксис INI-файлу.\n");
    exit(1);
}

foreach (['AsteriskManager', 'GrusherData'] as $requiredSection) {
    if (!isset($config[$requiredSection])) {
        fwrite(STDERR, "У config.ini відсутня секція [$requiredSection]\n");
        exit(1);
    }
}

foreach (['host', 'username', 'secret', 'port'] as $requiredKey) {
    if (!isset($config['AsteriskManager'][$requiredKey]) || $config['AsteriskManager'][$requiredKey] === '') {
        fwrite(STDERR, "У config.ini відсутній [AsteriskManager] $requiredKey\n");
        exit(1);
    }
}

foreach (['path', 'asterisk_type'] as $requiredKey) {
    if (!isset($config['GrusherData'][$requiredKey]) || $config['GrusherData'][$requiredKey] === '') {
        fwrite(STDERR, "У config.ini відсутній [GrusherData] $requiredKey\n");
        exit(1);
    }
}

$GrusherDataPath = rtrim((string) $config['GrusherData']['path'], '/');
$asterisk_type = (int) $config['GrusherData']['asterisk_type'];

function loadPhoneFilters(string $path): array
{
    $empty = ['local' => [], 'skipped' => []];

    if (!is_file($path) || !is_readable($path)) {
        return $empty;
    }

    $loader = static function (string $file): array {
        $local_phones = [];
        $skipped_phones = [];
        include $file;

        return [
            'local' => array_fill_keys(array_map('strval', $local_phones), true),
            'skipped' => array_fill_keys(array_map('strval', $skipped_phones), true),
        ];
    };

    try {
        $result = $loader($path);
    } catch (\Throwable $e) {
        fwrite(STDERR, "Помилка завантаження local_phones.php: " . $e->getMessage() . "\n");
        return $empty;
    }

    return $result;
}

$localPhonesFilePath = __DIR__ . '/local_phones.php';
$phoneFilters = loadPhoneFilters($localPhonesFilePath);
echo color(
    "local_phones.php: local=" . count($phoneFilters['local']) . ", skipped=" . count($phoneFilters['skipped']),
    'light yellow'
);

echo color("Grusher path $GrusherDataPath", 'light yellow');

$php_path = PHP_BINARY !== '' ? PHP_BINARY : trim((string) exec('which php'));
if ($php_path === '' || !is_executable($php_path)) {
    fwrite(STDERR, "Не вдалося визначити шлях до бінарника PHP\n");
    exit(1);
}
echo color("PHP path $php_path", 'light yellow');

$artisan_path = $GrusherDataPath . '/artisan';
if (!is_file($artisan_path)) {
    echo color("УВАГА: artisan не знайдено за шляхом $artisan_path", 'light red');
}
echo color("Artisan path =  $artisan_path", 'light yellow');

$Grusher_artisan_full_path = $php_path . ' ' . escapeshellarg($artisan_path);
echo color("Grusher Artisan full path =  $Grusher_artisan_full_path", 'light yellow');
echo color("Connecting to Asterisk and waiting for responce", 'light yellow');
echo color("Asterisk type = $asterisk_type", 'light yellow');

$ami = new AMIListener(
    (string) $config['AsteriskManager']['username'],
    (string) $config['AsteriskManager']['secret'],
    (string) $config['AsteriskManager']['host'],
    (int) $config['AsteriskManager']['port'],
);

$ami->addListener(function (array $parameter) use ($asterisk_type, $Grusher_artisan_full_path, &$phoneFilters) {

    // asterisk_type = 1  старі/перехідні версії
    if ($asterisk_type === 1) {
        $events = [
            'NewCallerid',
            'Newchannel',
            'Newstate', 
            'BridgeEnter',
            'BridgeLeave',
        ];
        if (isset($parameter['Event']) and in_array($parameter['Event'], $events, true)) {
            //Filter some events or All;
            switch (@$parameter['Event']) {
                // call go to asterisk
                case "Newstate":
                case "Newchannel":
                    if (isset($parameter['ChannelState']) and ((int) $parameter['ChannelState'] === 4)) {
                        if (!isset($parameter['Exten'], $parameter['Uniqueid'])) {
                            break;
                        }
                        $data = [
                            'type' => "call_new",
                            'phone_called' => $parameter['Exten'],
                            'uniq_id' => $parameter['Uniqueid'],
                        ];
                        sendToGrusher($data, $Grusher_artisan_full_path);
                    }
                break;
                case "NewCallerid":
                    if (isset($parameter['ChannelState'], $parameter['Exten'], $parameter['Uniqueid']) and ((int) $parameter['ChannelState'] === 4)) {
                        $data = [
                            'type' => "call_new",
                            'phone_called' => $parameter['Exten'],
                            'uniq_id' => $parameter['Uniqueid'],
                        ];
                        sendToGrusher($data, $Grusher_artisan_full_path);
                    }
                break;
                case "BridgeEnter":
                    if (
                        isset($parameter['ChannelState'], $parameter['BridgeNumChannels'], $parameter['Uniqueid'], $parameter['Channel'])
                        and ((int) $parameter['ChannelState'] === 6)
                        and ((int) $parameter['BridgeNumChannels'] === 1)
                    ) {
                        @preg_match_all("/\D*\/(\d*)[-@]\d*/", $parameter['Channel'], $match);
                        $phone_answered = $match[1][0] ?? 0;
                        $data = [
                            'type' => "call_answer",
                            'phone_answered' => $phone_answered,
                            'uniq_id' => $parameter['Uniqueid'],
                        ];
                        sendToGrusher($data, $Grusher_artisan_full_path);
                    }
                break;

                case "BridgeLeave":
                    if (isset($parameter['Uniqueid'])) {
                        $data = [
                            'type' => "call_end",
                            'uniq_id' => $parameter['Uniqueid'],
                        ];
                        sendToGrusher($data, $Grusher_artisan_full_path);
                    }
                break;
                default:
                    print_r($parameter);
                break;
            }
        }
    }


    // asterisk_type = 2  Asterisk 11.
    else if ($asterisk_type == 2) {
        $events = [
            //'All',
            'Bridge',
            'Newchannel',
            'Newstate',
            'Hangup',
            'HangupRequest',
            //'NewCallerid',
            'AgentConnect',
            'AgentComplete',
            'SoftHangupRequest',
            'QueueMemberStatus',
        ];

        if(isset($parameter['Event']) and in_array($parameter['Event'], $events)){
            //Filter some events or All;
            switch ( @$parameter['Event'] ) {
                // call go to operator
                case "Bridge":
                    if (isset($parameter['Uniqueid1']) and isset($parameter['Uniqueid2'])){
                        if(isset($parameter['Channel1'])){
                            if(is_sip($parameter['Channel1'])){
                                $data =[
                                    'type' => "call_new",
                                    'uniq_id' => $parameter['Uniqueid1'],
                                    'uniq_id1' => $parameter['Uniqueid1'],
                                    'uniq_id2' => $parameter['Uniqueid2'],
                                    'direction' => "OUT",
                                    'phone_called' => $parameter['CallerID2'],
                                    'phone_answered' => extractExtension($parameter['Channel1'],),
                                    'call_via' => $parameter['Channel2'],
                                ];
                                if(isset($parameter['HoldTime']) and ((int) $parameter['HoldTime'] > 0)){
                                    $data['duration_hold'] = $parameter['HoldTime'];
                                }
                                if(isset($parameter['RingTime']) and ((int) $parameter['RingTime'] > 0)){
                                    $data['duration_ring'] = $parameter['RingTime'];
                                }
                                sendToGrusher($data, $Grusher_artisan_full_path);
                            }elseif(is_sip($parameter['Channel2'])){
                                $data =[
                                    'type' => "call_new",
                                    'uniq_id' => $parameter['Uniqueid1'],
                                    'uniq_id1' => $parameter['Uniqueid1'],
                                    'uniq_id2' => $parameter['Uniqueid2'],
                                    'direction' => "IN",
                                    'phone_called' => $parameter['CallerID1'],
                                    'phone_answered' => extractExtension($parameter['Channel2'],),
                                    'call_via' => $parameter['Channel1'],
                                ];
                                if(isset($parameter['HoldTime']) and ((int) $parameter['HoldTime'] > 0)){
                                    $data['duration_hold'] = $parameter['HoldTime'];
                                }
                                if(isset($parameter['RingTime']) and ((int) $parameter['RingTime'] > 0)){
                                    $data['duration_ring'] = $parameter['RingTime'];
                                }
                                sendToGrusher($data, $Grusher_artisan_full_path);
                            }else{

                            }
                        }
                    }elseif (isset($parameter['Uniqueid'])){
                        //$data =[
                        //    'type' => "call_answer",
                        //    'uniq_id' => isset($parameter['Uniqueid']),
                        //    'disposition' => "ANSWERED",
                        //];
                        //sendToGrusher($data, $Grusher_artisan_full_path);
                    }
                break;
                case "Newstate":
                case "Newchannel":
                    if(isset($parameter['ChannelState'])){
                        switch((int)$parameter['ChannelState']){
                            case 4: // incoming call 
                                if(($parameter['CallerIDNum'] == '<unknown>') or ($parameter['CallerIDNum'] == '')){
                                    echo color("Event: ".$parameter['Event'] ."- Received empty number", 'light blue');
                                    return;
                                }
                                if(is_sip($parameter['Channel'])){
                                    $direction = "OUT";
                                }else{
                                    $direction = "IN";
                                }
                                $data =[
                                    'type' => "call_new",
                                    'phone_called' => $parameter['CallerIDNum'],
                                    'uniq_id' => $parameter['Uniqueid'],
                                    'direction' => $direction,
                                    'call_via' => $parameter['Channel'],
                                    'queue' => (isset($parameter['Queue']) ? $parameter['Queue'] : null),
                                ];
                                sendToGrusher($data, $Grusher_artisan_full_path);
                                
                            break;
                            case 5: // ring
                                if(isset($parameter['Channel'])){
                                    if(is_sip($parameter['Channel'])){
                                        $data =[
                                            'type' => "call_to_operator",
                                            'uniq_id' => $parameter['Uniqueid'],
                                            'call_called' => $parameter['ConnectedLineNum'],
                                            'call_called_to' => extractExtension($parameter['Channel']),
                                        ];
                                        sendToGrusher($data, $Grusher_artisan_full_path);
                                    }
                                }
                            break;
                            case 6: // hang up
                                if(isset($parameter['Location'])){
                                    $phone_answered = extractExtension($parameter['Location']);
                                    if($phone_answered == 0){
                                        $data =[
                                            'type' => "call_answer",
                                            'uniq_id' => $parameter['Uniqueid'],
                                        ];
                                    }else{
                                        $data =[
                                            'type' => "call_answer",
                                            'uniq_id' => $parameter['Uniqueid'],
                                            'phone_answered' => $phone_answered,
                                        ];
                                    }
                                    sendToGrusher($data, $Grusher_artisan_full_path);
                                }
                            break;
                        }
                        
                    }
                    $parameter['Event'] = null;
                break;
                case "QueueMemberStatus":
                    if(isset($parameter['Location']) and isset($parameter['Cause'])){
                        $cause = causeToDisposition((int) $parameter['Cause']);
                        $phone_answered = extractExtension($parameter['Location']);
                        if($phone_answered == 0){
                            $data =[
                                'type' => "call_answer",
                                'uniq_id' => $parameter['Uniqueid'],
                                'disposition' => $cause,
                            ];
                        }else{
                            $data =[
                                'type' => "call_answer",
                                'uniq_id' => $parameter['Uniqueid'],
                                'disposition' => $cause,
                                'phone_answered' => extractExtension($parameter['Location']),
                            ];
                        }
                        sendToGrusher($data, $Grusher_artisan_full_path);
                    }
                break;
                // call go to operator and operator is answered
                case "AgentConnect":
                    $data = [];
                    if(isset($parameter['HoldTime'])){
                        $data['duration_hold'] = $parameter['HoldTime'];
                    }
                    if(isset($parameter['RingTime'])){
                        $data['duration_ring'] = $parameter['RingTime'];
                    }
                    if(!empty($data)){
                        $data['type'] = "set_time";
                        $data['uniq_id'] = $parameter['Uniqueid'];
                        sendToGrusher($data, $Grusher_artisan_full_path);
                    }
                break;
                // end call
                case "AgentComplete":
                    $data['type'] = "call_end";
                    $data['uniq_id'] = $parameter['Uniqueid'];
                    if(isset($parameter['HoldTime'])){
                        $data['duration_hold'] = $parameter['HoldTime'];
                    }
                    if(isset($parameter['TalkTime'])){
                        $data['duration_bill'] = $parameter['TalkTime'];
                    }
                    sendToGrusher($data, $Grusher_artisan_full_path);
                break;
                // end call unknown reason
                case "SoftHangupRequest":
                    $cause = causeToDisposition((int) ($parameter['Cause'] ?? 0));
                    $data =[
                        'type' => "call_end_permanent",
                        'disposition' => $cause,
                        'uniq_id' => $parameter['Uniqueid'],
                    ];
                    sendToGrusher($data, $Grusher_artisan_full_path);
                break;
                case "Hangup":
                case "HangupRequest":
                    if(isset($parameter['Cause'])){
                        $cause = causeToDisposition((int) $parameter['Cause']);
                        $data =[
                            'type' => "call_answer",
                            'disposition' => $cause,
                            'uniq_id' => $parameter['Uniqueid'],
                        ];
                        if(($parameter['Event'] == "Hangup") and 
                            (((int)$parameter['Cause'] == 0) or ((int)$parameter['Cause'] == 16))
                        ){
                            $data['ended_at'] = date("Y-m-d H:i:s");
                        }
                        if(isset($parameter['HoldTime'])){
                            $data['duration_hold'] = $parameter['HoldTime'];
                        }
                        if(isset($parameter['TalkTime'])){
                            $data['duration_bill'] = $parameter['TalkTime'];
                        }
                        sendToGrusher($data, $Grusher_artisan_full_path);

                    }
                break;
                default:
                    //print_r($parameter);
                break;
            }
        }
    }

    // asterisk_type = 3  Asterisk (12–22, chan_sip або PJSIP)
    else if ($asterisk_type === 3) {
        static $bridgeState = [];
        static $dialDestinations = [];
        $events = [
            'Newchannel',
            'NewCallerid',
            'DialBegin',
            'BridgeEnter',
            'BridgeLeave',
            'Hangup',
            'HangupRequest',
            'AgentConnect',
            'AgentComplete',
            'SoftHangupRequest',
            'QueueMemberStatus',
        ];
        if (isset($parameter['Event']) and in_array($parameter['Event'], $events, true)) {
            switch ($parameter['Event']) {
                case "Newchannel":
                case "NewCallerid":
                    if (!isset($parameter['ChannelState'], $parameter['CallerIDNum'], $parameter['Uniqueid'], $parameter['Channel'])) {
                        break;
                    }
                    if ($parameter['Event'] === "Newchannel" and (int) $parameter['ChannelState'] !== 0) {
                        break;
                    }
                    if ($parameter['CallerIDNum'] === '<unknown>' or $parameter['CallerIDNum'] === '') {
                        break;
                    }
                    if (isset($dialDestinations[$parameter['Uniqueid']])) {
                        // Це вже відоме призначення активного дзвінка (DialBegin випередив цю подію) - не створюємо окремий запис.
                        break;
                    }
                    if (isSkippedNumber($parameter['CallerIDNum'], $phoneFilters['skipped'])) {
                        // Номер (внутрішній екстеншен своєї ж лінії - для вихідних, чи зовнішній абонент - для вхідних) у списку ігнорування local_phones.php - пропускаємо.
                        break;
                    }

                    $data = [
                        'type' => "call_new",
                        'phone_called' => $parameter['CallerIDNum'],
                        'uniq_id' => $parameter['Uniqueid'],
                        'direction' => isInternalExtension($parameter['Channel'], $phoneFilters['local']) ? "OUT" : "IN",
                        'call_via' => $parameter['Channel'],
                    ];
                    if (!empty($parameter['Queue'])) {
                        $data['queue'] = $parameter['Queue'];
                    }
                    sendToGrusher($data, $Grusher_artisan_full_path);
                break;
                case "DialBegin":
                    if (!isset($parameter['Uniqueid'], $parameter['DestChannel'])) {
                        break;
                    }
                    if (isSkippedNumber((string) extractExtension($parameter['DestChannel']), $phoneFilters['skipped'])) {
                        // Оператор/екстеншен у списку ігнорування - не стежимо за цим конкретним набором (сам дзвінок абонента, якщо він не в списку, продовжує відстежуватись і далі як завжди).
                        break;
                    }
                    if (isset($parameter['DestUniqueid'])) {
                        $dialDestinations[$parameter['DestUniqueid']] = $parameter['Uniqueid'];
                    }
                    if (is_sip($parameter['DestChannel'])) {
                        $callerNum = $parameter['CallerIDNum'] ?? '';
                        if ($callerNum === '<unknown>') {
                            $callerNum = '';
                        }
                        if ($callerNum === '') {
                            $connectedNum = $parameter['ConnectedLineNum'] ?? '';
                            $callerNum = ($connectedNum === '<unknown>') ? '' : $connectedNum;
                        }
                        $data = [
                            'type' => "call_to_operator",
                            'uniq_id' => $parameter['Uniqueid'],
                            'call_called' => $callerNum,
                            'call_called_to' => extractExtension($parameter['DestChannel']),
                        ];
                        sendToGrusher($data, $Grusher_artisan_full_path);
                    }
                break;
                case "BridgeEnter":
                    if (!isset($parameter['BridgeUniqueid'], $parameter['BridgeNumChannels'], $parameter['Uniqueid'], $parameter['Channel'])) {
                        break;
                    }

                    $bridgeId = $parameter['BridgeUniqueid'];
                    $numChannels = (int) $parameter['BridgeNumChannels'];

                    if ($numChannels === 1) {
                        // Перший учасник бриджа - запам'ятовуємо і чекаємо другого.
                        $bridgeState[$bridgeId] = [
                            'uniq_id' => $parameter['Uniqueid'],
                            'channel' => $parameter['Channel'],
                            'caller_id' => $parameter['CallerIDNum'] ?? '',
                        ];
                        break;
                    }
                    if ($numChannels === 2 && isset($bridgeState[$bridgeId])) {
                        $first = $bridgeState[$bridgeId];
                        unset($bridgeState[$bridgeId]); // стан більше не потрібен
                        $entrantA = [
                            'uniq_id' => $first['uniq_id'],
                            'channel' => $first['channel'],
                            'caller_id' => $first['caller_id'],
                        ];
                        $entrantB = [
                            'uniq_id' => $parameter['Uniqueid'],
                            'channel' => $parameter['Channel'],
                            'caller_id' => $parameter['CallerIDNum'] ?? '',
                        ];
                        if (str_starts_with($entrantA['channel'], 'Local/') or str_starts_with($entrantB['channel'], 'Local/')) {
                            break;
                        }
                        $confident = false;
                        if (isset($dialDestinations[$entrantB['uniq_id']]) and $dialDestinations[$entrantB['uniq_id']] === $entrantA['uniq_id']) {
                            $original = $entrantA;
                            $newLeg = $entrantB;
                            $confident = true;
                        } elseif (isset($dialDestinations[$entrantA['uniq_id']]) and $dialDestinations[$entrantA['uniq_id']] === $entrantB['uniq_id']) {
                            $original = $entrantB;
                            $newLeg = $entrantA;
                            $confident = true;
                        } else {
                            $original = $entrantA;
                            $newLeg = $entrantB;
                        }
                        unset($dialDestinations[$newLeg['uniq_id']]);

                        $cleanupUniqId = null;
                        if ($confident) {
                            $cleanupUniqId = $newLeg['uniq_id'];
                        }

                        $isOriginalInternal = isInternalExtension($original['channel'], $phoneFilters['local']);
                        $isNewLegInternal = isInternalExtension($newLeg['channel'], $phoneFilters['local']);

                        $blocked = false;
                        if ($isOriginalInternal and (
                            isSkippedNumber((string) extractExtension($original['channel']), $phoneFilters['skipped'])
                            or isSkippedNumber($newLeg['caller_id'], $phoneFilters['skipped'])
                        )) {
                            $blocked = true;
                        } elseif ($isNewLegInternal and (
                            isSkippedNumber((string) extractExtension($newLeg['channel']), $phoneFilters['skipped'])
                            or isSkippedNumber($original['caller_id'], $phoneFilters['skipped'])
                        )) {
                            $blocked = true;
                        }

                        if ($blocked) {
                            sendToGrusher(['type' => 'del_invalid_call', 'uniq_id' => $original['uniq_id']], $Grusher_artisan_full_path);
                            sendToGrusher(['type' => 'del_invalid_call', 'uniq_id' => $newLeg['uniq_id']], $Grusher_artisan_full_path);
                            break;
                        }

                        $data = [
                            'type' => "call_new",
                            'uniq_id' => $original['uniq_id'],
                            'uniq_id2' => $newLeg['uniq_id'],
                        ];
                        if ($cleanupUniqId !== null) {
                            $data['cleanup_uniq_id'] = $cleanupUniqId;
                        }

                        if ($isOriginalInternal) {
                            $data['direction'] = "OUT";
                            if ($newLeg['caller_id'] !== '') {
                                $data['phone_called'] = $newLeg['caller_id'];
                            }
                            $data['phone_answered'] = extractExtension($original['channel']);
                            $data['call_via'] = $newLeg['channel'];
                            sendToGrusher($data, $Grusher_artisan_full_path);
                        } elseif ($isNewLegInternal) {
                            $data['direction'] = "IN";
                            if ($original['caller_id'] !== '') {
                                $data['phone_called'] = $original['caller_id'];
                            }
                            $data['phone_answered'] = extractExtension($newLeg['channel']);
                            $data['call_via'] = $original['channel'];
                            sendToGrusher($data, $Grusher_artisan_full_path);
                        }
                    }
                break;
                case "BridgeLeave":
					// Прибираємо підвислий стан, якщо другий учасник так і не зайшов у бридж (наприклад, дзвінок скинули під час очікування).
                    if (isset($parameter['BridgeUniqueid'])) {
                        unset($bridgeState[$parameter['BridgeUniqueid']]);
                    }
                break;
                case "AgentConnect":
                    if (!isset($parameter['Uniqueid'])) {
                        break;
                    }
                    $data = [];
                    if (isset($parameter['HoldTime'])) {
                        $data['duration_hold'] = (int) $parameter['HoldTime'];
                    }
                    if (isset($parameter['RingTime'])) {
                        $data['duration_ring'] = (int) $parameter['RingTime'];
                    }
                    if (!empty($data)) {
                        $data['type'] = "set_time";
                        $data['uniq_id'] = $parameter['Uniqueid'];
                        sendToGrusher($data, $Grusher_artisan_full_path);
                    }
                break;
                case "AgentComplete":
                    if (!isset($parameter['Uniqueid'])) {
                        break;
                    }
                    $data = [
                        'type' => "call_end",
                        'uniq_id' => $parameter['Uniqueid'],
                    ];
                    if (isset($parameter['HoldTime'])) {
                        $data['duration_hold'] = (int) $parameter['HoldTime'];
                    }
                    if (isset($parameter['TalkTime'])) {
                        $data['duration_bill'] = (int) $parameter['TalkTime'];
                    }
                    sendToGrusher($data, $Grusher_artisan_full_path);
                break;
                case "QueueMemberStatus":
                    if (!isset($parameter['Location'], $parameter['Uniqueid'])) {
                        break;
                    }
                    $phoneAnswered = extractExtension($parameter['Location']);
                    $data = [
                        'type' => "call_answer",
                        'uniq_id' => $parameter['Uniqueid'],
                    ];
                    if ($phoneAnswered !== 0) {
                        $data['phone_answered'] = $phoneAnswered;
                    }
                    if (isset($parameter['Cause'])) {
                        $data['disposition'] = causeToDisposition((int) $parameter['Cause']);
                    }
                    sendToGrusher($data, $Grusher_artisan_full_path);
                break;

                case "SoftHangupRequest":
                    if (!isset($parameter['Cause'], $parameter['Uniqueid'])) {
                        break;
                    }
                    $data = [
                        'type' => "call_end_permanent",
                        'disposition' => causeToDisposition((int) $parameter['Cause']),
                        'uniq_id' => $parameter['Uniqueid'],
                    ];
                    sendToGrusher($data, $Grusher_artisan_full_path);
                break;
                case "Hangup":
                case "HangupRequest":
                    if (!isset($parameter['Cause'], $parameter['Uniqueid'], $parameter['Channel'])) {
                        break;
                    }
                    unset($dialDestinations[$parameter['Uniqueid']]);
                    foreach ($dialDestinations as $destId => $sourceId) {
                        if ($sourceId === $parameter['Uniqueid']) {
                            unset($dialDestinations[$destId]);
                        }
                    }
                    if (str_starts_with($parameter['Channel'], 'Local/')) {
                        break;
                    }
                    $cause = causeToDisposition((int) $parameter['Cause']);
                    $data = [
                        'type' => "call_answer",
                        'disposition' => $cause,
                        'uniq_id' => $parameter['Uniqueid'],
                    ];
                    if (
                        $parameter['Event'] === "Hangup"
                        and ((int) $parameter['Cause'] === 0 or (int) $parameter['Cause'] === 16)
                    ) {
                        $data['ended_at'] = date("Y-m-d H:i:s");
                    }
                    if (isset($parameter['HoldTime'])) {
                        $data['duration_hold'] = (int) $parameter['HoldTime'];
                    }
                    if (isset($parameter['TalkTime'])) {
                        $data['duration_bill'] = (int) $parameter['TalkTime'];
                    }
                    sendToGrusher($data, $Grusher_artisan_full_path);
                break;

                default:
                    //print_r($parameter);
                break;
            }
        }
    }
});

// Перезавантажуємо local_phones.php раз на 5 хвилин, щоб зміни у списку (додали/прибрали номер) підхоплювались без рестарту всього воркера.
$ami->addTimer(300, function () use (&$phoneFilters, $localPhonesFilePath) {
    $reloaded = loadPhoneFilters($localPhonesFilePath);
    if ($reloaded !== $phoneFilters) {
        $phoneFilters = $reloaded;
        echo color(
            "local_phones.php перезавантажено: local=" . count($phoneFilters['local']) . ", skipped=" . count($phoneFilters['skipped']),
            'light yellow'
        );
    }
});

$ami->start();


function sendToGrusher(array $data, string $commandBase): void
{
    $jsonData = json_encode($data);
    if ($jsonData === false) {
        error_log("JSON encode failed: " . print_r($data, true));
        return;
    }
    $escapedData = escapeshellarg($jsonData); // Безпека: екранування
    $fullCommand = $commandBase . " grusher:asterisk_get $escapedData";
    echo color("Sending to Grusher: $jsonData", 'light green');
    runAsyncCommand($fullCommand);
}

/*
// це для логування
function runAsyncCommand($command) {
    $logFile = __DIR__ . '/grusher_artisan_output.log';
    $bgCommand = $command . " >> " . escapeshellarg($logFile) . " 2>&1 &";
    exec($bgCommand);
}
*/
// це без логів типу fire-and-forget
function runAsyncCommand(string $command): void
{
    exec($command . " > /dev/null 2>&1 &");
}

function extractExtension(string $str): int {
    if (preg_match('/(?:SIP|IAX2|PJSIP)\/(\d+)[@#\-]/i', $str, $m)) {
        return (int) $m[1];
    }
    // Черги/агенти часто ведуться через Local-канали: Local/1001@from-queue-more-00000001;1
    if (preg_match('/^Local\/(\d+)@/i', $str, $m)) {
        return (int) $m[1];
    }
    return 0;
}

function is_sip(string $field): bool {
    if (preg_match('/^(?:SIP|IAX2|PJSIP)\/(\d{2,6})[@#\-]/i', $field)) {
        return true;
    }
    if (preg_match('/^Local\/(\d{2,6})@/i', $field)) {
        return true;
    }
    return false;
}

function causeToDisposition(int $cause): string {
    return match ($cause) {
        0, 16 => 'ANSWERED',
        17 => 'BUSY',
        18 => 'NO ANSWER',
        19 => 'FAILED',
        default => 'UNKNOWN',
    };
}

function detectDirection(string $channel): string {
    return is_sip($channel) ? "OUT" : "IN";
}

function isInternalExtension(string $channel, array $localPhonesSet): bool {
    if (is_sip($channel)) {
        return true;
    }
    if (empty($localPhonesSet)) {
        return false;
    }
    $ext = extractExtension($channel);

    return $ext !== 0 && isset($localPhonesSet[(string) $ext]);
}

function isSkippedNumber(string $number, array $skippedSet): bool {
    return $number !== '' && isset($skippedSet[$number]);
}

function color(string $content, string|int|null $color = null): string {
    if (!empty($color)) {
        $c = is_numeric($color) ? (int) $color : strtolower((string) $color);
    } else {
        $c = random_int(1, 14);
    }
    $cheader = match ($c) {
        1, 'red' => "\033[31m",
        2, 'green' => "\033[32m",
        3, 'yellow' => "\033[33m",
        4, 'blue' => "\033[34m",
        5, 'magenta' => "\033[35m",
        6, 'cyan' => "\033[36m",
        7, 'light grey' => "\033[37m",
        8, 'dark grey' => "\033[90m",
        9, 'light red' => "\033[91m",
        10, 'light green' => "\033[92m",
        11, 'light yellow' => "\033[93m",
        12, 'light blue' => "\033[94m",
        13, 'light magenta' => "\033[95m",
        14, 'light cyan' => "\033[96m",
        default => '',
    };
    $cfooter = "\033[0m";
    $content = $cheader . $content . $cfooter;
    return date("Y-m-d H:i:s") . " - " . $content . PHP_EOL;
}