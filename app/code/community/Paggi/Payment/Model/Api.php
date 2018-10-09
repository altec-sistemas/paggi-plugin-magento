<?php
/**
 * INFORMAÇÕES SOBRE LICENÇA
 *
 * Open Software License (OSL 3.0).
 * http://opensource.org/licenses/osl-3.0.php
 *
 * DISCLAIMER
 *
 * Não edite este arquivo caso você pretenda atualizar este módulo futuramente
 * para novas versões.
 *
 * @category      Paggi
 * @package       Paggi_Payment
 * @author        Thiago Contardi <thiago@contardi.com.br>
 *
 * @license       http://opensource.org/licenses/osl-3.0.php  Open Software License (OSL 3.0)
 *
 */
class Paggi_Payment_Model_Api extends Mage_Core_Model_Abstract
{
    protected $_paggi;
    protected $_helper;
    protected $_customerHelper;
    protected $_orderHelper;
    protected $_paymentHelper;

    /**
     * Paggi lib Object
     * @return Paggi_Payment_Model_Service_Order|false
     */
    public function getService()
    {
        if (!$this->_paggi) {
            /** @var Paggi_Payment_Model_Service_Order _paggi */
            $this->_paggi = Mage::getModel('paggi/service_order');
        }

        return $this->_paggi;

    }

    /**
     * Remove the Credit Card frm Paggi Account and remove from the store Account
     *
     * @param $ccSaved
     * @return bool
     */
    public function deleteCC($cardToken)
    {
        try {

            $endpoint = $this->getHelper()->getEndpoint('remove_cards', null, $cardToken);
            $response = $this->getService()->doDeleteRequest($endpoint);
            $responseData = null;
            if ($response->getStatus() >= 300) {
                Mage::throwException($this->getHelper()->__('Error removing credit card'));
            }

            $this->getHelper()->saveTransaction($endpoint, $responseData);

        } catch (Exception $e) {
            Mage::logException($e);
            Mage::throwException($e->getMessage());
        }

        return true;
    }

    /**
     * @param Paggi_Payment_Model_Method_Cc $method
     * @param Mage_Sales_Model_Order_Payment $payment
     * @param float $amount
     * @return object|boolean
     */
    public function ccMethod(Paggi_Payment_Model_Method_Cc $method, $payment, $amount)
    {
        $paggiOrder = new stdClass();
        $response = null;
        $code = $method->getCode();

        /** @var Mage_Sales_Model_Order $order */
        $order = $payment->getOrder();

        //Order Data
        $orderId = $order->getIncrementId();

        $ccInstallments = $payment->getAdditionalInformation('cc_installments');

        if ($payment->getAdditionalInformation('cc_total_with_interest') > 0) {
            $amount = number_format($payment->getAdditionalInformation('cc_total_with_interest'), 2, '.', '');
        }

        $token = $payment->getAdditionalInformation('cc_token');
        $amount = number_format($amount, 2, '.', '');
        $ccCid = Mage::registry('paggi_cc_cid');

        $charge = new stdClass();
        $charge->amount = $amount;
        $charge->installments = $ccInstallments;

        if ($token) {

            $charge->card = new stdClass();
            $charge->card->token = $token;
            $charge->card->cvc = $ccCid;

        } else {

            $ccNumber = $payment->decrypt($payment->getCcNumberEnc());
            $ccOwner = $payment->getCcOwner();
            $ccExpMonth = str_pad($payment->getCcExpMonth(), 2, '0', STR_PAD_LEFT);
            $ccExpYear = $payment->getCcExpYear();

            $charge->card = new stdClass();
            $charge->card->number = $ccNumber;
            $charge->card->cvc = $ccCid;
            $charge->card->holder = $ccOwner;
            $charge->card->year = $ccExpYear;
            $charge->card->month = $ccExpMonth;

            $ccSaveCard = $payment->getAdditionalInformation('cc_save_card');
            if ($ccSaveCard) {
                $this->saveCard($payment);
            }
        }

        $charges = array();
        array_push($charges, $charge);

        $customer = new stdClass();
        $customer->name = $order->getCustomerFirstname() . ' ' . $order->getCustomerLastname();
        $customer->email = $order->getCustomerEmail();
        $customer->document = $this->getHelper()->digits($payment->getAdditionalInformation('cpf_cnpj'));

        if (!$this->getHelper()->getConfigData('show_taxvat_field')) {
            $customer->document = $this->getHelper()->getTaxvatValue();
        }

        $paggiOrder->capture = $this->getHelper()->getConfig('capture', $code) ? true : false;
        $paggiOrder->external_identifier = $orderId;
        $paggiOrder->charge = $charges;
        $paggiOrder->customer = $customer;

        $endpoint = $this->getHelper()->getEndpoint('order');
        $orderData = Mage::helper('core')->jsonEncode($paggiOrder);
        $response = $this->getService()->doPostRequest($endpoint, $orderData);
        $responseData = json_decode($response->getBody());

        $this->getHelper()->saveTransaction($paggiOrder, $responseData, $orderId);

        if ($response->getStatus() >= 300) {
            Mage::throwException($this->getHelper()->__('There was an error') . ': ' . $response->getMessage());
        }
        return $response;
    }

