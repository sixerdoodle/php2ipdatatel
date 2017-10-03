<?php

// 
// WebSocket interface to the ipdatatel web service, https://www.alarmdealer.com/index.php
// must log into the interface and then can send WebSocket commands to the alarmdealer
//
// Routines:
//	Alarm_Login() - log into the alarmdealer interface, has embedded path, username, and password
//		returns the WebSocket client
//  parseHeaders($headers) - convert HTTP headers into an associative array
//		returns headers as an array
//  Alarm_Ping($client) - ping the alarm, it's a NOP to keep the link active
//		returns json decode of the return string
//  Alarm_Arm($client) - uses the embedded key code to arm the alarm.  verifies that the alarm
//                       is in Arm-able state, enters the keys, then verifies it arms
//		returns false if the arm fails and true if it succeeds
//  Alarm_Disarm($client) - embedded key code, effectively same as Arm except looking for different start/stop conditions
//		returns false on fail and true on success
//  Alarm_DisableZone($client) - enters the keycode *9 to disable all zone delay, good for going to bed at night
//		use before Alarm_Arm to disable the zones, returns true/false
//  Alarm_ArmAway($client) - keycode a to "arm in away mode", returns true/false
//  Alarm_ArmStay($client) - keycode s to "arm in stay mode", returns true/false
//  Alarm_WaitForLCD($client, $str, $cnt) - loops every 1 sec looking for $str to show up on the alarm LCD
//		loops for a maximum of $cnt times.   If there are several potential LCD strings that are "OK" append them
//		with a | inbetween.  returns true if one of the specified strings shows up on the LCD within the 
//		iteration limits, false if none of them ever turn up.
//  AlarmLCD($client) - returns the current LCD string, Line 1 and Line 2 concatenated with a space between
//		no verification happens so sometimes this returns blank, sometimes it even errors, so use Alarm_WaitForLCD
//		to wait for specific strings.
//  AlarmStatus($client, $cnt) - returns the full Alarm status, tries to ignore blank and null returns.  returns
//		the full status, LEDs and such (that's don't ever seem to get set!)

require('C:/Program Files/PHP/v5.6/vendor/autoload.php');

use WebSocket\Client;
$AlarmKeyDelay = 1;

// keycode to use to arm/disarm alarm, replace ? with individual PIN numbers
$Alarm_keys = array(
	"{\"action\":\"send_cmd\",\"input\":{\"cmd\":\"?\"}}",
	"{\"action\":\"send_cmd\",\"input\":{\"cmd\":\"?\"}}",
	"{\"action\":\"send_cmd\",\"input\":{\"cmd\":\"?\"}}",
	"{\"action\":\"send_cmd\",\"input\":{\"cmd\":\"?\"}}");

//
// convert HTTP headers into associative array
//
function parseHeaders( $headers ) {
	global $dbg;
    $head = array();
    foreach( $headers as $k=>$v )
    {
        $t = explode( ':', $v, 2 );
        if( isset( $t[1] ) )
            $head[ trim($t[0]) ] = trim( $t[1] );
        else
        {
            $head[] = $v;
            if( preg_match( "#HTTP/[0-9\.]+\s+([0-9]+)#",$v, $out ) )
                $head['reponse_code'] = intval($out[1]);
        }
    }
    return $head;
}

