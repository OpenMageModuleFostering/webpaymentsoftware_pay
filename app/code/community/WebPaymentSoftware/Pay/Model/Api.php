<?php
/**
 * Magento
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Open Software License (OSL 3.0)
 * that is bundled with this package in the file LICENSE.txt.
 * It is also available through the world-wide-web at this URL:
 * http://opensource.org/licenses/osl-3.0.php
 * If you did not receive a copy of the license and are unable to
 * obtain it through the world-wide-web, please send an email
 * to license@magentocommerce.com so we can send you a copy immediately.
 *
 * @category   Payment
 * @package    WebPaymentSoftware_Pay
 * @copyright  Copyright (c) 2013 Web Payment Software (http://www.web-payment-software.com/)
 * @license    http://opensource.org/licenses/osl-3.0.php  Open Software License (OSL 3.0)
 */

class WebPaymentSoftware_Pay_Model_Api {

	// Config
	//var	$host = "secure.web-payment-software.com";
	var	$port = 443;
	var	$path = "/gateway/";
	var $payment_method = 'card';

	// Request fields
	var $merchant_id;
	var $merchant_key;
	var $trans_type;
	var $order_id;
	var $amount;
	var $tax;
	var $shipping;

	var $cc_number;
	var $cc_exp;
	var $cc_cvv;

	var $check_routing_num;
	var $check_account_num;
	var $check_account_type = 'C';
	var $check_num;
	var $check_ssn;
	var $check_dl_num;
	var	$check_dl_state;

	var $company;
	var $name;
	var $address;
	var $city;
	var $state;
	var $zip;
	var $country;
	var $phone;
	var $email;

	var $ip_address;
	var $invoice_num;
	var $memo;
	var $test_mode = 0;

	// Response fields
	var $response;
	var $response_code;
	var $approval_code;
	var $auth_response_text;
	var $avs_result_code;
	var $cvv_result_code;
	var $response_text;
	
	// Check-only responses
	var $trace_num;
	var $preauth_result;
	var $preauth_text;

