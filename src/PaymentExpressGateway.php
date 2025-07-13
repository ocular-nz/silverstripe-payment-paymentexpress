<?php

namespace PaymentExpress;

use Exception;
use Payment\Payment;
use Payment\PaymentGateway_Failure;
use Payment\PaymentGateway_GatewayHosted;
use Payment\PaymentGateway_Incomplete;
use Payment\PaymentGateway_Success;
use Psr\Log\LoggerInterface;
use SilverStripe\Control\Controller;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Core\Injector\Injector;
use App\Web\SavedCardService;
use App\Web\FieldpineApi;
use SwipeStripe\Order\Order;
use GuzzleHttp\Client;
use Payment\Payment_Error;

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

    public function setPxPayUrl($pxPayUrl)
    {
        $this->pxPayUrl = $pxPayUrl;
    }

    public function setPxPayUserID($pxPayUserID)
    {
        $this->pxPayUserID = $pxPayUserID;
    }

    public function setPxPayKey($pxPayKey)
    {
        $this->pxPayKey = $pxPayKey;
    }

    public function getSupportedCurrencies()
    {

        $config = $this->getConfig();
        if (isset($config['supported_currencies'])) {
            $this->supportedCurrencies = $config['supported_currencies'];
        }
        return $this->supportedCurrencies;
    }

    public function process($data)
    {
        // Check if we have a BillingId (saved card) - if so, use PxPost directly
        if (isset($data['BillingId']) && !empty($data['BillingId'])) {
            return $this->processSavedCardPayment($data);
        }

        //Construct the request for new card payment via PxPay
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

    /**
     * Process payment using saved card via PxPost
     */
    protected function processSavedCardPayment($data)
    {
        $fieldpineApi = Injector::inst()->get(FieldpineApi::class);
        $logger = Injector::inst()->get(LoggerInterface::class);

        $amount = $data['Amount'];
        $billingId = $data['BillingId'];
        $reference = $data['Reference'] ?? '';

        $logger->debug('Processing saved card payment via PxPost', [
            'Amount' => $amount,
            'BillingId' => $billingId,
            'Reference' => $reference
        ]);

        try {
            // Build PxPost request XML
            $xml = new \SimpleXMLElement('<Txn/>');
            $xml->addChild('PostUsername', htmlspecialchars($fieldpineApi->pxPostUsername));
            $xml->addChild('PostPassword', htmlspecialchars($fieldpineApi->pxPostPassword));
            $xml->addChild('TxnType', 'Purchase'); // Use Purchase for immediate capture
            $xml->addChild('InputCurrency', $data['Currency'] ?? 'NZD');
            $xml->addChild('Amount', sprintf('%.2f', $amount));
            $xml->addChild('DpsBillingId', htmlspecialchars($billingId));
            $xml->addChild('MerchantReference', htmlspecialchars($reference));

            $pxRequestBody = $xml->asXML();

            // Create Guzzle client for PxPost
            $client = new Client([
                'timeout' => 30,
                'verify' => false
            ]);

            // Send payment request
            $response = $client->post($fieldpineApi->pxPostUrl, [
                'body' => $pxRequestBody,
                'headers' => [
                    'Content-Type' => 'text/xml'
                ]
            ]);

            if ($response->getStatusCode() !== 200) {
                $logger->error('PxPost HTTP error', [
                    'HttpCode' => $response->getStatusCode()
                ]);
                return new PaymentGateway_Failure();
            }

            // Parse XML response
            $pxResponseBody = simplexml_load_string($response->getBody()->getContents());

            if (!$pxResponseBody) {
                $logger->error('Failed to parse PxPost response', [
                    'Response' => $response->getBody()->getContents()
                ]);
                return new PaymentGateway_Failure();
            }

            $success = (string)$pxResponseBody->Success;
            $dpsTxnRef = (string)$pxResponseBody->DpsTxnRef;
            $responseText = (string)$pxResponseBody->ResponseText;
            $cardHolderHelpText = (string)$pxResponseBody->CardHolderHelpText;

            if ($success === '1') {
                $logger->info('PxPost payment successful', [
                    'DpsTxnRef' => $dpsTxnRef,
                    'Amount' => $amount
                ]);

                // Store transaction details in the result for later processing
                $result = new PaymentGateway_Success();
                $result->setAdditionalData([
                    'DpsTxnRef' => $dpsTxnRef,
                    'BillingId' => $billingId,
                    'Amount' => $amount,
                    'Currency' => $data['Currency'] ?? 'NZD',
                    'PaymentId' => $data['PaymentId']
                ]);

                return $result;
            } else {
                $logger->error('PxPost payment failed', [
                    'ResponseText' => $responseText,
                    'CardHolderHelpText' => $cardHolderHelpText
                ]);

                $errorMessage = $cardHolderHelpText ?: $responseText;
                $failure = new PaymentGateway_Failure();
                $failure->addError($errorMessage);
                return $failure;
            }
        } catch (\Exception $e) {
            $logger->error('Exception during PxPost payment', [
                'Exception' => $e->getMessage()
            ]);

            $failure = new PaymentGateway_Failure();
            $failure->addError('Payment processing failed: ' . $e->getMessage());
            return $failure;
        }
    }

    public function makeProcessRequest($request, $data)
    {
        $pxpay = new PxPay_Curl($this->pxPayUrl, $this->pxPayUserID, $this->pxPayKey);
        return $pxpay->makeRequest($request);
    }

    /**
     * Check that the payment was successful using "Process Response" API (http://www.paymentexpress.com/Technical_Resources/Ecommerce_Hosted/PxPay.aspx).
     *
     * @param HTTPRequest $request Request from the gateway - transaction response
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

        Injector::inst()->get(LoggerInterface::class)->debug('PxPay check response: ' . $request_string);

        //Obtain output XML
        $response = new MifMessage($request_string);

        //Parse output XML
        $success = $response->get_element_text('Success');
        $DPStnxid = $response->get_element_text('DpsTxnRef');

        // get payment object
        $rp = Payment::get()->byId($paymentID);

        // get billing id and add to member
        $dpsBillingId = $response->get_element_text('DpsBillingId');

        if ($dpsBillingId) {
            // Save card details if this was a successful payment with billing ID
            if ($success) {
                $this->saveCardFromResponse($response, $rp);
            }
        }

        // attach ref to payment object
        $rp->DPSReference = $DPStnxid;
        $rp->write();

        if ($success && is_numeric($success) && $success > 0) {
            return new PaymentGateway_Success();
        } else if (is_numeric($success) && $success == 0) {
            $failureText = $response->get_element_text('CardHolderHelpText');
            $failure = new PaymentGateway_Failure();
            $failure->addError($failureText);
            return $failure;
        } else {
            return new PaymentGateway_Incomplete();
        }
    }

    public function makeCheckRequest($request, $data)
    {
        $pxpay = new PxPay_Curl($this->pxPayUrl, $this->pxPayUserID, $this->pxPayKey);
        return $pxpay->makeRequest($request);
    }

    /**
     * Save card details from successful Windcave response
     */
    protected function saveCardFromResponse(MifMessage $response, Payment $payment): void
    {
        $member = $payment->PaidBy();
        if (!$member || !$member->exists()) {
            return;
        }

        // Check if we have a DpsBillingId (indicates EnableAddBillCard was set)
        $dpsBillingId = $response->get_element_text('DpsBillingId');
        if (!$dpsBillingId) {
            return;
        }

        // Check if this is a card update request
        $updateCardId = null;
        if (str_contains($payment->Reference, 'Update Card #')) {
            $updateCardId = (int)str_replace('Update Card #', '', $payment->Reference);
        }

        $savedCardService = Injector::inst()->get(SavedCardService::class);
        $savedCard = $savedCardService->saveCardFromResponse(
            response: $response,
            member: $member,
            updateCardId: $updateCardId
        );

        // If this payment is for a standing order, link the saved card to it
        if ($savedCard && $payment->OrderID) {
            $order = Order::get()->byID($payment->OrderID);
            if ($order && $order->IsStandingOrder()) {
                $order->SavedCardID = $savedCard->ID;
                $order->write();
            }
        }
    }
}

class PaymentExpressGateway_PxPay_Mock extends PaymentExpressGateway_PxPay
{

    public function makeProcessRequest($request, $data)
    {
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
