<?php
use AMIListener\AMIListener;

require_once __DIR__ . '/vendor/autoload.php';

$configFileName = "config.ini";
$config = parse_ini_file($configFileName, true);
$GrusherDataPath = $config['GrusherData']['path'];
$asterisk_type = $config['GrusherData']['asterisk_type'];
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
    print_r($parameter);


if($asterisk_type == 1){//ast > 11
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
                    print_r($parameter);
                    $data =[
                        'type' => "call_new",
                        'phone_called' => $parameter['Exten'],
                        'uniq_id' => $parameter['Uniqueid'],
                    ];
                    $data = json_encode($data);
                    echo exec($Grusher_artisan_full_path." grusher:asterisk_get '$data' &");
                    echo color("Sending to Grusher: ".$data, 'light green');
                    $parameter['Event'] = null;
                }
            break;
            case "NewCallerid":
                if(isset($parameter['ChannelState']) and ($parameter['ChannelState'] == 4)){
                    print_r($parameter);
                    $data =[
                        'type' => "call_new",
                        'phone_called' => $parameter['Exten'],
                        'uniq_id' => $parameter['Uniqueid'],
                    ];
                    $data = json_encode($data);
                    echo exec($Grusher_artisan_full_path." grusher:asterisk_get '$data' &");
                    echo color("Sending to Grusher: ".$data, 'light green');
                    $parameter['Event'] = null;
                }
            break;
            case "BridgeEnter":
                if(isset($parameter['ChannelState']) and isset($parameter['BridgeNumChannels']) and ($parameter['ChannelState'] == 6)){
                    echo color("Event: ".$parameter['Event'], 'light blue');
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
                    $data = json_encode($data);
                    echo exec($Grusher_artisan_full_path." grusher:asterisk_get '$data' &");
                    echo color("Sending to Grusher: ".$data, 'light green');
                    $parameter['Event'] = null;
                }
            break;

            case "BridgeLeave":
                echo color("Event: ".$parameter['Event'], 'light blue');
                $data =[
                    'type' => "call_end",
                    'uniq_id' => $parameter['Uniqueid'],
                ];
                $data = json_encode($data);
                echo exec($Grusher_artisan_full_path." grusher:asterisk_get '$data' &");
                echo color("Sending to Grusher: ".$data, 'light green');
                $parameter['Event'] = null;
            break;
            default:
                print_r($parameter);
            break;
        }
    }
}else if($asterisk_type == 2){ //ast 11
    $events = [
        //'All',
        'Newstate',
        'NewCallerid',
        'AgentConnect',
        'AgentComplete',
        'SoftHangupRequest',
    ];
    if(isset($parameter['Event']) and in_array($parameter['Event'], $events)){
        //Filter some events or All;
        switch ( @$parameter['Event'] ) {
            // call go to asterisk
            case "NewCallerid":
                echo color("Event: ".$parameter['Event'], 'light blue');
                //Skipping out numbers
                include (dirname(__FILE__)."/local_phones.php");
                if (in_array(trim($parameter['CallerIDNum']), $local_phones)){
                    $call_direction = "OUT";
                    echo color("OUT CALL: ".$parameter['CallerIDNum'] ." - Ignoring", 'light red');
                }else{
                    $call_direction = "IN";
                    $data =[
                        'type' => "call_new",
                        'phone_called' => $parameter['CallerIDNum'],
                        'uniq_id' => $parameter['Uniqueid'],
                    ];
                    $data = json_encode($data);
                    echo exec($Grusher_artisan_full_path." grusher:asterisk_get '$data' &");
                    echo color("Sending to Grusher: ".$data, 'light green');
                }
                $parameter['Event'] = null;
            break;
            // call go to operator
            case "Newstate":
                if(isset($parameter['ChannelState']) and ($parameter['ChannelState'] == 5)){
                    //print_r($parameter);
                    echo color("Event: ".$parameter['Event'], 'light blue');
                    @preg_match_all("/\D*\/(\d*)[-@]\d*/", $parameter['Channel'],$match);
                    $phone_called_to = 0;
                    if(isset($match[1]) and isset($match[1][0])){
                        $phone_called_to = $match[1][0];
                    }
                    $data =[
                        'type' => "call_to_operator",
                        'call_called' => $parameter['ConnectedLineNum'],
                        'call_called_to' => $phone_called_to,
                        'uniq_id' => $parameter['Uniqueid'],
                    ];
                    $data = json_encode($data);
                    echo exec($Grusher_artisan_full_path." grusher:asterisk_get '$data' &");
                    echo color("Sending to Grusher: ".$data, 'light green');
                }
                $parameter['Event'] = null;
            break;
            // call go to operator and operator is answered
            case "AgentConnect":
                echo color("Event: ".$parameter['Event'], 'light blue');
                @preg_match_all("/\D*\/(\d*)[-@]\d*/", $parameter['Channel'],$match);
                $phone_answered = 0;
                if(isset($match[1]) and isset($match[1][0])){
                    $phone_answered = $match[1][0];
                }
                $data =[
                    'type' => "call_answer",
                    'phone_answered' => $phone_answered,
                    'duration_ring' => @$parameter['RingTime'],
                    'duration_hold' => @$parameter['HoldTime'],
                    'uniq_id' => $parameter['Uniqueid'],
                ];
                $data = json_encode($data);
                echo exec($Grusher_artisan_full_path." grusher:asterisk_get '$data' &");
                echo color("Sending to Grusher: ".$data, 'light green');
                $parameter['Event'] = null;
            break;
            // end call
            case "AgentComplete":
                echo color("Event: ".$parameter['Event'], 'light blue');
                    $data =[
                    'type' => "call_end",
                    'duration_bill' => @$parameter['TalkTime'],
                    'duration_hold' => @$parameter['HoldTime'],
                    'uniq_id' => $parameter['Uniqueid'],
                ];
                $data = json_encode($data);
                echo exec($Grusher_artisan_full_path." grusher:asterisk_get '$data' &");
                echo color("Sending to Grusher: ".$data, 'light green');
                $parameter['Event'] = null;
            break;
            // end call unknown reason
            case "SoftHangupRequest":
                echo color("Event: ".$parameter['Event'], 'light blue');
                $data =[
                    'type' => "call_end_permanent",
                    'uniq_id' => $parameter['Uniqueid'],
                ];
                $data = json_encode($data);
                echo exec($Grusher_artisan_full_path." grusher:asterisk_get '$data' &");
                echo color("Sending to Grusher: ".$data, 'light green');
                $parameter['Event'] = null;
            break;
            default:
                print_r($parameter);
            break;
        }
    }
}else if($asterisk_type == 3){ //ast > 11
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
                    print_r($parameter);
                    if($parameter['CallerIDNum'] == '<unknown>') break;
                    if($parameter['CallerIDNum'] == '') break;

                    $data =[
                        'type' => "call_new",
                        'phone_called' => $parameter['CallerIDNum'],
                        'uniq_id' => $parameter['Uniqueid'],
                    ];
                    $data = json_encode($data);
                    echo exec($Grusher_artisan_full_path." grusher:asterisk_get '$data' &");
                    echo color("Sending to Grusher: ".$data, 'light green');
                    $parameter['Event'] = null;
                }
            break;
            case "NewCallerid":
                if(isset($parameter['ChannelState']) and ($parameter['ChannelState'] == 4)){
                    print_r($parameter);
                    if($parameter['CallerIDNum'] == '<unknown>') break;
                    if($parameter['CallerIDNum'] == '') break;

                    $data =[
                        'type' => "call_new",
                        'phone_called' => $parameter['CallerIDNum'],
                        'uniq_id' => $parameter['Uniqueid'],
                    ];
                    $data = json_encode($data);
                    echo exec($Grusher_artisan_full_path." grusher:asterisk_get '$data' &");
                    echo color("Sending to Grusher: ".$data, 'light green');
                    $parameter['Event'] = null;
                }
            break;

            case "DialBegin":
                if(isset($parameter['DestChannelState']) and (($parameter['DestChannelState'] == 0) or ($parameter['DestChannelState'] == 5))){
                    print_r($parameter);
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
                    $data = json_encode($data);
                    echo exec($Grusher_artisan_full_path." grusher:asterisk_get '$data' &");
                    echo color("Sending to Grusher: ".$data, 'light green');
                    $parameter['Event'] = null;
                }
            break;
            case "BridgeEnter":
                if(isset($parameter['ChannelState']) and isset($parameter['BridgeNumChannels']) and ($parameter['ChannelState'] == 6) and ($parameter['BridgeNumChannels'] == 1)){
                    echo color("Event: ".$parameter['Event'], 'light blue');
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
                    $data = json_encode($data);
                    echo exec($Grusher_artisan_full_path." grusher:asterisk_get '$data' &");
                    echo color("Sending to Grusher: ".$data, 'light green');
                    $parameter['Event'] = null;
                }
            break;
            case "BridgeLeave":
                echo color("Event: ".$parameter['Event'], 'light blue');
                $data =[
                    'type' => 'call_end',
                    'uniq_id' => $parameter['Uniqueid'],
                ];
                $data = json_encode($data);
                echo exec($Grusher_artisan_full_path." grusher:asterisk_get '$data' &");
                echo color("Sending to Grusher: ".$data, 'light green');
                $parameter['Event'] = null;
            break;
            default:
                print_r($parameter);
            break;
        }
    }
}


























});
$ami->start();

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