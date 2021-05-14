<?php

namespace PaymentExpress;

use Exception;
use Payment\Payment;
use Payment\PaymentGateway_Failure;
use Payment\PaymentGateway_GatewayHosted;
use Payment\PaymentGateway_Incomplete;
use Payment\PaymentGateway_Success;
use SilverStripe\Control\Controller;
use SilverStripe\Core\Config\Config;

class PaymentExpressGateway_PxPay extends PaymentGateway_GatewayHosted
{

	protected $pxPayUrl;
	protected $pxPayUserID;
	protected $pxPayKey;

	protected $supportedCurrencies = array(
		'NZD' => 'New Zealand Dollar',
		'USD' => 'United States Dollar',
		'GBP' => 'Great British Pound'
	);

	public function getSupportedCurrencies()
	{

		$config = $this->getConfig();
		if (isset($config['supported_currencies'])) {
			$this->supportedCurrencies = $config['supported_currencies'];
		}
		return $this->supportedCurrencies;
	}

	protected function onBeforeMakeRequest()
	{
		$config = $this->getConfig();

		$this->pxPayUrl    = Config::inst()->get('PaymentExpressGateway_PxPay', 'url');
		$this->pxPayUserID = $config['authentication']['user_id'];
		$this->pxPayKey    = $config['authentication']['key'];
	}

	public function process($data)
	{

		//Construct the request
		$request = new PxPayRequest();
		$request->setAmountInput($data['Amount']);
		$request->setCurrencyInput($data['Currency']);

		//Set PxPay properties
		if (isset($data['EnableAddBillCard'])) $request->setEnableAddBillCard($data['EnableAddBillCard']);
		if (isset($data['Reference'])) $request->setMerchantReference($data['Reference']);
		if (isset($data['EmailAddress'])) $request->setEmailAddress($data['EmailAddress']);

		//Set TxnData for custom fields
		if (isset($data['TxnData1'])) $request->setTxnData1($data['TxnData1']);
		if (isset($data['TxnData2'])) $request->setTxnData2($data['TxnData2']);
		if (isset($data['TxnData3'])) $request->setTxnData3($data['TxnData3']);

		$request->setUrlFail($this->cancelURL);
		$request->setUrlSuccess($this->returnURL);

		//Generate a unique identifier for the transaction
		$request->setTxnId(uniqid('ID'));
		$request->setTxnType('Auth');

		//Get encrypted URL from DPS to redirect the user to
		$request_string = $this->makeProcessRequest($request, $data);

		//Obtain output XML
		$response = new MifMessage($request_string);

		//Parse output XML
		$url = $response->get_element_text('URI');
		$valid = $response->get_attribute('valid');

		//If this is a fail or incomplete (cannot reach gateway) then mark payment accordingly and redirect to payment 
		if ($valid && is_numeric($valid) && $valid == 1) {
			//Redirect to payment page
			Controller::curr()->redirect($url);
		} else if (is_numeric($valid) && $valid == 0) {
			return new PaymentGateway_Failure();
		} else {
			return new PaymentGateway_Incomplete();
		}
	}

	public function makeProcessRequest($request, $data)
	{

		$this->onBeforeMakeRequest();
		$pxpay = new PxPay_Curl($this->pxPayUrl, $this->pxPayUserID, $this->pxPayKey);
		return $pxpay->makeRequest($request);
	}

	/**
	 * Check that the payment was successful using "Process Response" API (http://www.paymentexpress.com/Technical_Resources/Ecommerce_Hosted/PxPay.aspx).
	 * 
	 * @param SS_HTTPRequest $request Request from the gateway - transaction response
	 * @return PaymentGateway_Result
	 */
	public function check($request)
	{

		$data = $request->getVars();

		$url = $request->getVar('url');
		$result = $request->getVar('result');
		$userID = $request->getVar('userid');
		$paymentID = $request->param('OtherID');

		//Construct the request to check the payment status
		$request = new PxPayLookupRequest();
		$request->setResponse($result);

		//Get encrypted URL from DPS to redirect the user to
		$request_string = $this->makeCheckRequest($request, $data);

		//Obtain output XML
		$response = new MifMessage($request_string);

		//Parse output XML
		$success = $response->get_element_text('Success');
		$DPStnxid = $response->get_element_text('DpsTxnRef');

		// get payment object
		$rp = Payment::get()->byId($paymentID);

		// get billing id and add to member
		$dpsBillingId = $response->get_element_text('DpsBillingId');
		$rp->PaidBy()->WindcaveBillingId = $dpsBillingId;
		$rp->PaidBy()->write();

		// attach ref to payment object
		$rp->DPSReference = $DPStnxid;
		$rp->write();

		if ($success && is_numeric($success) && $success > 0) {
			return new PaymentGateway_Success();
		} else if (is_numeric($success) && $success == 0) {
			return new PaymentGateway_Failure();
		} else {
			return new PaymentGateway_Incomplete();
		}
	}

