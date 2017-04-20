<?php
use AspectMock\Test as test;

require_once(__DIR__.'/../../../../src/catalog/controller/payment/omise.php');

class ControllerPaymentOmiseTest extends PHPUnit_Framework_TestCase
{
    public function setup()
    {
        $this->registry = new MockRegistry();
        $this->controller = new ControllerPaymentOmise($this->registry);
    }

    public function tearDown()
    {
        test::clean();
    }

    public function testCheckoutSuccess()
    {
        $model_checkout_order = $this->registry->mockModel('checkout/order', array('getOrder', 'addOrderHistory'));
        $model_checkout_order
            ->method('getOrder')
            ->with(1)
            ->willReturn(array(
                'total' => 1500,
                'currency_code' => 'thb'
            ));
        $model_checkout_order
            ->expects($this->at(0))
            ->method('addOrderHistory')
            ->with(1, 2);

        $this->registry->get('currency')
            ->method('format')
            ->with(1500, 'thb', '', false)
            ->willReturn('1,500 ฿');
        $this->registry->get('currency')
            ->method('getCode')
            ->willReturn('thb');

        $charge_double = test::double('OmiseCharge', array('create' => array(
            'id' => 'id!!',
            'failure_code' => '',
            'authorized' => true,
            'captured' => true
        )));

        $this->controller->session->data['order_id'] = 1;
        $this->controller->request->post['omise_token'] = 'token';
        $this->controller->request->post['description'] = 'description';

        $this->controller->checkout();

        $output = $this->getActualOutput();
        $json = json_decode($output, true);
        $this->assertEquals('id!!', $json['omise']['id']);
        $this->assertEquals('', $json['omise']['failure_code']);
        $this->assertTrue($json['omise']['authorized']);
        $this->assertTrue($json['omise']['captured']);
    }

    public function testCheckoutCallback()
    {
        $model_payment_omise = $this->registry->mockModel('payment/omise', array(
            'retrieveOmiseKey',
            'getChargeTransaction',
        ));
        $model_payment_omise
            ->method('retrieveOmiseKey')
            ->willReturn(array(
                'pkey' => 'public',
                'skey' => 'secret',
            ));
        $model_payment_omise
            ->method('getChargeTransaction')
            ->with(1)
            ->willReturnCallback(function () {
                $txn = new stdClass();
                $txn->row = array(
                    'omise_charge_id' => 'chrg_test_1111',
                );
                return $txn;
            });

        $model_checkout_order = $this->registry->mockModel('checkout/order', array(
            'addOrderHistory'
        ));
        $model_checkout_order
            ->expects($this->once())
            ->method('addOrderHistory')
            ->with(1, 15);

        $this->registry->get('response')
            ->expects($this->once())
            ->method('redirect')
            ->with('checkout/success____');

        $fake_charge = array(
            'authorized' => true,
            'captured' => true,
        );
        test::double('OmiseCharge', array('retrieve' => $fake_charge));

        $request = $this->registry->get('request');
        $request->get['order_id'] = 1;

        $this->controller->checkoutCallback();
    }

    public function testCheckoutCallbackWaiting()
    {
        $model_payment_omise = $this->registry->mockModel('payment/omise', array(
            'retrieveOmiseKey',
            'getChargeTransaction',
        ));
        $model_payment_omise
            ->method('retrieveOmiseKey')
            ->willReturn(array(
                'pkey' => 'public',
                'skey' => 'secret',
            ));
        $model_payment_omise
            ->method('getChargeTransaction')
            ->with(1)
            ->willReturnCallback(function () {
                $txn = new stdClass();
                $txn->row = array(
                    'omise_charge_id' => 'chrg_test_1111',
                );
                return $txn;
            });

        $model_checkout_order = $this->registry->mockModel('checkout/order', array(
            'addOrderHistory'
        ));
        $model_checkout_order
            ->expects($this->once())
            ->method('addOrderHistory')
            ->with(1, 15);

        $this->registry->get('response')
            ->expects($this->once())
            ->method('redirect')
            ->with('checkout/success____');

        $fake_charge = array(
            'authorized' => false,
            'captured'   => false,
            'status'     => 'pending',
        );
        test::double('OmiseCharge', array('retrieve' => $fake_charge));

        $request = $this->registry->get('request');
        $request->get['order_id'] = 1;

        $this->controller->checkoutCallback();
    }

    public function testIndex()
    {
        $model_checkout_order = $this->registry->mockModel('checkout/order', array(
            'getOrder'
        ));
        $model_checkout_order
            ->method('getOrder')
            ->with(1)
            ->willReturn(array(
                'currency_code' => 'thb',
                'total' => 1500,
                'telephone' => '1150',
                'payment_address_1' => '1448/2',
                'payment_iso_code_2' => 'TH',
                'payment_zone' => '',
                'payment_city' => 'BKK',
                'payment_postcode' => '10240',
                'shipping_firstname' => 'test',
                'shipping_lastname' => 'test',
                'shipping_address_1' => '1488/2',
                'shipping_city' => 'BKK',
                'shipping_iso_code_2' => 'TH',
                'shipping_zone' => '',
                'shipping_postcode' => '10240',
                'email' => 'omise@omise.co',
            ));

        $this->registry->get('session')->data['order_id'] = 1;

        $this->registry->get('load')
            ->method('view')
            ->with('default/template/payment/omise.tpl')
            ->willReturnCallback(function ($name, $data) {
                $this->assertEquals('thb', $data['currency']);
            });

        $this->controller->index();
    }

    public function testProcessing()
    {
        $this->registry->get('customer')->method('isLogged')->willReturn(true);
        $this->registry->get('request')->get['order_id'] = 1;

        $this->registry->get('load')
            ->method('view')
            ->with('default/template/common/success.tpl')
            ->willReturnCallback(function ($name, $data) {
                $this->assertEquals('l10n_heading_title', $data['heading_title']);
                $this->assertEquals('common/home____', $data['continue']);
            });

        $this->controller->processing();
    }

    public function testRefresh()
    {
        $this->registry->get('request')->get['order_id'] = 1;

        $model_checkout_order = $this->registry->mockModel('checkout/order', array('getOrder', 'addOrderHistory'));
        $model_checkout_order->expects($this->at(0))
            ->method('getOrder')
            ->with(1)
            ->willReturn(array(
                'order_status_id' => 2
            ));
        $model_checkout_order->expects($this->at(1))
            ->method('getOrder')
            ->with(1)
            ->willReturn(array(
                'order_status'    => 'Processed',
                'order_status_id' => 15
            ));

        $model_payment_omise = $this->registry->mockModel('payment/omise', array('getChargeTransaction'));
        $model_payment_omise->method('getChargeTransaction')->with(1)->willReturnCallback(function () {
            $transaction = new stdClass;
            $transaction->row = array('omise_charge_id' => 'chrg_test_1111');
            return $transaction;
        });

        $fake_charge = array(
            'authorized' => true,
            'captured'   => true,
        );
        test::double('OmiseCharge', array('retrieve' => $fake_charge));

        $this->registry->get('response')->method('setOutput')->willReturnCallback(function ($output) {
            $json = json_decode($output, true);
            $this->assertEquals('Order status has been updated.', $json['success']);
        });

        $this->controller->refresh();
    }
}