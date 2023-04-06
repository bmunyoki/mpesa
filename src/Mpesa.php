<?php

namespace Bmunyoki\Mpesa;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Cache;


class Mpesa {
	public $environment;
	private $baseUrl;
	public $consumerKey;
	public $consumerSecret;
	private $cred;
	private $accessToken;

	/**
	 * Constructor method
	 *
	 * Initializes the class with an array of API values.
	 *
	 * @param array $config
	 * @return void
	 * @throws exception if the values array is not valid
	 */
	public function __construct() {
		$environment = config('mpesa.mpesa_env');
		$consumerKey = config('mpesa.consumer_key');
		$consumerSecret = config('mpesa.consumer_secret');

		$this->environment = $environment;

		// Set the base URL for API calls based on the application environment
		if ($environment == 'sandbox') {
       		$this->baseUrl = 'https://sandbox.safaricom.co.ke/mpesa/';
     	} else {
       		$this->baseUrl = 'https://api.safaricom.co.ke/mpesa/';
     	}

		$this->consumerKey = $consumerKey;
		$this->consumerSecret = $consumerSecret;
     	
     	// Set the access token
		$this->accessToken = $this->getAccessToken();
	}

	/**
	 * Submit Request - Handles submission of all API endpoints queries
	 * @return object|boolean Curl response or FALSE on failure
	 * @throws exception if the Access Token is not valid
	 */
	public function setCred($initiatorPassword) {
		// Set public key certificate based on environment
		if($this->environment == 'sandbox') {
			$pubkey = File::get(__DIR__.'/cert/sandbox.cer');
		} else {
			$pubkey = File::get(__DIR__.'/cert/production.cer');
		}
		
		openssl_public_encrypt($initiatorPassword, $output, $pubkey, OPENSSL_PKCS1_PADDING);
        $this->cred = base64_encode($output);

        return $this->cred;
	}


	public function getAccessToken() {
        $credentials = base64_encode($this->consumerKey.':'.$this->consumerSecret);

        $ch = curl_init();
        $url = 'https://api.safaricom.co.ke/oauth/v1/generate?grant_type=client_credentials';
        if($this->environment == 'sandbox') {
            $url = 'https://sandbox.safaricom.co.ke/oauth/v1/generate?grant_type=client_credentials';
        }

        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array('Authorization: Basic '.$credentials, 'Content-Type: application/json'));
        $response = curl_exec($ch);
        curl_close($ch);
        $response = json_decode($response);
        //\Log::info(json_encode($response, true));

    	if($response == null) {
            return false;
        }

        $accessToken = @$response->access_token;
        $this->accessToken = $accessToken;

