<?php

use AMIListener\AMIListener;

require_once __DIR__ . '/vendor/autoload.php';

$config = parse_ini_file("config.ini", true);

$GrusherDataPath   = $config['GrusherData']['path'] ?? '';
$asterisk_type     = (int) ($config['GrusherData']['asterisk_type'] ?? 1);
$skip_out_call     = (bool) ($config['GrusherData']['skip_out_call'] ?? false);

$php_path          = trim(exec('which php') ?: 'php');
$artisan_full_path = $php_path . ' ' . $GrusherDataPath . '/artisan';

echo color("Grusher path: {$GrusherDataPath}", 'light yellow');
echo color("PHP path: {$php_path}", 'light yellow');
echo color("Artisan full path: {$artisan_full_path}", 'light yellow');
echo color("Starting AMI Listener...", 'light yellow');

// ====================== AMI Listener ======================

$ami = new AMIListener(
    $config['AsteriskManager']['username'],
    $config['AsteriskManager']['secret'],
    $config['AsteriskManager']['host'],
    $config['AsteriskManager']['port']
);

$ami->addListener(function ($parameter) use ($asterisk_type, $artisan_full_path, $skip_out_call) {

    if (!isset($parameter['Event'])) {
        return;
    }

    $event = $parameter['Event'];
    $data  = prepareEventData($parameter, $asterisk_type, $event, $skip_out_call);

    if ($data && !empty($data['type'])) {
        sendToGrusherAsync($data, $artisan_full_path);
    }
});

$ami->start(true); // true = увімкнути авто-reconnect

// ====================== ДОПОМІЖНІ ФУНКЦІЇ ======================

/**
 * Підготовка даних для Grusher (весь великий switch винесений сюди)
 */
