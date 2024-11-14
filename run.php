<?php
namespace App;
use App\Ami;
include 'vendor/autoload.php';
set_time_limit(0);
echo color("Starting Grusher Asterisk AMI", 'light yellow');

$configFileName = "config.ini";
$config = parse_ini_file($configFileName, true);
$GrusherDataPath = $config['GrusherData']['path'];
echo color("Grusher path $GrusherDataPath", 'light yellow');
$php_path = exec ('which php');
echo color("PHP path $php_path", 'light yellow');
$artisan_path = $GrusherDataPath."/artisan";
echo color("Artisan path =  $artisan_path", 'light yellow');
$Grusher_artisan_full_path = $php_path." ". $GrusherDataPath."/artisan";
echo color("Grusher Artisan full path =  $Grusher_artisan_full_path", 'light yellow');


$lock_filename =__DIR__ . '/ami_service.lock';
echo color("Lock filename =  $lock_filename", 'light yellow');
echo color("Checking locking", 'light blue');
$lock_file = fopen($lock_filename, 'c');
$got_lock = flock($lock_file, LOCK_EX | LOCK_NB, $wouldblock);
if ($lock_file === false || (!$got_lock && !$wouldblock)) {
	echo color("Unexpected error opening or locking lock file. Perhaps you don't  have permission to write to the lock file or its containing directory?", 'red');
	exit();
}else if (!$got_lock && $wouldblock) {
    echo color("Another instance is already running; terminating", 'red');
    exit();
}else{
	echo color("No another instance is already running", 'light green');
}
echo color("Locking file", 'light blue');
ftruncate($lock_file, 0);
fwrite($lock_file, getmypid() . "\n");
echo color("Connecting to Asterisk and waiting for responce", 'light yellow');
$event = [
	//'All',
	'NewCallerid',
	'AgentConnect',
	'AgentComplete',
	'SoftHangupRequest',
];
echo color("Waiting for this events: ".implode (", ", $event), 'light yellow');
$ami = new Ami();

//Filter some events or All;

do {
	switch ( @$amiEvent['Event'] ) {
		// call go to asterisk
		case "NewCallerid":
			echo color("Event: ".$amiEvent['Event'], 'light blue');
        	//include ("./local_phones.php");
        	//if (in_array($amiEvent['CallerIDNum'], $local_phones)){
	        //    $call_direction = "OUT";
        	//}else{
	        	$call_direction = "IN";
	        	$data =[
	        		'type' => $amiEvent['Event'],
	        		'phone_called' => $amiEvent['CallerIDNum'],
	        		'uniq_id' => $amiEvent['Uniqueid'],
	        	];
	        	$data = json_encode($data);
	        	echo exec($Grusher_artisan_full_path." grusher:asterisk_get '$data'");
	        	echo color("Sending to Grusher: ".$data, 'light green');
			//}
			$amiEvent['Event'] = null;
		break;

		case "AgentConnect":
			echo color("Event: ".$amiEvent['Event'], 'light blue');
			@preg_match_all("/\D*\/(\d*)[-@]\d*/", $amiEvent['Channel'],$match);
			$phone_answered = 0;
			if(isset($match[1]) and isset($match[1][0])){
				$phone_answered = $match[1][0];
			}
			$data =[
				'type' => $amiEvent['Event'],
				'phone_answered' => $phone_answered,
				'duration_ring' => @$amiEvent['RingTime'],
				'duration_hold' => @$amiEvent['HoldTime'],
				'uniq_id' => $amiEvent['Uniqueid'],
			];
			$data = json_encode($data);
			echo exec($Grusher_artisan_full_path." grusher:asterisk_get '$data' &");
			echo color("Sending to Grusher: ".$data, 'light green');
			$amiEvent['Event'] = null;
		break;
		case "AgentComplete":
			echo color("Event: ".$amiEvent['Event'], 'light blue');
				$data =[
				'type' => $amiEvent['Event'],
				'duration_bill' => @$amiEvent['TalkTime'],
				'duration_hold' => @$amiEvent['HoldTime'],
				'uniq_id' => $amiEvent['Uniqueid'],
			];
			$data = json_encode($data);
			echo exec($Grusher_artisan_full_path." grusher:asterisk_get '$data' &");
			echo color("Sending to Grusher: ".$data, 'light green');
			$amiEvent['Event'] = null;
		break;
		case "SoftHangupRequest":
			echo color("Event: ".$amiEvent['Event'], 'light blue');
			$data =[
        		'type' => $amiEvent['Event'],
        		'uniq_id' => $amiEvent['Uniqueid'],
        	];
        	$data = json_encode($data);
        	echo exec($Grusher_artisan_full_path." grusher:asterisk_get '$data'");
        	echo color("Sending to Grusher: ".$data, 'light green');
        	$amiEvent['Event'] = null;
		break;

		default:
			$amiEvent = $ami->getEvent($event);
			//print_r($amiEvent);
		break;
	}
}

while ( Utils::check_asterisk_status() );

function color($content, $color=null)
    {
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
?>
