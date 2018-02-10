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
//  Alarm_NoEntryDelay($client) - enters the keycode *9 to disable all zone delay, good for going to bed at night
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
//  AlarmStatus($client) - returns the full Alarm status, tries to ignore blank and null returns.  returns
//		the full status, LEDs and such (that's don't ever seem to get set!)

require('C:/Program Files/PHP/v5.6/vendor/autoload.php');

const BlankIsOK = true;
const BlankNotOK = false;
const AlarmKeyDelay = 500000;   // 1/2 a second

use WebSocket\Client;

// key-code to use to arm/disarm alarm
$Alarm_keys = array(
	"{\"action\":\"send_cmd\",\"input\":{\"cmd\":\"?\"}}",  <== your
	"{\"action\":\"send_cmd\",\"input\":{\"cmd\":\"?\"}}",  <== 4 digit
	"{\"action\":\"send_cmd\",\"input\":{\"cmd\":\"?\"}}",  <== code
	"{\"action\":\"send_cmd\",\"input\":{\"cmd\":\"?\"}}"); <== here

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
	if($dbg) error_log(basename(__FILE__)."[".__LINE__."/".__FUNCTION__."]",0);
	
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
	$sessID = substr($phpsession,strpos($phpsession,'=')+1);
	if($dbg) error_log(basename(__FILE__)."[".__LINE__."/".__FUNCTION__."]\t php sessID:".$sessID." phpsession:".$phpsession,0);

	// do the login to get the encrypted password
	$url = "https://www.alarmdealer.com/index.php?mod=auth&action=authenticate";
	$data = array(
		'user_name' => '???????',  <== your username
		'user_pass' => '???????',  <== and password to IPdatatel web site here
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
	$username = "";
	$pos = strpos($result,$needle);
	if ($pos === false) {
		if($dbg) error_log(basename(__FILE__)."[".__LINE__."/".__FUNCTION__."]\t did not find ".$needle,0);
		return null;
	} else {
		$tmp = substr($result,$pos+strlen($needle));
		$username = substr($tmp,0,strpos($tmp,"\""));
	}
	$needle = "window.epass = \"";
	$epass = "";
	$pos = strpos($result,$needle);
	if ($pos === false) {
		if($dbg) error_log(basename(__FILE__)."[".__LINE__."/".__FUNCTION__."]\t did not find ".$needle,0);
		return null;
	} else {
		$tmp = substr($result,$pos+strlen($needle));
		$epass = substr($tmp,0,strpos($tmp,"\""));
	}
	$needle = "window.device_id = \"";
	$device_id = "";
	$pos = strpos($result,$needle);
	if ($pos === false) {
		if($dbg) error_log(basename(__FILE__)."[".__LINE__."/".__FUNCTION__."]\t did not find ".$needle,0);
		return null;
	} else {
		$tmp = substr($result,$pos+strlen($needle));
		$device_id = substr($tmp,0,strpos($tmp,"\""));
	}
	$needle = "window.user_type = \"";
	$user_type = "";
	$pos = strpos($result,$needle);
	if ($pos === false) {
		if($dbg) error_log(basename(__FILE__)."[".__LINE__."/".__FUNCTION__."]\t did not find ".$needle,0);
		return null;
	} else {
		$tmp = substr($result,$pos+strlen($needle));
		$user_type = substr($tmp,0,strpos($tmp,"\""));
	}
	if($dbg) {
		error_log(basename(__FILE__)."[".__LINE__."/".__FUNCTION__."]\t username:".var_export($username,true),0);
		error_log(basename(__FILE__)."[".__LINE__."/".__FUNCTION__."]\t epass:".var_export($epass,true),0);
		error_log(basename(__FILE__)."[".__LINE__."/".__FUNCTION__."]\t device_id:".var_export($device_id,true),0);
		error_log(basename(__FILE__)."[".__LINE__."/".__FUNCTION__."]\t user_type:".var_export($user_type,true),0);
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
	$client->setTimeout(20);
	$jd = json_encode( $data );
	$client->send($jd);
	$r = $client->receive();
	if($r != "{\"msg\":\"Logged in successfully\",\"status\":\"OK\"}"){
		error_log(basename(__FILE__)."[".__LINE__."]\t logon failed: ".$r,0);
		return null;
	}
//	$response = json_decode($r); 
//	var_dump($response);
	// should say {"msg":"Logged in successfully","status":"OK"}


	// select device
	if($dbg) error_log(basename(__FILE__)."[".__LINE__."/".__FUNCTION__."]\t select device",0);
	$data = array(
	  'action'	=> 'select_device',
	  'input'   => array('device_id' => $device_id) );
	$jd = json_encode( $data );
	usleep(AlarmKeyDelay);
	$client->send($jd);
	$r = $client->receive();
	if($r != "{\"msg\":\"Device is focused\",\"status\":\"OK\"}"){
		error_log(basename(__FILE__)."[".__LINE__."/".__FUNCTION__."]\t device selection failed",0);
		return null;
	}
	//$response = json_decode($r);
	//var_dump($response);
	// should say {"msg":"Device is focused","status":"OK"}
	if($dbg) error_log(basename(__FILE__)."[".__LINE__."/".__FUNCTION__."]\t device select done",0);
	
	//
	// send an initial reset
	//
	//Alarm_Reset($client);
	return $client;
}

//
// ping the alarm
//
function Alarm_Ping($soc) {
	global $dbg;
	if($dbg) error_log(basename(__FILE__)."[".__LINE__."/".__FUNCTION__."]\t Ping Alarm",0);
	
	$data = array(
	  'action'	=> 'send_cmd',
	  'input'   => array('cmd' => 'dping') );
	$jd = json_encode( $data );
	if($dbg) error_log(basename(__FILE__)."[".__LINE__."/".__FUNCTION__."]\t ".$jd,0);
	$response = AlarmSend($soc,$jd,BlankIsOK); 

	return $response;
}

//
// Arm the alarm by entering the keycode
//
function Alarm_Arm($soc) {
	global $dbg;
	global $Alarm_keys;
	if($dbg) error_log(basename(__FILE__)."[".__LINE__."/".__FUNCTION__."]\t Alarm Arm",0);

	$data = false;
	if(!Alarm_WaitForLCD($soc,"Press (*) for Zone Bypass|System is Ready to Arm|Enter Your Access Code",5)) {
		if($dbg) error_log(basename(__FILE__)."[".__LINE__."/".__FUNCTION__."]\t Alarm not ready to arm",0);
	} else {
		foreach( $Alarm_keys as $key) {
			if($dbg) error_log(basename(__FILE__)."[".__LINE__."/".__FUNCTION__."]\t ".$key,0);
			AlarmSend($soc,$key,BlankIsOK);
		}
		
		if(Alarm_WaitForLCD($soc,"Exit Delay in Progress|System Armed in Away Mode|Armed With No Entry Delay",10)) {
			if($dbg) error_log(basename(__FILE__)."[".__LINE__."/".__FUNCTION__."]\t system successfully armed",0);
			$data = true;
		} else {
			error_log(basename(__FILE__)."[".__LINE__."/".__FUNCTION__."]\t system did not arm",0);
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
	error_log(basename(__FILE__)."[".__LINE__."/".__FUNCTION__."]\t ",0);

	$data = false;
	if(!Alarm_WaitForLCD($soc,"Exit Delay in Progress|System Armed in Away Mode|Armed With No Entry Delay",5,"Invalid Access Code")) {
		error_log(basename(__FILE__)."[".__LINE__."/".__FUNCTION__."]\t Alarm is not armed",0);
	} else {
		foreach( $Alarm_keys as $key) {
			if($dbg)error_log(basename(__FILE__)."[".__LINE__."/".__FUNCTION__."]\t ".$key,0);
			AlarmSend($soc,$key,BlankIsOK);
		}
		
		if(!Alarm_WaitForLCD($soc,"System is Ready to Arm|System Disarmed No Alarm Memory",10)) {
			error_log(basename(__FILE__)."[".__LINE__."/".__FUNCTION__."]\t failed to turn off alarm",0);
		} else {
			$data = true;
		}
	}
	return $data;
}
//
// Arming Without Entry Delay
//
function Alarm_NoEntryDelay($soc) {
	global $dbg;
	error_log(basename(__FILE__)."[".__LINE__."/".__FUNCTION__."]\t ",0);
	
	$data = false;
	if(!Alarm_WaitForLCD($soc,"System is Ready to Arm",5)) {
		error_log(basename(__FILE__)."[".__LINE__."/".__FUNCTION__."]\t Alarm not ready for NoEntryDelay",0);  // must be ready to arm to do this
	} else {
		$keys = array(
			"{\"action\":\"send_cmd\",\"input\":{\"cmd\":\"*\"}}",
			"{\"action\":\"send_cmd\",\"input\":{\"cmd\":\"9\"}}");

		foreach( $keys as $key) {
			if($dbg)error_log(basename(__FILE__)."[".__LINE__."/".__FUNCTION__."]\t ".$key,0);
			AlarmSend($soc,$key,BlankIsOK);
		}

		//if(Alarm_WaitForLCD($soc,"Enter Your Access Code|Press (*) for Zone Bypass",5)) {  //wait long enough here....
		if(Alarm_WaitForLCD($soc,"Enter Your Access Code",5)) {  //wait long enough here....
			if($dbg)error_log(basename(__FILE__)."[".__LINE__."/".__FUNCTION__."]\t system successfully set NoEntryDelay",0);
			$data = true;
		} else {
			error_log(basename(__FILE__)."[".__LINE__."/".__FUNCTION__."]t system failed to set NoEntryDelay",0);
		}
	}
	return $data;
}

//
// Simply send a # to clear any outstanding status
//
function Alarm_Reset($soc) {
	global $dbg;
	if($dbg) error_log(basename(__FILE__)."[".__LINE__."/".__FUNCTION__."]\t Alarm_Reset",0);
	
	$keys = array("{\"action\":\"send_cmd\",\"input\":{\"cmd\":\"#\"}}");

	foreach( $keys as $key) {
		if($dbg)error_log(basename(__FILE__)."[".__LINE__."/".__FUNCTION__."]\t ".$key,0);
		AlarmSend($soc,$key,BlankIsOK);
	}

	return;
}

//
// arm the alarm in "Away" mode, returns the status of the alarm
// which should show "Exit Delay in Progress" if everything
// worked as it should.
//
function Alarm_ArmAway($soc) {
	global $dbg;
	if($dbg) error_log(basename(__FILE__)."[".__LINE__."/".__FUNCTION__."]\t Arm in Away mode",0);
	
	$data = false;
	if(!Alarm_WaitForLCD($soc,"System is Ready to Arm",5)) {
		if($dbg)error_log(basename(__FILE__)."[".__LINE__."/".__FUNCTION__."]\t Alarm not ready to arm",0);
	} else {
		$key = "{\"action\":\"send_cmd\",\"input\":{\"cmd\":\"a\"}}";
		if($dbg)error_log(basename(__FILE__)."[".__LINE__."/".__FUNCTION__."]\t ".$key,0);
		AlarmSend($soc,$key,BlankIsOK); 
		
		if(Alarm_WaitForLCD($soc,"Exit Delay in Progress|System Armed in Away Mode|Armed With No Entry Delay",5)) {
			if($dbg)error_log(basename(__FILE__)."[".__LINE__."/".__FUNCTION__."]\t system successfully armed",0);
			$data = true;
		} else {
			error_log(basename(__FILE__)."[".__LINE__."/".__FUNCTION__."]\t system failed to arm",0);
		}
	}
	return $data;
}

//
// arm the alarm in Stay mode.  
//
function Alarm_ArmStay($soc) {
	global $dbg;
	if($dbg) error_log(basename(__FILE__)."[".__LINE__."/".__FUNCTION__."]\t Arm in Stay mode",0);
	
	$data = false;
	if(!Alarm_WaitForLCD($soc,"System is Ready to Arm",5)) {
		if($dbg)error_log(basename(__FILE__)."[".__LINE__."/".__FUNCTION__."]\t Alarm not ready to arm",0);
	} else {
	
		$key = "{\"action\":\"send_cmd\",\"input\":{\"cmd\":\"s\"}}";
		if($dbg)error_log(basename(__FILE__)."[".__LINE__."/".__FUNCTION__."]\t ".$key,0);
		AlarmSend($soc,$key,BlankIsOK);
		
		if(Alarm_WaitForLCD($soc,"Exit Delay in Progress|System Armed in Away Mode|Armed With No Entry Delay",5)) {
			if($dbg)error_log(basename(__FILE__)."[".__LINE__."/".__FUNCTION__."]\t system successfully armed",0);
			$data = true;
		} else {
			error_log(basename(__FILE__)."[".__LINE__."/".__FUNCTION__."]\t system did not arm",0);
		} 
	}
	return $data;
}

//
// Wait for an LCD status to show up.  given string with | delimiter and number of attempts
//
function Alarm_WaitForLCD($soc,$str,$cnt,$FailStr = null) {
	global $dbg;
	if($dbg) error_log(basename(__FILE__)."[".__LINE__."/".__FUNCTION__."]",0);
	$found = false;
	$failed = false;
	$ii = 0;
	do {
		$ii = $ii + 1;
		if($dbg) error_log(basename(__FILE__)."[".__LINE__."/".__FUNCTION__."]\t try ".$ii,0);
		$data = AlarmLCD($soc);			// try to get the current LCD display
		$found = strpos($str,$data);	// see if the current LCD string is in the returned display
		if(($found !== false) and (!is_null($FailStr))){
			$failed = strpos($FailStr,$data);
			if($dbg)error_log(basename(__FILE__)."[".__LINE__."/".__FUNCTION__."]\t is ~".$data."~ in ~".$FailStr."~  ".StateValName('BooleanYes',$failed));
		}
		if($found === false){
			if($dbg)error_log(basename(__FILE__)."[".__LINE__."/".__FUNCTION__."]\t is ~".$data."~ in ~".$str."~  NO");
			//usleep(AlarmKeyDelay);
		} else {
			if($dbg)error_log(basename(__FILE__)."[".__LINE__."/".__FUNCTION__."]\t is ~".$data."~ in ~".$str."~  YES");
		}
	} while ( ( $found === false ) and ( $ii < $cnt ) and ($failed !== true));
	if( $found !== false ) $found = true;  // note $found !== false is actually a number, have to insert True if it's not false
	return $found;
}

// 
// returns the LCD screen as a string (L1 +sp + L2)
//
Function AlarmLCD($soc) {
	global $dbg;
	if($dbg) error_log(basename(__FILE__)."[".__LINE__."/".__FUNCTION__."]\t isConnected:".$soc->isConnected() ,0);
	
	$response = AlarmStatus($soc); 
	//error_log(basename(__FILE__)."[".__LINE__."/".__FUNCTION__."]\t ".var_export($response,true),0);
	$data = json_decode($response->{"data"});
	//error_log(basename(__FILE__)."[".__LINE__."/".__FUNCTION__."]\t ".var_export($data,true),0);
	if($dbg) error_log(basename(__FILE__)."[".__LINE__."/".__FUNCTION__."]\t Alarm LCD response L1:".$data->{"LCD_L1"}." L2:".$data->{"LCD_L2"}." AL:".$data->{"LCD_AL"}." BL:".$data->{"LCD_BL"}." RL:".$data->{"LCD_RL"},0);
	$RetVal = $data->{"LCD_L1"}." ".$data->{"LCD_L2"};
	$RetVal = str_replace("<>","",$RetVal);	// get rid of the crazy <> that shows up
	$RetVal = str_replace("  "," ",$RetVal); // get rid of redundant spaces
	$RetVal = trim($RetVal);
	if($dbg) error_log(basename(__FILE__)."[".__LINE__."/".__FUNCTION__."]\t return: ".$RetVal,0);
	return $RetVal;
}

//
// fetch the alarm status.  comes back as associative array
// {"data":"{\"LCD_AL\":\"0\",\"LCD_BL\":\"0\",\"LCD_L1\":\"System is\",\"LCD_L2\":\"Ready to Arm\",\"LCD_RL\":\"0\"}","status":"OK"}
// LCD_AL = armed/not armed, LCD_RL = ready not/ready, LCD_BL = BYPASS?
// System is Ready to Arm
// Exit Delay in Progress
// System Armed in Away Mode <= this after the long countdown, both s and a produce this
// System Disarmed No Alarm Memory
// *9 yields Press (*) for <> Zone Bypass  then enter code
//
function AlarmStatus( $soc ){
	global $dbg;
	if($dbg) error_log(basename(__FILE__)."[".__LINE__."/".__FUNCTION__."]",0);
	$data = array(
	  'action'	=> 'send_cmd',
	  'input'   => array('cmd' => 'dstatus') );
	$jd = json_encode( $data );
	return AlarmSend($soc,$jd,BlankNotOK);
}

// send a json encoded command and receive the data back
// may retry on blank will retry on empty string
function AlarmSend($soc,$data,$BlankOK) {
	global $dbg;
	if($dbg) error_log(basename(__FILE__)."[".__LINE__."/".__FUNCTION__."]\t sending ".$data,0);

	$success = false;
	$ii = 0;
	$RetVal = "";
	
	do {
		$ii = $ii + 1;
		try {
			usleep(AlarmKeyDelay);  // a little delay before
			$soc->send($data);
			usleep(AlarmKeyDelay);	// and a little delay after
		} catch (Exception $e) {
			error_log(basename(__FILE__)."[".__LINE__."/".__FUNCTION__."]\t failed sending:".$data."\nerror:". $e->getMessage(),0);
		}
		try {
			$response = json_decode($soc->receive()); 
			if(is_object($response)) {
				if($response->{"data"} != "") {
					$success = true;
					$RetVal = $response;
				} else {
					if($BlankOK){
						$success = true;
					} else {
						if($dbg)error_log(basename(__FILE__)."[".__LINE__."/".__FUNCTION__."]\t received blank response, try again ".$ii." ".var_export($response,true),0);
					}
				}
			} else {
				error_log(basename(__FILE__)."[".__LINE__."/".__FUNCTION__."]\t received no object, try again ".$ii,0);
				//if($BlankOK)$success = true;  Ii think we've got to have an object
			}
		} catch (Exception $e) {
			error_log(basename(__FILE__)."[".__LINE__."/".__FUNCTION__."]\t error receiving, killing the connection\n". $e->getMessage(),0);
			//$soc->close();
			$ii = 99;	// no more tries, connection closed
		}
		if(!$success) usleep(AlarmKeyDelay);
	} while ((!$success) and ($ii < 5) );
	if($dbg)error_log(basename(__FILE__)."[".__LINE__."/".__FUNCTION__."]\t RetVal=".var_export($RetVal,true),0);
	return $RetVal;
}

function AlarmWsPing($soc) {
	global $dbg;
	if($dbg) error_log(basename(__FILE__)."[".__LINE__."/".__FUNCTION__."]\t",0);

	$success = false;
	$ii = 0;
	$RetVal = "";
	
	do {
		$ii = $ii + 1;
		try {
			usleep(AlarmKeyDelay);
			$soc->send('Hello','ping');
			usleep(AlarmKeyDelay);
		} catch (Exception $e) {
			error_log(basename(__FILE__)."[".__LINE__."/".__FUNCTION__."]\t Failed to WS Ping:". $e->getMessage(),0);
		}
		try {
			$success = true;
			$RetVal = $soc->receive();
		} catch (Exception $e) {
			error_log(basename(__FILE__)."[".__LINE__."/".__FUNCTION__."]\t error receiving ". $e->getMessage(),0);
		}
		//if(!$success) usleep(AlarmKeyDelay);
	} while ((!$success) and ($ii < 5) );
	//error_log(basename(__FILE__)."[".__LINE__."/".__FUNCTION__."]\t".var_export($RetVal,true),0);
	return $RetVal;
}

?>
