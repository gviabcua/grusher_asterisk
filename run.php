<?php
use AMIListener\AMIListener;

require_once __DIR__ . '/vendor/autoload.php';

$configFileName = "config.ini";
$config = parse_ini_file($configFileName, true);
$GrusherDataPath = $config['GrusherData']['path'];
$asterisk_type = $config['GrusherData']['asterisk_type'];
//$skip_out_call = $config['GrusherData']['skip_out_call'];
echo color("Grusher path $GrusherDataPath", 'light yellow');
$php_path = exec ('which php');
echo color("PHP path $php_path", 'light yellow');
$artisan_path = $GrusherDataPath."/artisan";
echo color("Artisan path =  $artisan_path", 'light yellow');
$Grusher_artisan_full_path = $php_path." ". $GrusherDataPath."/artisan";
echo color("Grusher Artisan full path =  $Grusher_artisan_full_path", 'light yellow');
echo color("Connecting to Asterisk and waiting for responce", 'light yellow');
$ami = new AMIListener(
    $config['AsteriskManager']['username'], 
    $config['AsteriskManager']['secret'],
    $config['AsteriskManager']['host'],
    $config['AsteriskManager']['port']
);

$ami->addListener(function($parameter) use ($asterisk_type, $Grusher_artisan_full_path,){
    // ASTERISK > 11
    if($asterisk_type == 1){
        $events = [
            'NewCallerid', // ??
            'Newchannel',
            'BridgeEnter',
            'BridgeLeave',
        ];
        if(isset($parameter['Event']) and in_array($parameter['Event'], $events)){
            //Filter some events or All;
            switch ( @$parameter['Event'] ) {
                // call go to asterisk
                case "Newchannel":
                    if(isset($parameter['ChannelState']) and ($parameter['ChannelState'] == 4)){
                        $data =[
                            'type' => "call_new",
                            'phone_called' => $parameter['Exten'],
                            'uniq_id' => $parameter['Uniqueid'],
                        ];
                        sendToGrusher($data, $Grusher_artisan_full_path);
                    }
                break;
                case "NewCallerid":
                    if(isset($parameter['ChannelState']) and ($parameter['ChannelState'] == 4)){
                        $data =[
                            'type' => "call_new",
                            'phone_called' => $parameter['Exten'],
                            'uniq_id' => $parameter['Uniqueid'],
                        ];
                        sendToGrusher($data, $Grusher_artisan_full_path);
                    }
                break;
                case "BridgeEnter":
                    if(isset($parameter['ChannelState']) and isset($parameter['BridgeNumChannels']) and ($parameter['ChannelState'] == 6)){
                        @preg_match_all("/\D*\/(\d*)[-@]\d*/", $parameter['Channel'],$match);
                        $phone_answered = 0;
                        if(isset($match[1]) and isset($match[1][0])){
                            $phone_answered = $match[1][0];
                        }
                        $data =[
                            'type' => "call_answer",
                            'phone_answered' => $phone_answered,
                            'uniq_id' => $parameter['Uniqueid'],
                        ];
                        sendToGrusher($data, $Grusher_artisan_full_path);
                    }
                break;

                case "BridgeLeave":
                    $data =[
                        'type' => "call_end",
                        'uniq_id' => $parameter['Uniqueid'],
                    ];
                    sendToGrusher($data, $Grusher_artisan_full_path);
                break;
                default:
                    print_r($parameter);
                break;
            }
        }
    }


    // Astersisk == 11
    else if($asterisk_type == 2){
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
                        switch((int)$parameter['Cause']){
                            case 0:case 16:$cause = 'ANSWERED';break;
                            case 17:$cause = 'BUSY';break;
                            case 18:$cause = 'NO ANSWER';break;
                            case 19:$cause = 'FAILED';break;
                            default:$cause = 'UNKNOWN';break;
                        }
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
                    switch((int)$parameter['Cause']){
                        case 0:case 16:$cause = 'ANSWERED';break;
                        case 17:$cause = 'BUSY';break;
                        case 18:$cause = 'NO ANSWER';break;
                        case 19:$cause = 'FAILED';break;
                        default:$cause = 'UNKNOWN';break;
                    }
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
                        switch((int)$parameter['Cause']){
                            case 0:case 16:$cause = 'ANSWERED';break;
                            case 17:$cause = 'BUSY';break;
                            case 18:$cause = 'NO ANSWER';break;
                            case 19:$cause = 'FAILED';break;
                            default:$cause = 'UNKNOWN';break;
                        }
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








    // Astersisk > 11
    else if($asterisk_type == 3){
        $events = [
            'Newchannel',
            'BridgeEnter',
            'BridgeLeave',
        ];
        if(isset($parameter['Event']) and in_array($parameter['Event'], $events)){
            switch ( @$parameter['Event'] ) {
                // call go to asterisk
                case "Newchannel":
                    if(isset($parameter['ChannelState']) and ($parameter['ChannelState'] == 0)){
                        if($parameter['CallerIDNum'] == '<unknown>') break;
                        if($parameter['CallerIDNum'] == '') break;

                        $data =[
                            'type' => "call_new",
                            'phone_called' => $parameter['CallerIDNum'],
                            'uniq_id' => $parameter['Uniqueid'],
                        ];
                        sendToGrusher($data, $Grusher_artisan_full_path);
                    }
                break;
                case "NewCallerid":
                    if(isset($parameter['ChannelState']) and ($parameter['ChannelState'] == 4)){
                        if($parameter['CallerIDNum'] == '<unknown>') break;
                        if($parameter['CallerIDNum'] == '') break;

                        $data =[
                            'type' => "call_new",
                            'phone_called' => $parameter['CallerIDNum'],
                            'uniq_id' => $parameter['Uniqueid'],
                        ];
                        sendToGrusher($data, $Grusher_artisan_full_path);
                    }
                break;

                case "DialBegin":
                    if(isset($parameter['DestChannelState']) and (($parameter['DestChannelState'] == 0) or ($parameter['DestChannelState'] == 5))){
                        if($parameter['DestUniqueid'] == ''){
                            $parameter['DestUniqueid'] = $parameter['Uniqueid'];
                        } 

                        if($parameter['DestCallerIDNum'] == '<unknown>') $parameter['DestCallerIDNum'] = $parameter['DialString'];
                        if($parameter['DestCallerIDNum'] == '') $parameter['DestCallerIDNum'] = $parameter['DialString'];
                        @preg_match_all("/[a-z]*\/?([^@]*)/i", $parameter['DestCallerIDNum'],$match);
                        $phone_to_called = 0;
                        if(isset($match[1]) and isset($match[1][0])){
                            $phone_to_called = $match[1][0];
                        }else{
                            $phone_to_called = $parameter['DestCallerIDNum'];
                        }
                        $data =[
                            'type' => "call_calling",
                            'phone_to_called' => $phone_to_called,
                            'uniq_id' => $parameter['Uniqueid'],
                            'dest_uniq_id' => $parameter['DestUniqueid'],
                        ];
                        sendToGrusher($data, $Grusher_artisan_full_path);
                    }
                break;
                case "BridgeEnter":
                    if(isset($parameter['ChannelState']) and isset($parameter['BridgeNumChannels']) and ($parameter['ChannelState'] == 6) and ($parameter['BridgeNumChannels'] == 1)){
                        @preg_match_all("/\D*\/(\d*)[-@]\d*/", $parameter['Channel'],$match);
                        $phone_answered = 0;
                        if(isset($match[1]) and isset($match[1][0])){
                            $phone_answered = $match[1][0];
                        }
                        $data =[
                            'type' => "AgentConnect",
                            'phone_answered' => $phone_answered,
                            'uniq_id' => $parameter['Uniqueid'],
                        ];
                        sendToGrusher($data, $Grusher_artisan_full_path);
                    }
                break;
                case "BridgeLeave":
                    $data =[
                        'type' => 'call_end',
                        'uniq_id' => $parameter['Uniqueid'],
                    ];
                    sendToGrusher($data, $Grusher_artisan_full_path);
                break;
                default:
                    print_r($parameter);
                break;
            }
        }
    }
});
$ami->start();



function sendToGrusher($data, $commandBase) { 
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
function runAsyncCommand($command) {
    exec($command . " > /dev/null 2>&1 &");
}
function extractExtension($str) {
    if (preg_match('/(?:SIP|IAX2|PJSIP)\/(\d+)[@#\-]/i', $str, $m)) {
        return (int)$m[1];
    }
    return 0;
}
function is_sip($field){
    if (preg_match('/^(?:SIP|IAX2|PJSIP)\/(\d{3,4})[@#\-]/i', $field)) {
        return true;
    } 
    return;
}
function color($content, $color=null){
    if(!empty($color)){if(!is_numeric($color)){$c = strtolower($color);}else{if(!empty($color)){$c = $color;}else{$c = rand(1,14);}}}else{$c = rand(1,14);}
    $cheader = '';
    $cfooter = "\033[0m";
    switch($c)
    {
        case 1:case 'red':$cheader .= "\033[31m";break;
        case 2:case 'green':$cheader .= "\033[32m";break;
        case 3:case 'yellow':$cheader .= "\033[33m";break;
        case 4:case 'blue':$cheader .= "\033[34m";break;
        case 5:case 'magenta':$cheader .= "\033[35m";break;
        case 6:case 'cyan':$cheader .= "\033[36m";break;
        case 7:case 'light grey':$cheader .= "\033[37m";break;
        case 8:case 'dark grey':$cheader .= "\033[90m";break;
        case 9:case 'light red':$cheader .= "\033[91m";break;
        case 10:case 'light green':$cheader .= "\033[92m";break;
        case 11:case 'light yellow':$cheader .= "\033[93m";break;
        case 12:case 'light blue':$cheader .= "\033[94m";break;
        case 13:case 'light magenta':$cheader .= "\033[95m";break;
        case 14:case 'light cyan':$cheader .= "\033[92m";break;
    }
    $content = $cheader.$content.$cfooter;
    return date("Y-m-d H:i:s") ." - " . $content.PHP_EOL;
}
