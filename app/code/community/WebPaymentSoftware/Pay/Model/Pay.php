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

class WebPaymentSoftware_Pay_Model_Pay extends Mage_Payment_Model_Method_Cc
{
	protected $_code = 'pay';
	protected $_formBlockType = 'pay/form_pay';
	protected $_infoBlockType = 'pay/info_pay';

	//protected $_isGateway               = true;
	protected $_canAuthorize            = true;
	protected $_canCapture              = true;
	protected $_canRefund               = true;
    //protected $_canCapturePartial       = true;


	protected $_canSaveCc = false; //if made try, the actual credit card number and cvv code are stored in database.

	//protected $_canRefundInvoicePartial = true;
	//protected $_canVoid                 = true;
	//protected $_canUseInternal          = true;
	//protected $_canUseCheckout          = true;
	//protected $_canUseForMultishipping  = true;
	//protected $_canFetchTransactionInfo = true;
	//protected $_canReviewPayment        = true;


	public function process($data){

		if($data['cancel'] == 1){
		 $order->getPayment()
		 ->setTransactionId(null)
		 ->setParentTransactionId(time())
		 ->void();
		 $message = 'Unable to process Payment';
		 $order->registerCancellation($message)->save();
		}
	}

	/** For capture **/
	public function capture(Varien_Object $payment, $amount)
	{
		$order = $payment->getOrder();
		$result = $this->callApi($payment, $amount, 'AuthCapture');
		if($result == false) {
			$errorCode = 'Invalid Data';
			$errorMsg = $this->_getHelper()->__('Error Processing the request');
		} else {
            if($result['status'] == '00'){
				$payment->setTransactionId($result['transaction_id']);
				$payment->setIsTransactionClosed(1);
				$order->save();
			}else{
                $errorMsg = $this->_getHelper()->__($result['response_text']);
				Mage::throwException($errorMsg);
			}
		}
		if(isset($errorMsg)){
			Mage::throwException($errorMsg);
		}

		return $this;
	}


	/** For authorization **/
	public function authorize(Varien_Object $payment, $amount)
	{
		$order = $payment->getOrder();
		$result = $this->callApi($payment, $amount, 'AuthOnly');
        
        if($result == false) {
			$errorCode = 'Invalid Data';
			$errorMsg = $this->_getHelper()->__('Error Processing the request');
		} else {
            if($result['status'] == '00'){
				$payment->setTransactionId($result['transaction_id']);
				$payment->setIsTransactionClosed(1);
				$order->save();
			}else{
                $errorMsg = $this->_getHelper()->__($result['response_text']);
				Mage::throwException($errorMsg);
			}
		}
		if(isset($errorMsg)){
			Mage::throwException($errorMsg);
		}

		return $this;
	}

	public function processBeforeRefund($invoice, $payment){
		return parent::processBeforeRefund($invoice, $payment);
	}
    
	public function refund(Varien_Object $payment, $amount){
		$order = $payment->getOrder();
		$result = $this->callApi($payment,$amount,'Reversal');
		if($result == false) {
			$errorCode = 'Invalid Data';
			$errorMsg = $this->_getHelper()->__('Error Processing the request');
			Mage::throwException($errorMsg);
		}
		return $this;
	}
    
	public function processCreditmemo($creditmemo, $payment){
		return parent::processCreditmemo($creditmemo, $payment);
	}

	private function callApi(Varien_Object $payment, $amount, $type){

		$order = $payment->getOrder();
		$billingaddress = $order->getBillingAddress();
		$totals = number_format($amount, 2, '.', '');
		$orderId = $order->getIncrementId();
		$currencyDesc = $order->getBaseCurrencyCode();
        
        $trans = Mage::getModel('pay/api');        
        $trans->host = 	$this->getConfigData('gateway_url');
        $trans->merchant_id = 	$this->getConfigData('merchant_id');
        $trans->merchant_key =	$this->getConfigData('merchant_key');
        $trans->trans_type = 	$type;
        $trans->amount =		$totals;
        $trans->cc_number =		$payment->getCcNumber();
        $trans->cc_exp =		$this->getExp($payment->getCcExpMonth(), $payment->getCcExpYear());
        $trans->cc_cvv =		$payment->getCcCid();
        $trans->name =			$billingaddress->getData('firstname') . ' ' . $billingaddress->getData('lastname');
        $trans->address =		$billingaddress->getData('street');
        $trans->city =			$billingaddress->getData('city');
        $trans->state =			$this->getState($billingaddress->getData('region_id'));
        $trans->zip =			$billingaddress->getData('postcode');
        $trans->country =		$billingaddress->getData('country_id');
        $trans->test_mode = 	$this->getConfigData('test');
        $trans->debug = 		$this->getConfigData('debug');
        
        $trans->transact();
        
        return array('status' => $trans->response_code, 'transaction_id' => $trans->approval_code, 'response_text' => $trans->response_text);
        
		//return array('status'=>1,'transaction_id' => time() , 'fraud' => rand(0,1));
        //return array('status'=>rand(0, 1),'transaction_id' => time() , 'fraud' => rand(0,1));
	}
    
    function getState($region_id, $region){
        if($region_id){
            $region = Mage::getModel('directory/region')->load($region_id);
            $state = $region->getName();

            $explode = explode(' ', $state);
            if (count($explode) >= 2) {
                $state = substr($explode[0], 0, 1) . substr($explode[1], 0, 1);
            } else {
                $state = substr($state, 0, 2);
            }

            return $state;
        }elseif ($region) {
            $state = $region;
            $explode = explode(' ', $state);
            if (count($explode) >= 2) {
                $state = substr($explode[0], 0, 1) . substr($explode[1], 0, 1);
            } else {
                $state = substr($state, 0, 2);
            }

            return $state;
        }
    }
    
    function getExp($month, $year){
        if(strlen($month) == 1) {
            $month = '0'.$month;
        }
        $year = substr($year, -2);
        
        return "$month$year";
    }
}