//
// log in and select the device, returns the WebSocket client
//
function Alarm_Login() {
	global $dbg;
	if($dbg) error_log(basename(__FILE__)."[".__LINE__."]\t Alarm Login",0);
	
	// get the initial page to get the php session going
	$url = "https://www.alarmdealer.com/index.php";
	$options = array(
		'http' => array(
		'method'  => 'GET')
	);
	//var_dump( $options);
	$context  = stream_context_create( $options );
	$result = file_get_contents( $url, false, $context );
	$headers = parseHeaders($http_response_header);
	//var_dump();
	$phpsession = substr($headers["Set-Cookie"],0,strpos($headers["Set-Cookie"],';'));

	// do the login to get the encrypted password, replace aaa/bbb w/ your username/password
	$url = "https://www.alarmdealer.com/index.php?mod=auth&action=authenticate";
	$data = array(
		'user_name' => 'aaaaaaaaaa',
		'user_pass' => 'bbbbbbbbbb',
		'return_mod' => '',
		'return_action' => '',
		'return_id' => ''
	);
	$options = array(
		'http' => array(
		'method'  => 'POST',
		'header'=>  "Content-type: application/x-www-form-urlencoded\r\nCookie: dev_qnty=1; ".$phpsession."\r\n",
		'content' => http_build_query($data)
		)
	);
	//var_dump( $options);

	$context  = stream_context_create( $options );
	$result = file_get_contents( $url, false, $context );
	// scan the result
	$needle = "window.username = \"";
	$pos = strpos($result,$needle);
	if ($pos === false) {
		if($dbg) error_log(basename(__FILE__)."[".__LINE__."]\t did not find ".$needle,0);
		return null;
	} else {
		$tmp = substr($result,$pos+strlen($needle));
		$username = substr($tmp,0,strpos($tmp,"\""));
	}
	$needle = "window.epass = \"";
	$pos = strpos($result,$needle);
	if ($pos === false) {
		if($dbg) error_log(basename(__FILE__)."[".__LINE__."]\t did not find ".$needle,0);
		return null;
	} else {
		$tmp = substr($result,$pos+strlen($needle));
		$epass = substr($tmp,0,strpos($tmp,"\""));
	}
	$needle = "window.device_id = \"";
	$pos = strpos($result,$needle);
	if ($pos === false) {
		if($dbg) error_log(basename(__FILE__)."[".__LINE__."]\t did not find ".$needle,0);
		return null;
	} else {
		$tmp = substr($result,$pos+strlen($needle));
		$device_id = substr($tmp,0,strpos($tmp,"\""));
	}
	$needle = "window.user_type = \"";
	$pos = strpos($result,$needle);
	if ($pos === false) {
		if($dbg) error_log(basename(__FILE__)."[".__LINE__."]\t did not find ".$needle,0);
		return null;
	} else {
		$tmp = substr($result,$pos+strlen($needle));
		$user_type = substr($tmp,0,strpos($tmp,"\""));
	}
	if($dbg) {
		var_dump($username);
		var_dump($epass);
		var_dump($device_id);
		var_dump($user_type);
	}

	$client = new Client("wss://alarmdealer.com:8800/ws",array('timeout'=>30));

	$data = array(
	  'action'	=> 'login',
	  'input'   => array(
						'username'		=> $username,
						'epass' 		=> $epass,
						'pass_hashed'	=> 'true',
						'user_type' 	=> $user_type
		)
	);
	$jd = json_encode( $data );
	$client->send($jd);
	$r = $client->receive();
	if($r != "{\"msg\":\"Logged in successfully\",\"status\":\"OK\"}"){
		error_log(basename(__FILE__)."[".__LINE__."]\t logon failed",0);
		return null;
	}
//	$response = json_decode($r); 
//	var_dump($response);
	// should say {"msg":"Logged in successfully","status":"OK"}


	// select device
	if($dbg) error_log(basename(__FILE__)."[".__LINE__."]\t select device",0);
	$data = array(
	  'action'	=> 'select_device',
	  'input'   => array('device_id' => $device_id) );
	$jd = json_encode( $data );
	$client->send($jd);
	$r = $client->receive();
	if($r != "{\"msg\":\"Device is focused\",\"status\":\"OK\"}"){
		error_log(basename(__FILE__)."[".__LINE__."]\t device selection failed",0);
		return null;
	}
	//$response = json_decode($r);
	//var_dump($response);
	// should say {"msg":"Device is focused","status":"OK"}
	if($dbg) error_log(basename(__FILE__)."[".__LINE__."]\t device select done",0);
	return $client;
}

//
// ping the alarm
//
function Alarm_Ping($soc) {
	global $dbg;
	if($dbg) error_log(basename(__FILE__)."[".__LINE__."]\t Ping Alarm",0);
	
	$data = array(
	  'action'	=> 'send_cmd',
	  'input'   => array('cmd' => 'dping') );
	$jd = json_encode( $data );
	if($dbg) error_log(basename(__FILE__)."[".__LINE__."]\t ".$jd,0);
	$soc->send($jd);
	
	$response = json_decode($soc->receive()); 

	return $response;
}

//
// Arm the alarm by entering the keycode
//
function Alarm_Arm($soc) {
	global $dbg;
	global $Alarm_keys;
	global $AlarmKeyDelay;
	if($dbg) error_log(basename(__FILE__)."[".__LINE__."]\t Alarm Arm",0);

	$data = false;
	if(!Alarm_WaitForLCD($soc,"Press (*) for Zone Bypass|System is Ready to Arm|Enter Your Access Code",5)) {
		if($dbg) error_log(basename(__FILE__)."[".__LINE__."]\t Alarm not ready to arm",0);
	} else {
		foreach( $Alarm_keys as $key) {
			if($dbg) error_log(basename(__FILE__)."[".__LINE__."]\t ".$key,0);
			sleep($AlarmKeyDelay);
			$soc->send($key);
			$response = json_decode($soc->receive()); 
		}
		
		if(Alarm_WaitForLCD($soc,"Exit Delay in Progress|System Armed in Away Mode|Armed With No Entry Delay",10)) {
			if($dbg) error_log(basename(__FILE__)."[".__LINE__."]\t system successfully armed",0);
			$data = true;
		} else {
			error_log(basename(__FILE__)."[".__LINE__."]\t system did not arm",0);
		} 
	}
	return $data;
}