    /**
     * @param Paggi_Payment_Model_Method_Cc $method
     * @param Mage_Payment_Model_Info $paymentInfo
     * @param float $amount
     * @return object|boolean
     */
    public function recurringMethod(Paggi_Payment_Model_Method_Cc $method, $profile, $paymentInfo, $action = 'new')
    {
        try {
            $quote = $profile->getQuote();

            $code = $method->getCode();
            $softDescriptor = $this->getHelper()->getConfig('soft_descriptor', $code);

            $response = null;

            //Order Data
            $referenceNum = $profile->getData('internal_reference_id');
            $currencyCode = Mage::app()->getStore()->getCurrentCurrencyCode();

            $ccCid = Mage::registry('paggi_cc_cid');

            $ipAddress = $this->getIpAddress();

            $amount = number_format($profile->getTaxAmount() + $profile->getBillingAmount() + $profile->getShippingAmount(), 2, '.', '');

            $cpfCnpj = $paymentInfo->getAdditionalInformation('cpf_cnpj');

            $ccNumber = $paymentInfo->decrypt($paymentInfo->getCcNumberEnc());
            $ccExpMonth = str_pad($paymentInfo->getCcExpMonth(), 2, '0', STR_PAD_LEFT);
            $ccExpYear = $paymentInfo->getCcExpYear();

            $startDate = date('Y-m-d');
            if ($profile->getData('start_datetime')) {
                $startDate = date('Y-m-d', strtotime($profile->getData('start_datetime')));
            }

            $frequency = $profile->getData('period_frequency');
            $failureThreshold = $profile->getData('suspension_threshold');
            $installments = $profile->getData('period_max_cycles');
            if (!$installments) {
                $installments = '1';
            }

            $period = 'monthly';
            switch($profile->getData('period_unit')){
                case 'day':
                    $period = 'daily';
                    break;
                case 'week':
                    $period = 'weekly';
                    break;
                case 'month':
                    $period = 'monthly';
                    break;
            }

            $data = array(
                'referenceNum' => $referenceNum, //Profile reference NUM
                'processorID' => $processorID, //Processor
                'ipAddress' => $ipAddress,
                'customerIdExt' => $cpfCnpj,
                //'fraudCheck' => $fraudCheck,
                'number' => $ccNumber,
                'expMonth' => $ccExpMonth,
                'expYear' => $ccExpYear,
                'cvvNumber' => $ccCid,
                'currencyCode' => $currencyCode,
                'chargeTotal' => $amount,
                'softDescriptor' => $softDescriptor,
                'action' => $action,
                //Recurring data
                'startDate' => $startDate,
                'frequency' => $frequency,
                'period' => $period,
                'installments' => $installments,
                'failureThreshold' => $failureThreshold
            );

            if ($profile->getInitAmount()) {
                if ($startDate == date('Y-m-d')) {
                    $data['startDate'] = date('Y-m-d', strtotime($startDate . ' +1 day'));
                }
                $data['firstAmount'] = number_format($profile->getInitAmount(), 2, '.', '');
            }

            $billingData = $this->getCustomerHelper()->getAddressData($quote, $paymentInfo);
            $shippingData = $this->getCustomerHelper()->getAddressData($quote, $paymentInfo, 'shipping');

            $data = array_merge($data, $billingData, $shippingData);

            $this->getService()->createRecurring($data);
            $response = $this->getService()->response;

            $this->getHelper()->log($this->getService()->xmlRequest);
            $this->getHelper()->log($this->getService()->xmlResponse);

            $this->getHelper()->saveTransaction('recurring_payment', $data, $response, $referenceNum);

            if (
                (isset($response['errorMessage']) && $response['errorMessage'])
                ||
                (isset($response['errorMsg']) && $response['errorMsg'])
            ) {
                $error = isset($response['errorMessage']) ? $response['errorMessage'] : $response['errorMsg'];
                Mage::throwException($error);
            }


            return $response;

        } catch (Exception $e) {
            Mage::logException($e);
        }

        return false;
    }

