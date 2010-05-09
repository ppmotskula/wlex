<?php

/* library with necessary functions: */
include('for_smspay.php');

if ($_testing = FALSE) {
    $my_pay = new ForSmspay(
        '28a66bd8d7280b75a4ea0b2201e77d17', // your SMS-pay service id
        'c48fb1dcd54440f37be2c6f07036c123' // your SMS-pay service secret
    );
} else {
    $my_pay = new ForSmspay(
	'5c75b58c74fe85d28404bb640c83855b', // your SMS-pay service id
	'0d0e36b5ec967949a57138dead7a4307' // your SMS-pay service secret
    );
}

/*
* NB! Response headers are set by session_start() function and thus,
* it must be executed before generating the response body.
* 
* If your application already calls session_start() before executing this script,
* it will be recommended to remove the function call:
*/
if ($my_pay->sessions) {
	session_start();
}

/* The content can be displayed by another script which generates HTML output: */
global $smspay_resp;

if ($my_pay->payment_verified()) {
	/* User entered a valid password, authentication successful and the content can be displayed to the user */
	$smspay_resp = $my_pay->restricted_content();

  /* Or you can redirect the user to a separate script which verifies that $_SESSION["payment_ok"] is set: */
  /* 
   * header('HTTP/1.0 302 Moved temporarily');
   * header('Location: content.php');
   */
} else {
	/*
	 * Invalid password or an error occurred;
	 * display the login widget with an error message
	 */
	$smspay_resp = $my_pay->widget();
}