//
// Disarm the alarm by entering the keycode
//
function Alarm_Disarm($soc) {
	global $dbg;
	global $Alarm_keys;
	global $AlarmKeyDelay;
	if($dbg) error_log(basename(__FILE__)."[".__LINE__."]\t Disarm",0);

	$data = false;
	if(!Alarm_WaitForLCD($soc,"Exit Delay in Progress|System Armed in Away Mode|Armed With No Entry Delay",5)) {
		if($dbg)error_log(basename(__FILE__)."[".__LINE__."]\t Alarm is not armed",0);
	} else {
		foreach( $Alarm_keys as $key) {
			if($dbg)error_log(basename(__FILE__)."[".__LINE__."]\t ".$key,0);
			sleep($AlarmKeyDelay);
			$soc->send($key);
			$response = json_decode($soc->receive());
			//error_log(print_r($response,true));
		}
		//$response = json_decode($soc->receive()); 
		
		if(!Alarm_WaitForLCD($soc,"System is Ready to Arm|System Disarmed No Alarm Memory",10)) {
			error_log(basename(__FILE__)."[".__LINE__."]\t failed to turn off alarm",0);
		} else {
			$data = true;
		}
	}
	return $data;
}
//
// Press Disable zone code
//
function Alarm_DisableZone($soc) {
	global $dbg;
	global $AlarmKeyDelay;
	if($dbg) error_log(basename(__FILE__)."[".__LINE__."]\t Alarm Disable Zone",0);
	
	$data = false;
	if(!Alarm_WaitForLCD($soc,"System is Ready to Arm",5)) {
		if($dbg)error_log(basename(__FILE__)."[".__LINE__."]\t Alarm not ready to disable",0);  // must be ready to arm to do this
	} else {
		$keys = array(
			"{\"action\":\"send_cmd\",\"input\":{\"cmd\":\"*\"}}",
			"{\"action\":\"send_cmd\",\"input\":{\"cmd\":\"9\"}}");

		foreach( $keys as $key) {
			if($dbg)error_log(basename(__FILE__)."[".__LINE__."]\t ".$key,0);
			sleep($AlarmKeyDelay);
			$soc->send($key);
			$response = json_decode($soc->receive()); 
		}

		if(Alarm_WaitForLCD($soc,"Press (*) for Zone Bypass|Enter Your Access Code",5)) {
			if($dbg)error_log(basename(__FILE__)."[".__LINE__."]\t system successfully disabled zones",0);
			$data = true;
		} else {
			error_log(basename(__FILE__)."[".__LINE__."]\t system failed to disable zones",0);
		}
	}
	return $data;
}

//
// arm the alarm in "Away" mode, that means with the 
// countdown timer.  returns the status of the alarm
// which should show "Exit Delay in Progress" if everything
// worked as it should.
//
function Alarm_ArmAway($soc) {
	global $dbg;
	if($dbg) error_log(basename(__FILE__)."[".__LINE__."]\t Arm in Away mode",0);
	
	$data = false;
	if(!Alarm_WaitForLCD($soc,"System is Ready to Arm",5)) {
		if($dbg)error_log(basename(__FILE__)."[".__LINE__."]\t Alarm not ready to arm",0);
	} else {
		$key = "{\"action\":\"send_cmd\",\"input\":{\"cmd\":\"a\"}}";
		if($dbg)error_log(basename(__FILE__)."[".__LINE__."]\t ".$key,0);
		$soc->send($key);
		$response = json_decode($soc->receive()); 
		
		if(Alarm_WaitForLCD($soc,"Exit Delay in Progress|System Armed in Away Mode|Armed With No Entry Delay",5)) {
			if($dbg)error_log(basename(__FILE__)."[".__LINE__."]\t system successfully armed",0);
			$data = true;
		} else {
			error_log(basename(__FILE__)."[".__LINE__."]\t system failed to arm",0);
		}
	}
	return $data;
}

//
// arm the alarm in Stay mode.  this sets the 
// alarm to trigger right away on a door open
//
function Alarm_ArmStay($soc) {
	global $dbg;
	if($dbg) error_log(basename(__FILE__)."[".__LINE__."]\t Arm in Stay mode",0);
	
	$data = false;
	if(!Alarm_WaitForLCD($soc,"System is Ready to Arm",5)) {
		if($dbg)error_log(basename(__FILE__)."[".__LINE__."]\t Alarm not ready to arm",0);
	} else {
	
		$key = "{\"action\":\"send_cmd\",\"input\":{\"cmd\":\"s\"}}";
		if($dbg)error_log(basename(__FILE__)."[".__LINE__."]\t ".$key,0);
		$soc->send($key);
		$response = json_decode($soc->receive()); 
		
		if(Alarm_WaitForLCD($soc,"Exit Delay in Progress|System Armed in Away Mode|Armed With No Entry Delay",5)) {
			if($dbg)error_log(basename(__FILE__)."[".__LINE__."]\t system successfully armed",0);
			$data = true;
		} else {
			error_log(basename(__FILE__)."[".__LINE__."]\t system did not arm",0);
		} 
	}
	return $data;
}