function prepareEventData($parameter, $asterisk_type, $event, $skip_out_call)
{
    $data = [
        'uniq_id' => $parameter['Uniqueid'] ?? $parameter['Uniqueid1'] ?? null,
        'type'    => ''
    ];

    // ==================== ASTERISK > 11 (type 1 та 3) ====================
    if (in_array($asterisk_type, [1, 3])) {

        switch ($event) {
            case 'Newchannel':
            case 'NewCallerid':
                if (isset($parameter['ChannelState']) && $parameter['ChannelState'] == 4) {
                    $data['type'] = 'call_new';
                    $data['phone_called'] = $parameter['Exten'] ?? $parameter['CallerIDNum'] ?? '';
                }
                break;

            case 'BridgeEnter':
                if (isset($parameter['ChannelState']) && $parameter['ChannelState'] == 6) {
                    $phone_answered = extractExtension($parameter['Channel'] ?? '');
                    $data['type'] = 'call_answer';
                    $data['phone_answered'] = $phone_answered;
                }
                break;

            case 'BridgeLeave':
                $data['type'] = 'call_end';
                break;

            case 'DialBegin':
                // додай свою логіку для type 3 якщо потрібно
                break;
        }

        return $data;
    }

    // ==================== ASTERISK == 11 (type 2) ====================
    if ($asterisk_type === 2) {

        switch ($event) {
            case 'Bridge':
                if (isset($parameter['Uniqueid1'], $parameter['Uniqueid2'])) {
                    $data['uniq_id1'] = $parameter['Uniqueid1'];
                    $data['uniq_id2'] = $parameter['Uniqueid2'];

                    if (is_sip($parameter['Channel1'] ?? '')) {
                        $data['type']       = 'call_new';
                        $data['direction']  = 'OUT';
                        $data['phone_called']   = $parameter['CallerID2'] ?? '';
                        $data['phone_answered'] = extractExtension($parameter['Channel1'] ?? '');
                        $data['call_via']   = $parameter['Channel2'] ?? '';
                    } elseif (is_sip($parameter['Channel2'] ?? '')) {
                        $data['type']       = 'call_new';
                        $data['direction']  = 'IN';
                        $data['phone_called']   = $parameter['CallerID1'] ?? '';
                        $data['phone_answered'] = extractExtension($parameter['Channel2'] ?? '');
                        $data['call_via']   = $parameter['Channel1'] ?? '';
                    }

                    if (isset($parameter['HoldTime']) && $parameter['HoldTime'] > 0) {
                        $data['duration_hold'] = $parameter['HoldTime'];
                    }
                    if (isset($parameter['RingTime']) && $parameter['RingTime'] > 0) {
                        $data['duration_ring'] = $parameter['RingTime'];
                    }
                }
                break;

            case 'Newstate':
            case 'Newchannel':
                if (isset($parameter['ChannelState'])) {
                    switch ((int)$parameter['ChannelState']) {
                        case 4: // Ring / New call
                            if (empty($parameter['CallerIDNum']) || $parameter['CallerIDNum'] === '<unknown>') {
                                return null;
                            }
                            $data['type'] = 'call_new';
                            $data['phone_called'] = $parameter['CallerIDNum'];
                            $data['direction'] = is_sip($parameter['Channel'] ?? '') ? 'OUT' : 'IN';
                            $data['call_via'] = $parameter['Channel'] ?? '';
                            $data['queue'] = $parameter['Queue'] ?? null;
                            break;

                        case 5: // Ring to operator
                            if (is_sip($parameter['Channel'] ?? '')) {
                                $data['type'] = 'call_to_operator';
                                $data['call_called'] = $parameter['ConnectedLineNum'] ?? '';
                                $data['call_called_to'] = extractExtension($parameter['Channel'] ?? '');
                            }
                            break;

                        case 6:
                            $data['type'] = 'call_answer';
                            $data['phone_answered'] = extractExtension($parameter['Location'] ?? '');
                            break;
                    }
                }
                break;

            case 'AgentConnect':
                $data['type'] = 'set_time';
                if (isset($parameter['HoldTime'])) $data['duration_hold'] = $parameter['HoldTime'];
                if (isset($parameter['RingTime'])) $data['duration_ring'] = $parameter['RingTime'];
                break;

            case 'AgentComplete':
                $data['type'] = 'call_end';
                if (isset($parameter['HoldTime'])) $data['duration_hold'] = $parameter['HoldTime'];
                if (isset($parameter['TalkTime'])) $data['duration_bill'] = $parameter['TalkTime'];
                break;

            case 'Hangup':
            case 'HangupRequest':
            case 'SoftHangupRequest':
                $cause = match ((int)($parameter['Cause'] ?? 0)) {
                    0, 16 => 'ANSWERED',
                    17 => 'BUSY',
                    18 => 'NO ANSWER',
                    19 => 'FAILED',
                    default => 'UNKNOWN'
                };
                $data['type'] = ($event === 'Hangup' && in_array((int)($parameter['Cause'] ?? 0), [0,16])) 
                    ? 'call_end' 
                    : 'call_answer';
                $data['disposition'] = $cause;
                if (isset($parameter['HoldTime'])) $data['duration_hold'] = $parameter['HoldTime'];
                if (isset($parameter['TalkTime'])) $data['duration_bill'] = $parameter['TalkTime'];
                if ($event === 'Hangup' && in_array((int)($parameter['Cause'] ?? 0), [0,16])) {
                    $data['ended_at'] = date('Y-m-d H:i:s');
                }
                break;

            case 'QueueMemberStatus':
                // твоя логіка для QueueMemberStatus
                break;
        }
    }

    return $data;
}

/**
 * Асинхронний запуск команди Grusher (без блокування Workerman!)
 */
function sendToGrusherAsync($data, $commandBase)
{
    $jsonData = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

    $escapedJson = escapeshellarg($jsonData);
    $fullCommand = $commandBase . " grusher:asterisk_get " . $escapedJson;

    echo color("→ Sending to Grusher: {$jsonData}", 'light green');

    // Запуск у фоні без очікування (найнадійніший спосіб для Workerman)
    exec($fullCommand . " > /dev/null 2>&1 &");
}

// ====================== Твої допоміжні функції ======================

function extractExtension($str)
{
    if (preg_match('/(?:SIP|IAX2|PJSIP)\/(\d+)[@#\-]/i', $str, $m)) {
        return (int)$m[1];
    }
    return 0;
}

function is_sip($field)
{
    return (bool) preg_match('/^(?:SIP|IAX2|PJSIP)\/(\d{3,4})[@#\-]/i', $field);
}

function color($content, $color = null)
{
    $cheader = '';
    switch (strtolower($color ?? '')) {
        case 'red':           $cheader = "\033[31m"; break;
        case 'green':         $cheader = "\033[32m"; break;
        case 'yellow':        $cheader = "\033[33m"; break;
        case 'blue':          $cheader = "\033[34m"; break;
        case 'light yellow':  $cheader = "\033[93m"; break;
        case 'light green':   $cheader = "\033[92m"; break;
        case 'light blue':    $cheader = "\033[94m"; break;
        default:              $cheader = "\033[97m";
    }
    return date("Y-m-d H:i:s") . " - " . $cheader . $content . "\033[0m" . PHP_EOL;
}