	// Socket error responses
	var $error_num;
	var $error_string;
	
	
	//////////
	//// INTERFACE METHOD TO THE PAYGATE GATEWAY
	//// Do some basic error handling and post to PayGate
	function transact() {
	
		$required = array(	'merchant_id',
							'merchant_key',
							'trans_type',
							'ip_address'
						 );	


		if (!isset($this->ip_address)) $this->ip_address = $_SERVER['REMOTE_ADDR'];
		//if (!isset($this->referer)) $this->referer = $_SERVER[''];

		if ($this->payment_method != 'card' && $this->payment_method != 'check') die ("\$payment_method must be 'card' or 'check'!");
		
		$this->trans_type = strtolower($this->trans_type);
		if ($this->trans_type == 'unmark') $this->trans_type == 'void';
		if ($this->trans_type == 'mark') $this->trans_type == 'postauth';
		
		// Set required fields array
		if (($this->trans_type == 'authonly') || ($this->trans_type == 'authcapture')) {
			array_push(	$required,
						'amount',
						'name',
						'address',
						'city',
						'state',
						'zip',
						'country'
						);
	
			if ($this->payment_method == 'card') {
				array_push($required,'cc_number','cc_exp');
			} elseif ($this->payment_method == 'check') {
				array_push($required,'check_routing_num','check_account_num');
			}

		} elseif (($this->trans_type == 'void') || ($this->trans_type == 'postauth')) {
			array_push(	$required,'order_id');

		} elseif (($this->trans_type == 'credit') || ($this->trans_type == 'force')) {
			array_push(	$required,
						'amount',
						'name'
						);
			if ($this->payment_method == 'card') {
				array_push($required,'cc_number','cc_exp');
			
				if ($this->trans_type == 'force') {
					array_push($required,'approval_code');
				}

			} elseif ($this->payment_method == 'check') {
				array_push($required,'check_routing_num','check_account_num');
			
			}

		}
		
		// first do a quick check to make sure required fields
		// are set
		foreach ($required as $field) {
			if (!isset($this->$field)) die ("$field must be set!");
		}

		return $this->_post2PayGate();

	}

	
	// PRIVATE METHOD FOR POSTING TO PAYGATE
	function _post2PayGate() {

		// Array of fields that may be posted
		$fields = array(
			'merchant_id',
			'merchant_key',
			'trans_type',
			'order_id',
			'amount',
			'tax',
			'shipping',
			
			'cc_number',
			'cc_exp',
			'cc_cvv',
			
			'check_routing_num',
			'check_account_num',
			'check_account_type',
			'check_num',
			'check_ssn',
			'check_dl_num',
			'check_dl_state',
			
			'company',
			'name',
			'address',
			'city',
			'state',
			'zip',
			'country',
			'phone',
			'email',
			
			'ip_address',
			'invoice_num',
			'memo',
			'test_mode',
			);

		// Fields whose names must be changed to cc_... or check_...
		$prepend_fields = array('company','name','address','city','state','zip','country','phone','email');


		// build request
		$post = ''; 
		foreach($fields as $field) { 
			if (isset($this->$field)) {
				if ($post!='') $post.="&"; 
				
				if (in_array($field,$prepend_fields)) {
					$field_name = ($this->payment_method=='card'?'cc_':'check_').$field;
				} else {
					$field_name = $field;
				}
				
				$post .= urlencode($field_name)."=".urlencode($this->$field);
				//$post.="&";
			}
		}

		// Build header and body string
		$req =	"POST ".$this->path.$this->payment_method." HTTP/1.0\r\n" .
				"Host: ".$this->host."\r\n" .
				"Content-Type: application/x-www-form-urlencoded\r\n" .
				"Content-Length: ".strlen($post)."\r\n" .
				"User-Agent: PayGate-Zilla/1.0\r\n" .
				"Connection: Close\r\n" .
				"\r\n" .
				$post . 
				"\r\n\r\n";

//		if ($this->debug) echo "<pre>\n";
//		if ($this->debug) echo $req;


		$timeout = 5;		
		$attempt_count = 5;
		$sleep_between = 2;
		
		for ($i=0;$i<$attempt_count;$i++) {
			$socket = fsockopen("ssl://".$this->host, $this->port, $errno, $errstr, $timeout);
			if ($socket) break;			
			
			// If we haven't exhausted connexion attempts, sleep then try again.
			if ($i < $attempt_count-1) {
				sleep($sleep_between);
				continue;
			} else {
				$this->error_string = $errstr;
				$this->error_num = $errno;
				$this->response_code = 501;
				$this->response_text = 'Error connecting to financial network.';
				return;
			}

		}
	
		// Send the server request
		$header = ''; //marcel
		fwrite($socket, $req, strlen($req));
		do $header.=fgets($socket); while (!preg_match('/\\r\\n\\r\\n$/',$header));

		// create regex for parsing xml response
		$match = "/^<([a-z_]+)>([ \(\)&\+,A-Za-z0-9\.-]+)<\/[a-z_]+>/";
	
		// Parse response
		while (!feof($socket)) {
			$buffer = @fgets($socket);
			$this->response .= $buffer;
			
			// parse xml
			if(preg_match($match,$buffer,$matches))
				$this->$matches[1] = $matches[2];
		}
		
		// Close socket
		fclose ($socket);
		
		if ($this->debug) {
			$responses = array(	'response_code','approval_code','auth_response_text',
								'avs_result_code','cvv_result_code','response_text',
								'trace_num','preauth_result','preauth_text','order_id');
            $result = array();
            foreach($responses as $row) {
				if (!isset($this->$row)) continue;
				$result[$row] = $this->$row;
			}
            Mage::log($result, null, 'paygate.log');
		}
		return true;
	}

}