//
// Wait for an LCD status to show up.  given string with | delimiter and number of attempts
//
function Alarm_WaitForLCD($soc,$str,$cnt) {
	global $dbg;
	if($dbg) error_log(basename(__FILE__)."[".__LINE__."]\t Alarm_WaitForLCD",0);
	$found = false;
	$ii = 0;
	do {
		$ii = $ii + 1;
		if($dbg) error_log(basename(__FILE__)."[".__LINE__."]\t try ".$ii,0);
		$data = AlarmLCD($soc);			// try to get the current LCD display
		if($data == "")$data = "NotFound";
		$found = strpos($str,$data);	// see if the current LCD string is in the returned display
		if($found === false){
			if($dbg)error_log(basename(__FILE__)."[".__LINE__."]\t Alarm_WaitForLCD: is ~".$data."~ in ~".$str."~  NO");
		} else {
			if($dbg)error_log(basename(__FILE__)."[".__LINE__."]\t Alarm_WaitForLCD: is ~".$data."~ in ~".$str."~  YES");
		}
	} while ( ( $found === false ) and ( $ii < $cnt ));
	if( $found !== false ) $found = true;  // note $found !== false is actually a number, have to insert True if it's not false
	return $found;
}

// 
// returns the LCD screen as a string (L1 +sp + L2) or blank is no data is received.
//
Function AlarmLCD($soc) {
	global $dbg;
	if($dbg) error_log(basename(__FILE__)."[".__LINE__."]\t Alarm LCD",0);
	
	$data = array(
	  'action'	=> 'send_cmd',
	  'input'   => array('cmd' => 'status') );
	$jd = json_encode( $data );

	$soc->send($jd);
	//sleep(1);
	$RetVal = "";
	$response = json_decode($soc->receive()); 

	if(is_object($response)) {
		//var_dump($response);
		if($response->{"data"} != "") {
			$data = json_decode($response->{"data"});
			$RetVal = $data->{"LCD_L1"}." ".$data->{"LCD_L2"};
			$RetVal = str_replace("<>","",$RetVal);	// get rid of the crazy <> that shows up
			$RetVal = str_replace("  "," ",$RetVal); // get rid of redundant spaces
			$RetVal = trim($RetVal);
		} else {
			if($dbg) error_log(basename(__FILE__)."[".__LINE__."]\t is blank ".print_r($response->{"data"},true),0);
		}
	}  else {
		if($dbg) error_log(basename(__FILE__)."[".__LINE__."]\t returned no object",0);
	}
	return $RetVal;
}

//
// fetch the alarm status.  comes back as associative array
//{"data":"{\"LCD_AL\":\"0\",\"LCD_BL\":\"0\",\"LCD_L1\":\"System is\",\"LCD_L2\":\"Ready to Arm\",\"LCD_RL\":\"0\"}","status":"OK"}
//LCD_AL = armed/not armed, LCD_RL = ready not/ready, LCD_BL = BYPASS?
// System is Ready to Arm
// Exit Delay in Progress
// System Armed in Away Mode <= this after the long countdown, both s and a produce this
// System Disarmed No Alarm Memory
// *9 yields Press (*) for <> Zone Bypass  then enter code
//
function AlarmStatus( $soc, $cnt ){
	global $dbg;
	global $AlarmKeyDelay;
	if($dbg) error_log(basename(__FILE__)."[".__LINE__."]\t Alarm Status",0);
	$data = array(
	  'action'	=> 'send_cmd',
	  'input'   => array('cmd' => 'status') );
	$jd = json_encode( $data );
	$ii = 0;
	$data = "";
	do {
		$ii = $ii + 1;
		$soc->send($jd);
		$start = time();
		$response = json_decode($soc->receive()); 
		$end = time() - $start;
		if($dbg) error_log(basename(__FILE__)."[".__LINE__."]\t iteration ".$ii." took ".$end." seconds",0);
		if(is_object($response)) {
			//var_dump($response);
			if($response->{"data"} != "") {
				$data = json_decode($response->{"data"});
			}
		} else {
			sleep(1);
		}
	} while ( ($ii < $cnt) and ($data == ""));

	return $data;
}

?>
