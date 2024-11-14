<?php

namespace App;

include dirname( __DIR__, 3 ). '/autoload.php';

while( true ){

	if( Utils::check_asterisk_status() ) {
		shell_exec('php daemon_manager.php &');
	}
	else {
		Utils::put_log("Asterisk is not working");
	}
	sleep(5);
}