    /**
     * @param Mage_Payment_Model_Recurring_Profile $profile
     * @return boolean
     */
    public function cancelRecurring(Mage_Payment_Model_Recurring_Profile $profile)
    {
        try {
            $data = array(
                'command' => 'cancel-recurring',
                'orderID' =>  $profile->getData('reference_id')
            );

            $this->getService()->cancelRecurring($data);
            $response = $this->getService()->response;

            $this->getHelper()->log($this->getService()->xmlRequest);
            $this->getHelper()->log($this->getService()->xmlResponse);

            if (
                (isset($response['errorMessage']) && $response['errorMessage'])
                ||
                (isset($response['errorMsg']) && $response['errorMsg'])
            ) {
                $error = isset($response['errorMessage']) ? $response['errorMessage'] : $response['errorMsg'];
                Mage::throwException($error);
            }

            $this->getHelper()->saveTransaction('cancel_recurring', $data, $response);
        } catch (Exception $e) {
            return false;
        }

        return true;
    }

    public function updateRecurring($profile, $flagActive)
    {
        try {
            $data = array(
                'command' => 'modify-recurring',
                'orderID' =>  $profile->getData('reference_id'),
                'action' =>  (!$flagActive) ? 'disable' : 'enable'
            );

            $this->getService()->cancelRecurring($data);
            $response = $this->getService()->response;

            $this->getHelper()->log($this->getService()->xmlRequest);
            $this->getHelper()->log($this->getService()->xmlResponse);

            if (
                (isset($response['errorMessage']) && $response['errorMessage'])
                ||
                (isset($response['errorMsg']) && $response['errorMsg'])
            ) {
                $error = isset($response['errorMessage']) ? $response['errorMessage'] : $response['errorMsg'];
                Mage::throwException($error);
            }

            $this->getHelper()->saveTransaction('cancel_recurring', $data, $response);
        } catch (Exception $e) {
            return false;
        }

        return true;
    }

    /**
     * Save the credit card at the database
     * @param Mage_Payment_Model_Info $order
     * @return null
     */
    public function saveCard(Mage_Sales_Model_Order_Payment $payment)
    {
        try {
            /** @var Mage_Sales_Model_Order $order */
            $order = $payment->getOrder();

            $customerId = $order->getCustomerId();
            $firstname = $order->getCustomerFirstname();
            $lastname = $order->getCustomerLastname();
            $mpCustomerId = null;

            $ccNumber = $payment->decrypt($payment->getCcNumberEnc());
            $ccExpMonth = str_pad($payment->getCcExpMonth(), 2, '0', STR_PAD_LEFT);
            $ccExpYear = $payment->getCcExpYear();
            $ccCid = Mage::registry('paggi_cc_cid');

            /** @var Paggi_Payment_Model_Card $card */
            $description = substr($ccNumber, 0, 6) . 'XXXXXX' . substr($ccNumber, -4, 4);

            /** @var Paggi_Payment_Model_Resource_Card_Collection $cards */
            $cards = Mage::getModel('paggi/card')->getCollection()
                ->addFieldToFilter('description', $description)
                ->addFieldToFilter('customer_id', $customerId)
            ;

            if (!$cards->count()) {

                $ccData = new stdClass();
                $ccData->cvc = $ccCid;
                $ccData->year = $ccExpYear;
                $ccData->month = $ccExpMonth;
                $ccData->number = $ccNumber;
                $ccData->holder = $firstname . ' ' . $lastname;
                $ccData->document = $this->getHelper()->digits($payment->getAdditionalInformation('cpf_cnpj'));

                $endpoint = $this->getHelper()->getEndpoint('cards');
                $response = $this->getService()->doPostRequest($endpoint, json_encode($ccData));
                $responseData = json_decode($response->getBody());

                if (property_exists($responseData, 'id')) {
                    $token = $responseData->id;
                    $description = $responseData->masked_number;

                    /** @var Paggi_Payment_Model_Card $card */
                    $card = Mage::getModel('paggi/card');
                    $card->setData(
                        array(
                            'customer_id' => $customerId,
                            'token' => $token,
                            'description' => $description
                        )
                    );
                    $card->save();
                }

                $this->getHelper()->saveTransaction($ccData, $responseData);

            }
        } catch (Exception $e) {
            Mage::logException($e);
            return false;
        }

        return true;
    }