	public function makeCheckRequest($request, $data)
	{

		$this->onBeforeMakeRequest();
		$pxpay = new PxPay_Curl($this->pxPayUrl, $this->pxPayUserID, $this->pxPayKey);
		return $pxpay->makeRequest($request);
	}
}

class PaymentExpressGateway_PxPay_Mock extends PaymentExpressGateway_PxPay
{

	public function makeProcessRequest($request, $data)
	{

		$this->onBeforeMakeRequest();

		//Mock request string
		$mock = isset($data['mock']) ? $data['mock'] : false;
		if ($mock) {
			switch ($mock) {
				case 'incomplete':
					$request_string = false;
					break;
				case 'failure':
					$request_string = "
		    	<Request valid=\"0\">
						<URI></URI>
					</Request>";
					break;
				case 'success':
				default:
					$request_string = "
		    	<Request valid=\"1\">
						<URI>{$this->pxPayUrl}?userid={$this->pxPayUserID}&amp;request=v52CRsqBR5-mock</URI>
					</Request>";
					break;
			}
		} else {
			throw new Exception('Mock string not passed');
		}

		return $request_string;
	}

	public function makeCheckRequest($request, $data)
	{

		//Mock request string
		$mock = isset($data['mock']) ? $data['mock'] : false;
		if ($mock) {
			switch ($mock) {

					//Gateway could not be reached, curl_exec returns false
				case 'incomplete':
					$request_string = false;
					break;
				case 'failure':
					$request_string = '
			    <Response valid="1">
			    	<Success>0</Success>
			    	<TxnType>Purchase</TxnType>
			    	<CurrencyInput>NZD</CurrencyInput>
			    	<MerchantReference></MerchantReference>
			    	<TxnData1></TxnData1>
			    	<TxnData2></TxnData2>
			    	<TxnData3></TxnData3>
			    	<AuthCode>150715</AuthCode>
			    	<CardName>Visa</CardName>
			    	<CardHolderName>Joe Bloggs</CardHolderName>
			    	<CardNumber>411111........11</CardNumber>
			    	<DateExpiry>1213</DateExpiry>
			    	<ClientInfo>123.255.12.345</ClientInfo>
			    	<TxnId>ID5192f4a180c796-mock</TxnId>
			    	<EmailAddress></EmailAddress>
			    	<DpsTxnRef>0000000106502ae12-mock</DpsTxnRef>
			    	<BillingId></BillingId>
			    	<DpsBillingId></DpsBillingId>
			    	<AmountSettlement>50.00</AmountSettlement>
			    	<CurrencySettlement>NZD</CurrencySettlement>
			    	<DateSettlement>20130515</DateSettlement>
			    	<TxnMac></TxnMac>
			    	<ResponseText>APPROVED</ResponseText>
			    	<CardNumber2></CardNumber2>
			    	<IssuerCountryId>0</IssuerCountryId>
			    	<Cvc2ResultCode>NotUsed</Cvc2ResultCode>
			    	<ReCo>00</ReCo>
			    </Response>';
					break;
				case 'success':
				default:
					$request_string = '
			    <Response valid="1">
			    	<Success>1</Success>
			    	<TxnType>Purchase</TxnType>
			    	<CurrencyInput>NZD</CurrencyInput>
			    	<MerchantReference></MerchantReference>
			    	<TxnData1></TxnData1>
			    	<TxnData2></TxnData2>
			    	<TxnData3></TxnData3>
			    	<AuthCode>150715</AuthCode>
			    	<CardName>Visa</CardName>
			    	<CardHolderName>Joe Bloggs</CardHolderName>
			    	<CardNumber>411111........11</CardNumber>
			    	<DateExpiry>1213</DateExpiry>
			    	<ClientInfo>123.255.12.345</ClientInfo>
			    	<TxnId>ID5192f4a180c796-mock</TxnId>
			    	<EmailAddress></EmailAddress>
			    	<DpsTxnRef>0000000106502ae12-mock</DpsTxnRef>
			    	<BillingId></BillingId>
			    	<DpsBillingId></DpsBillingId>
			    	<AmountSettlement>50.00</AmountSettlement>
			    	<CurrencySettlement>NZD</CurrencySettlement>
			    	<DateSettlement>20130515</DateSettlement>
			    	<TxnMac></TxnMac>
			    	<ResponseText>APPROVED</ResponseText>
			    	<CardNumber2></CardNumber2>
			    	<IssuerCountryId>0</IssuerCountryId>
			    	<Cvc2ResultCode>NotUsed</Cvc2ResultCode>
			    	<ReCo>00</ReCo>
			    </Response>';
					break;
			}
		} else {
			throw new Exception('Mock string not passed');
		}

		return $request_string;
	}
}
