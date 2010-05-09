<?php

$FORSMSPAY_SERVER = "smspay.fortumo.com";

/*
 * We do not use "http_get" as the function name here, because http_get
 * has already been defined in the standard PHP 5 extension "http"
 */
function http_get4($url) {
	$s = "";
	if( ($fp = @fopen($url, 'r')) !== false ) {
		while (!feof($fp)) { $s .= fgets($fp); }
	}
	return $s;
}

class ForSmspay {
	
	/*
	* Parameters:
	* $id - your service_id
	* $secret - your service secret
	* $do_sessions - if true, authentication status will be kept in the session after successful authentication
	*/
	function ForSmspay($id, $secret, $do_sessions = true) {
		$this->id = $id;
		$this->secret = $secret;
		$this->sessions = $do_sessions;
		$this->error = "";
	}

	function payment_verified() {
		if($this->sessions) {
			return (isset($_SESSION["payment_ok"]) || $this->authenticate($_POST['code']));
		} else {
			return $this->authenticate($_POST['code']);
		}
	}

	/*
	* This function performs an HTTP GET request to http://smspay.fortumo.com/tokens/show/
	* in order to authenticate a password submitted by an end-user.
	* 
	* Mandatory request parameters:
	* service_id - an unique string identifying your service
	*	(see the general configuration page of your service in Fortumo)
	* code - password submitted by the end-user from your widget
	* sig - request signature, generated as following:
	*	1) concatenate request params as "param=value" strings in alphabetical order into one string
	*	2) append your service secret to the end of this string
	*	3) apply md5() to the result
	*
	* The result is an XML with status code and message
	* Code "0" indicates successful authentication.
	* Otherwise, authentication failed and the widget should be displayed to the end-user
	*
	*/
	function authenticate($code) {
		global $FORSMSPAY_SERVER;
		if( strlen($code) == 0 ) {
			return false;
		} else {
			$sig = md5("code=".$code."service_id=".$this->id.$this->secret);
			$response = http_get4(
				"http://".$FORSMSPAY_SERVER."/tokens/show/?".
				"service_id=".$this->id.
				"&code=".urlencode($code).
				"&sig=".$sig
			);

			// if we are using php5, use simplexml to parse the xml
			if (version_compare(PHP_VERSION,'5','>=')) {
				$doc = simplexml_load_string($response);
				$status = $doc->status_code;
				$message = $doc->message;
			} else { // use domxml for parsing the xml
				$doc = domxml_open_mem($response);
			}

			/* if xml parsing does not work for you for whatever reason, 
			 * then comment it out and use the following regexp instead. */

			/* $matches = Array();
			preg_match("/<status_code>(\d+)\<.+<message>(.+)<\/message/", str_replace("\n", '', $response), $matches);
			$status = $matches[1];
			$message = $matches[2]; */
			if($status == "0") {
				if($this->sessions) {
					$_SESSION["payment_ok"] = true;
				}
				return true;
			} else {
				$this->error = $status;
				return false;
			}
		}
	}

	/*
	 * Parameters which you can use to control the content of the widget:
	 *
	 * submit_url - the "SUBMIT" button on the widget needs to point to your password-processing script
	 * status_code - when password validation failed, 
	 *	use the status_code to reload the widget with a proper error message
	 * 
	 * Description of error codes:
	 * 1 - password processing error
	 * 2 - end-user submitted a wrong password
	 * 3 - password has expired
	 * 6 - end-user reply SMS hasn't been billed yet (billing is pending)
	 * 7 - end-user reply SMS billing failed
	 *
	 */
	function widget() {
		global $FORSMSPAY_SERVER;
		$widget_params = "?submit_url=".urlencode($_SERVER['REQUEST_URI']);
		if ($this->error != null) {
			$widget_params = $widget_params."&status_code=".$this->error;
		}

		return http_get4("http://".$FORSMSPAY_SERVER."/widgets/show/".$this->id.$widget_params);
	}

	function restricted_content() {
		return <<<END
<div id="smspay-widget">
  <div id="smspay-header" class="smspay-top1">
    <div class="smspay-top3">
      <div class="smspay-top2"><h3 class="smspay-title">Värske väljavõte</h3></div>
    </div>
  </div>

  <div id="smspay-main">
    <p><a href="wLex.zip" style="font-weight:bold; color:#f00">Väljavõtte allalaadimine</a></p>
  </div>

  <div id="smspay-footer">
    <div class="smspay-bottom1">
      <div class="smspay-bottom3">
        <div class="smspay-bottom2"></div>
      </div>
    </div>
  </div>
</div>
END;
	}
};
