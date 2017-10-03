# php2ipdatatel
php script to communicate with ipdatatel alarm interface via alarmdealer

Based on the java websocket calls found in the public alarmdealer web interface and simply attempting to duplicate similar web socket calls with php.  You have to have a valid alarmdealer account as part of the interface is logging into the alarmdealer interface.

Requires php WebSockets: https://github.com/Textalk/websocket-php

My intent was to build a utility which I could interface with IFTTT since alarmdealer doesn't have any native integration with IFTTT.  To interface with IFTTT, you need a local web server to host this php code which can then recieve IFTTT requests and then use this code to talk to the alarm interface.  Username and password is currently hard-coded in the php script, so recommend to not put this on a public web server!

*************************************************************************************
None of this is supported / documented by either myself or ipdatatel or alarmdealer
it appears to generally work, may have unintended side effects.  
!! Use at your own risk !!
*************************************************************************************