        return $accessToken;
    }

    /**
	 * Submit request - submits a curl request to Mpesa API
	 * @return object $response, false on failure
	 */
	private function submitRequest($url, $data) { 
		$accessToken = $this->getAccessToken();
		if($accessToken == '' || $accessToken == FALSE) {
			return false;
		}

		$curl = curl_init();
		curl_setopt($curl, CURLOPT_URL, $url);
		curl_setopt($curl, CURLOPT_HTTPHEADER, array('Content-Type: application/json','Authorization: Bearer '.$accessToken));

		curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($curl, CURLOPT_POST, true);
		curl_setopt($curl, CURLOPT_POSTFIELDS, $data);

		$response = curl_exec($curl);
		curl_close($curl);

		return $response;
	}

	/**
	 * Business to Client (B2C) - used to send money to the customer Mpesa account. 
	 * @return object Curl Response from submitRequest method, FALSE on failure
	 */
	public function b2c($paybillOrTill, $initiatorUsername, $initiatorPassword, $amount, $phone, $commandId, $remarks, $b2cTimeoutUrl, $b2cResultUrl) {
		$cred = $this->setCred($initiatorPassword);

		$requestData = array(
			'InitiatorName' => $initiatorUsername,
			'SecurityCredential' => $cred,
			'CommandID' => $commandId,
			'Amount' => $amount,
			'PartyA' => $paybillOrTill,
			'PartyB' => $phone,
			'Remarks' => $remarks,
			'QueueTimeOutURL' => $b2cTimeoutUrl,
			'ResultURL' => $b2cResultUrl,
			'Occasion' => '' //Optional
		);

		$data = json_encode($requestData);
		$url = $this->baseUrl.'b2c/v1/paymentrequest';

		return $this->submitRequest($url, $data);
	}

	/**
	 * Business to Business (B2B) - used to send money to other business Mpesa till or paybill.
	 * @return object Curl Response from submitRequest, FALSE on failure
	 */
	public function b2b($initiatorUsername, $initiatorPassword, $amount, $paybillOrTill, $shortcode, $reference, $b2bTimeoutUrl, $b2bResultUrl) {
		$cred = $this->setCred($initiatorPassword);

		$requestData = array(
			'Initiator' => $initiatorUsername,
			'SecurityCredential' => $cred,
			'CommandID' => 'BusinessToBusinessTransfer',
			'SenderIdentifierType' => 'Shortcode',
			'RecieverIdentifierType' => 'Shortcode',
			'Amount' => $amount,
			'PartyA' => $paybillOrTill,
			'PartyB' => $shortcode,
			'AccountReference' => $reference,
			'Remarks' => 'This is a test comment or remark',
			'QueueTimeOutURL' => $b2bTimeoutUrl,
			'ResultURL' => $b2bResultUrl,
		);

		$data = json_encode($requestData);
		$url = $this->baseUrl.'b2b/v1/paymentrequest';

		return $this->submitRequest($url, $data);
	}

	/**
	 * C2B register urls - used to register URLs for callbacks when money is sent from the MPesa toolkit menu
	 * @return object Curl Response from submitRequest, FALSE on failure
	 */
	public function c2bRegisterUrls($paybillOrTill, $c2bConfirmationUrl, $c2bValidationUrl) {
		$requestData = array(
			'ShortCode' => $paybillOrTill,
			'ResponseType' => 'Completed',
			'ConfirmationURL' => $c2bConfirmationUrl,
			'ValidationURL' => $c2bValidationUrl
		);

		$data = json_encode($requestData);
		$url = $this->baseUrl.'c2b/v2/registerurl';

		$response = $this->submitRequest($url, $data);

		return $response;
	}

	/**
	 * Pull API to Business - used to register URLs for Pull API callbacks
	 *
	 * @return object Curl Response from submitRequest, FALSE on failure
	 */
	public function pullRegisterUrl($nominatedNumber, $storeNumber, $pullCallbackUrl) {
		$requestData = array(
			'ShortCode' => $storeNumber, //Replace this with store number 7085771
			'RequestType' => 'Pull',
			'NominatedNumber' => $nominatedNumber,
			'CallBackURL' => $pullCallbackUrl
		);
		$data = json_encode($requestData);

		$url = 'https://api.safaricom.co.ke/pulltransactions/v1/register';

		return $this->submitRequest($url, $data);
	}

	/**
	 * PULL API - used to pull transactions for a given short code within given timestamps
	 * @return object Curl Response from submitRequest, FALSE on failure
	 */
	public function pullAPI($storeNumber, $start, $end) {
		$data = array(
			'ShortCode' => $storeNumber,
			'StartDate' => $start,
			'EndDate' => $end,
			'OffSetValue' => "0"
		);

		$data = json_encode($data);
		$url = 'https://api.safaricom.co.ke/pulltransactions/v1/query';

		return $this->submitRequest($url, $data);
	}


	/**
	 * C2B Simulation - used to simulate a C2B Transaction to test your ConfirmURL and ValidationURL in the Client to Business method
	 * @return object Curl Response from submitRequest, FALSE on failure
	 */
	public function simulateC2B($paybillOrTill, $amount, $msisdn, $ref) {
		$data = array(
			'ShortCode' => $paybillOrTill,
			'CommandID' => 'CustomerPayBillOnline',
			'Amount' => $amount,
			'Msisdn' => $msisdn,
			'BillRefNumber' => $ref
		);

		$data = json_encode($data);
		$url = $this->baseUrl.'c2b/v2/simulate';

		return $this->submitRequest($url, $data);
	}

	/**
	 * Check Balance
	 * @return object Curl Response from submitRequest, FALSE on failure
	 */
	public function checkBalance($paybillOrTill, $initiatorUsername, $initiatorPassword, $balanceTimeoutUrl, $balanceResultUrl) {
		$cred = $this->setCred($initiatorPassword);

		$data = array(
			'CommandID' => 'AccountBalance',
			'PartyA' => $paybillOrTill,
			'IdentifierType' => '4',
			'Remarks' => 'Remarks or short description',
			'Initiator' => $initiatorUsername,
			'SecurityCredential' => $cred,
			'QueueTimeOutURL' => $balanceTimeoutUrl,
			'ResultURL' => $balanceResultUrl
		);

		$data = json_encode($data);
		$url = $this->baseUrl.'accountbalance/v1/query';

		return $this->submitRequest($url, $data);
	}

	/**
	 * Transaction status request - used to check a transaction status
	 * @return object Curl Response from submitRequest, FALSE on failure
	 */
	public function statusRequest($paybillOrTill, $initiatorUsername, $initiatorPassword, $statusTimeoutUrl, $statusResultUrl, $transaction = 'LH7819VXPE') {
		$cred = $this->setCred($initiatorPassword);

		$data = array(
			'CommandID' => 'TransactionStatusQuery',
			'PartyA' => $paybillOrTill,
			'IdentifierType' => 4,
			'Remarks' => 'Testing API',
			'Initiator' => $initiatorUsername,
			'SecurityCredential' => $cred,
			'QueueTimeOutURL' => $statusTimeoutUrl,
			'ResultURL' => $statusResultUrl,
			'TransactionID' => $transaction,
			'Occassion' => 'Test'
		);

		$data = json_encode($data);
		$url = $this->baseUrl.'transactionstatus/v1/query';

		return $this->submitRequest($url, $data);
	}

	/**
	 * Transaction Reversal - used to reverse a transaction
	 * 
	 * @return object Curl Response from submitRequest, FALSE on failure
	 */
	public function reverseTransaction($initiatorUsername, $initiatorPassword, $resersalTimeoutUrl, $resersalResultUrl, $receiver, $transactionId, $amount, $testMsisdn) {
		$cred = $this->setCred($initiatorPassword);

		$data = array(
			'CommandID' => 'TransactionReversal',
			'ReceiverParty' => $testMsisdn,
			'RecieverIdentifierType' => 1, //1=MSISDN, 2=Till_Number, 4=Shortcode
			'Remarks' => 'Testing',
			'Amount' => $amount,
			'Initiator' => $initiatorUsername,
			'SecurityCredential' => $cred,
			'QueueTimeOutURL' => $resersalTimeoutUrl,
			'ResultURL' => $resersalResultUrl,
			'TransactionID' => $transactionId
		);
		
		$data = json_encode($data);
		$url = $this->baseUrl.'reversal/v1/request';

		return $this->submitRequest($url, $data);
	}

	/*********************************************************************
	 *
	 * 	LNMO APIs - STK
	 *
	 * *******************************************************************/
	public function stkRequest($paybillOrTill, $storeNumber, $passKey, $amount, $phone, $callbackUrl, $transactionType, $ref = "Payment", $desc = "Payment") {
		if(!is_numeric($amount) || $amount < 1 || !is_numeric($phone)) {
			throw new \Exception("Invalid amount and/or phone number. Amount should be 10 or more, phone number should be in the format 254xxxxxxxx");
			return false;
		}

		$timestamp = date('YmdHis');
		$passwd = base64_encode($storeNumber.$passKey.$timestamp);
		
		$data = array(
			'BusinessShortCode' => $storeNumber,
			'Password' => $passwd,
			'Timestamp' => $timestamp,
			'TransactionType' => $transactionType,
			'Amount' => $amount,
			'PartyA' => $phone,
			'PartyB' => $paybillOrTill,
			'PhoneNumber' => $phone,
			'CallBackURL' => $callbackUrl,
			'AccountReference' => $ref,
			'TransactionDesc' => $desc,
		);

		$data = json_encode($data);
		$url = $this->baseUrl.'stkpush/v1/processrequest';
		$response = $this->submitRequest($url, $data);

		return json_decode($response);
	}

	private function stkStatusQuery($paybillOrTill, $passKey, $checkoutRequestID = null) {
		$timestamp = date('YmdHis');
		$passwd = base64_encode($paybillOrTill.$passKey.$timestamp);

		if($checkoutRequestID == null || $checkoutRequestID == '') {
			return false;
		}

		$data = array(
			'BusinessShortCode' => $paybillOrTill,
			'Password' => $passwd,
			'Timestamp' => $timestamp,
			'CheckoutRequestID' => $checkoutRequestID
		);

		$data = json_encode($data);
		$url = $this->baseUrl.'stkpushquery/v2/query';

		return $this->submitRequest($url, $data);
	}

}