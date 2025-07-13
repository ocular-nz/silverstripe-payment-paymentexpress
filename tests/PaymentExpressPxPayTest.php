<?php

use Payment\PaymentFactory;
use SilverStripe\Core\Config\Config;
use SilverStripe\Dev\SapphireTest;

/**
 * Tests for 'connect' test gateway process() which connects to gateway and gets the URL to redirect to
 * Other tests are for the gateway check() method which connects to gateway to check the payment went through
 */
class PaymentExpressPxPayTest extends SapphireTest
{

    public $data;
    public $processor;

    function setUp(): void
    {
        parent::setUp();

        Config::inst()->remove('PaymentGateway', 'environment');
        Config::inst()->update('PaymentGateway', 'environment', 'test');

        // $paymentMethods = array('test' => array('PaymentExpressPxPay'));
        Config::inst()->remove('PaymentProcessor', 'supported_methods');
        Config::inst()->update('PaymentProcessor', 'supported_methods', array(
            'test' => array(
                'PaymentExpressPxPay'
            )
        ));

        $gatewayConfig = array('authentication' => array(
            'user_id' => 'mock_user_id',
            'key' => 'mock_key'
        ));
        Config::inst()->remove('PaymentExpressGateway_PxPay', 'test');
        Config::inst()->update('PaymentExpressGateway_PxPay', 'test', $gatewayConfig);

        $this->processor = PaymentFactory::factory('PaymentExpressPxPay');

        $this->data = array(
            'Amount' => '10',
            'Currency' => 'USD'
        );
    }

    public function testProcessorConfig()
    {
        $this->assertEquals(get_class($this->processor), 'PaymentProcessor_GatewayHosted');
        $this->assertEquals(get_class($this->processor->gateway), 'PaymentExpressGateway_PxPay_Mock');
        $this->assertEquals(get_class($this->processor->payment), 'Payment');
    }

    public function testGatewayConfig()
    {
        $config = $this->processor->gateway->getConfig();

        $PxPay_Url    = Config::inst()->get('PaymentExpressGateway_PxPay', 'url');
        $PxPay_Userid = $config['authentication']['user_id'];
        $PxPay_Key    = $config['authentication']['key'];

        $this->assertEquals($PxPay_Url, 'https://sec.paymentexpress.com/pxpay/pxaccess.aspx');
        $this->assertTrue(isset($PxPay_Userid));
        $this->assertTrue(isset($PxPay_Key));
    }

    public function testPaymentConnectSuccess()
    {

        $config = $this->processor->gateway->getConfig();
        $PxPay_Url    = Config::inst()->get('PaymentExpressGateway_PxPay', 'url');
        $PxPay_Userid = $config['authentication']['user_id'];

        //This should set up a redirect to the gateway for the browser in the response of the controller
        $this->data['mock'] = 'success';
        $this->processor->capture($this->data);

        $payment = $payment = Payment::get()->byID($this->processor->payment->ID);
        $this->assertEquals($payment->Status, Payment::PENDING);
    }

    public function testPaymentConnectFailure()
    {

        $config = $this->processor->gateway->getConfig();
        $PxPay_Url    = Config::inst()->get('PaymentExpressGateway_PxPay', 'url');
        $PxPay_Userid = $config['authentication']['user_id'];

        //This should set up a redirect to the gateway for the browser in the response of the controller
        $this->data['mock'] = 'failure';
        $this->processor->capture($this->data);

        $payment = $payment = Payment::get()->byID($this->processor->payment->ID);
        $this->assertEquals($payment->Status, Payment::FAILURE);
    }

    public function testPaymentConnectIncomplete()
    {

        $config = $this->processor->gateway->getConfig();
        $PxPay_Url    = Config::inst()->get('PaymentExpressGateway_PxPay', 'url');
        $PxPay_Userid = $config['authentication']['user_id'];

        //This should set up a redirect to the gateway for the browser in the response of the controller
        $this->data['mock'] = 'incomplete';
        $this->processor->capture($this->data);

        $payment = $payment = Payment::get()->byID($this->processor->payment->ID);
        $this->assertEquals($payment->Status, Payment::INCOMPLETE);
    }

    public function testPaymentSuccess()
    {

        $config = $this->processor->gateway->getConfig();
        $PxPay_Url    = Config::inst()->get('PaymentExpressGateway_PxPay', 'url');
        $PxPay_Userid = $config['authentication']['user_id'];

        //This should set up a redirect to the gateway for the browser in the response of the controller
        $this->data['mock'] = 'success';
        $this->processor->capture($this->data);

        //Test redirect to gateway
        $response = Controller::curr()->getResponse();
        $this->assertEquals($response->getHeader('Location'), "{$PxPay_Url}?userid={$PxPay_Userid}&request=v52CRsqBR5-mock");

        //Test payment completion after redirect from gateway
        $queryString = http_build_query(array(
            'result' => 'v52GezCtXh1qqQ-mock',
            'userid' => $PxPay_Userid,
            'mock' => 'success'
        ));
        Director::test($this->processor->gateway->returnURL . "?$queryString");

        $payment = $payment = Payment::get()->byID($this->processor->payment->ID);
        $this->assertEquals($payment->Status, Payment::SUCCESS);
    }

    public function testPaymentFailure()
    {

        $config = $this->processor->gateway->getConfig();
        $PxPay_Url    = Config::inst()->get('PaymentExpressGateway_PxPay', 'url');
        $PxPay_Userid = $config['authentication']['user_id'];

        //This should set up a redirect to the gateway for the browser in the response of the controller
        $this->data['mock'] = 'success';
        $this->processor->capture($this->data);

        //Test redirect to gateway
        $response = Controller::curr()->getResponse();
        $this->assertEquals($response->getHeader('Location'), "{$PxPay_Url}?userid={$PxPay_Userid}&request=v52CRsqBR5-mock");

        //Test payment completion after redirect from gateway
        $queryString = http_build_query(array(
            'result' => 'v52GezCtXh1qqQ-mock',
            'userid' => $PxPay_Userid,
            'mock' => 'failure'
        ));
        Director::test($this->processor->gateway->returnURL . "?$queryString");

        $payment = $payment = Payment::get()->byID($this->processor->payment->ID);
        $this->assertEquals($payment->Status, Payment::FAILURE);
    }

    public function testPaymentIncomplete()
    {

        $config = $this->processor->gateway->getConfig();
        $PxPay_Url    = Config::inst()->get('PaymentExpressGateway_PxPay', 'url');
        $PxPay_Userid = $config['authentication']['user_id'];

        //This should set up a redirect to the gateway for the browser in the response of the controller
        $this->data['mock'] = 'success';
        $this->processor->capture($this->data);

        //Test redirect to gateway
        $response = Controller::curr()->getResponse();
        $this->assertEquals($response->getHeader('Location'), "{$PxPay_Url}?userid={$PxPay_Userid}&request=v52CRsqBR5-mock");

        //Test payment completion after redirect from gateway
        $queryString = http_build_query(array(
            'result' => 'v52GezCtXh1qqQ-mock',
            'userid' => $PxPay_Userid,
            'mock' => 'incomplete'
        ));
        Director::test($this->processor->gateway->returnURL . "?$queryString");

        $payment = $payment = Payment::get()->byID($this->processor->payment->ID);
        $this->assertEquals($payment->Status, Payment::INCOMPLETE);
    }
}

