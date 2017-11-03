# php2ipdatatel
php script to communicate with ipdatatel alarm interface via alarmdealer

Based on the java websocket calls found in the public alarmdealer web interface and simply attempting to duplicate similar web socket calls with php.  You have to have a valid alarmdealer account as part of the interface is logging into the alarmdealer interface.

Requires php WebSockets: https://github.com/Textalk/websocket-php

My intent was to build a utility which I could interface with IFTTT since alarmdealer doesn't have any native integration with IFTTT.  To interface with IFTTT, you need a local web server to host this php code which can then recieve IFTTT requests and then use this code to talk to the alarm interface.  

Username and password and the PIN for the target alarm system is currently hard-coded in the php script, so recommend to NOT put this on a public web server!  You do not want this information in the public domain.

Note that timing appears to be a problem.  The WebSocket timing out to alarmdealer seems OK, but then alarmdealer has to loop back to your alarm.  Looks like the websocket handshaking is OK, but if you go too fast the alarmdealer side hasn't gotten an update from the alarm yet.  So had to code in a bunch of timing and cross-checking to see if what the WebSocket returns actually makes sense.  Lots of cases of JSON return = {"Data":"","Status":"OK"} which doesn't make much sense.  So code tries to resubmit the command if the result is non-sense.   However retransimitting the PIN codes generally results in invalid PIN entered.  So retransmitts really only work for status commands and not for PIN key commands.

*************************************************************************************
None of this is supported / documented by either myself or ipdatatel or alarmdealer
it appears to generally work, may have unintended side effects.  
!! Use at your own risk !!
*************************************************************************************

Typical usage might look like this:

	$client = Alarm_Login();
	if($client->isConnected() == true) {
		if(Alarm_DisableZone($client)){
			if(Alarm_Arm($client)) {
					IFTTT_say("Alarm is armed");
			} else {
				error_log(basename(__FILE__)."[".__LINE__."]\t failed to arm alarm");
			}
		} else {
			error_log(basename(__FILE__)."[".__LINE__."]\t failed disable zones");
		}
	} else {
		error_log(basename(__FILE__)."[".__LINE__."]\t failed to connect to alarm");
	}