    /**
     * @param Mage_Sales_Model_Order $order
     * @param string $paggiOrderId
     * @param $amount
     * @return array|bool
     */
    public function capture($order, $paggiOrderId, $amount)
    {
        $endpoint = $this->getHelper()->getEndpoint('capture', $paggiOrderId);
        $response = $this->getService()->doPutRequest($endpoint);
        $responseData = json_decode($response->getBody());

        $this->getHelper()->saveTransaction($endpoint, $responseData, $order->getIncrementId());

        if (!property_exists($responseData, 'id')) {
            $error = $this->getHelper()->__('Error capturing order %s', $order->getIncrementId());
            Mage::throwException($error);
        }

        return $responseData;
    }

    /**
     * @param Mage_Sales_Model_Order $order
     * @param string $paggiOrderId
     * @param $amount
     * @return bool
     */
    public function refund($order, $paggiOrderId, $amount)
    {
        $amount = number_format($amount, 2, '.', '');
        $endpoint = $this->getHelper()->getEndpoint('void', $paggiOrderId);

        $response = $this->getService()->doPostRequest($endpoint);
        $responseData = json_decode($response->getBody());

        $this->getHelper()->saveTransaction($endpoint, $responseData, $order->getIncrementId());

        if (!property_exists($responseData, 'id')) {
            $error = $this->getHelper()->__('Error voiding order %s', $order->getIncrementId());
            Mage::throwException($error);
        }

        return $responseData;

    }

    /**
     * @param Mage_Sales_Model_Order $order
     * @param boolean $transactionId
     * @return array|false
     */
    public function checkOrders(Mage_Sales_Model_Order $order, $transactionId = false, $logFile = false)
    {
        try {
            /** @var Mage_Sales_Model_Order_Payment $payment */
            $payment = $order->getPayment();
            if ($order->getPayment()->getMethod() == 'paggi_checkout2') {
                $data = array(
                    "payOrderId" => $payment->getAdditionalInformation('pay_order_id'),
                );
                $this->getService()->pullPaymentOrder($data);
            } else {
                if ($transactionId) {
                    $data = array(
                        "transactionID" => $payment->getAdditionalInformation('transaction_id'),
                    );
                } else {
                    $data = array(
                        "orderID" => $payment->getAdditionalInformation('order_id'),
                    );
                }
                $this->getService()->pullReport($data);
            }

            $response = $this->getService()->response;

            if ($logFile) {
                $this->getHelper()->log($this->getService()->xmlRequest, $logFile);
                $this->getHelper()->log($this->getService()->xmlResponse, $logFile);
            }

            return $response;

        } catch (Exception $e) {
            Mage::logException($e);
        }

        return false;
    }

    /**
     * @param string $orderId
     * @param string $pageToken
     * @param string $pageNumber
     * @return array|false
     */
    public function pullReportByOrderId($orderId, $pageToken = null, $pageNumber = null)
    {
        try {
            $data = array(
                "orderID" =>$orderId,
            );

            if ($pageToken) {
                $data['pageToken'] = $pageToken;
            }

            if ($pageNumber) {
                $data['pageNumber'] = $pageNumber;
            }
            $this->getService()->pullReport($data);
            $response = $this->getService()->response;
            return $response;

        } catch (Exception $e) {
            Mage::logException($e);
        }

        return false;
    }

    /**
     * @return Paggi_Payment_Helper_Data|Mage_Core_Helper_Abstract
     */
    public function getHelper()
    {
        if (!$this->_helper) {
            /** @var Paggi_Payment_Helper_Data _helper */
            $this->_helper = Mage::helper('paggi');
        }

        return $this->_helper;
    }

    /**
     * @return Paggi_Payment_Helper_Customer|Mage_Core_Helper_Abstract
     */
    public function getCustomerHelper()
    {
        if (!$this->_customerHelper) {
            /** @var Paggi_Payment_Helper_Customer _customerHelper */
            $this->_customerHelper = Mage::helper('paggi/customer');
        }

        return $this->_customerHelper;
    }

    /**
     * @return Paggi_Payment_Helper_Order|Mage_Core_Helper_Abstract
     */
    public function getOrderHelper()
    {
        if (!$this->_orderHelper) {
            /** @var Paggi_Payment_Helper_Order _orderHelper */
            $this->_orderHelper = Mage::helper('paggi/order');
        }

        return $this->_orderHelper;
    }

    /**
     * @return Paggi_Payment_Helper_Customer|Mage_Core_Helper_Abstract
     */
    public function getPaymentHelper()
    {
        if (!$this->_paymentHelper) {
            /** @var Paggi_Payment_Helper_Payment _paymentHelper */
            $this->_paymentHelper = Mage::helper('paggi/payment');
        }

        return $this->_paymentHelper;
    }
}
