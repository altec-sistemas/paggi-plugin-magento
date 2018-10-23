<?php

/**
 * Bizcommerce Desenvolvimento de Plataformas Digitais Ltda - Epp
 *
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
class Paggi_Payment_Model_Method_Cc
    extends Mage_Payment_Model_Method_Abstract
{
    /**
     * unique internal payment method identifier
     * @var string [a-z0-9_]
     */
    protected $_code = 'paggi_cc';
    protected $_canSaveCc = true;


    protected $_canUseInternal              = true;

    protected $_isGateway                   = true;
    protected $_canOrder                    = true;
    protected $_canAuthorize                = true;
    protected $_canCapture                  = true;
    protected $_canCapturePartial           = false;
    protected $_canCaptureOnce              = true;
    protected $_canRefund                   = true;
    protected $_canRefundInvoicePartial     = false;
    protected $_canVoid                     = true;
    protected $_canUseCheckout              = true;
    protected $_canUseForMultishipping      = true;
    protected $_canFetchTransactionInfo     = true;
    protected $_canReviewPayment            = true;
    protected $_canCreateBillingAgreement   = false;
    protected $_canManageRecurringProfiles = false;

    protected $_formBlockType = 'paggi/form_cc';
    protected $_infoBlockType = 'paggi/info_cc';

    protected $helper;
    protected $helperOrder;

    /**
     * @param mixed $data
     * @return $this
     */
    public function assignData($data)
    {
        if (!($data instanceof Varien_Object)) {
            $data = new Varien_Object($data);
        }

        /** @var Mage_Payment_Model_Info $info */
        $info = $this->getInfoInstance();

        $ccCid = preg_replace("/[^0-9]/", '', $data->getCcCid());
        $installments = $data->getInstallments();
        $useSavedCard = $data->getUseSavedCard();
        $grandTotal = $data->getBaseGrandTotal();

        $cpfCnpj = $data->getCpfCnpj();
        if (!$this->getHelper()->getConfig('show_taxvat_field')) {
            $cpfCnpj = $this->getHelper()->getTaxvatValue();
        }

        if ($useSavedCard) {
            /** @var Paggi_Payment_Model_Card $card */
            $card = $this->getHelper()->getSavedCard($data->getCcToken());
            $info->setAdditionalInformation('cc_token', $card->getToken());
            $info->setAdditionalInformation('cc_description', $card->getDescription());
            $info->setAdditionalInformation('cc_customer_id_paggi', $card->getCustomerIdPaggi());
            $ccType = $card->getBrand();
            $ccCid = $data->getCcCidSc();
        } else {

            $ccType = $data->getCcType();
            $ccOwner = $data->getCcOwner();
            $ccNumber = preg_replace("/[^0-9]/", '', $data->getCcNumber());
            $ccNumberEnc = $info->encrypt($ccNumber);
            $ccLast4 = substr($ccNumber, -4);
            $ccExpMonth = str_pad($data->getCcExpMonth(), 2, '0', STR_PAD_LEFT);
            $ccExpYear = $data->getCcExpYear();
            $saveCard = $data->getSaveCard();

            $info->setCcOwner($ccOwner);
            $info->setCcNumber($ccNumber);
            $info->setCcExpMonth($ccExpMonth);
            $info->setCcExpYear($ccExpYear);
            $info->setCcNumberEnc($ccNumberEnc);
            $info->setCcLast4($ccLast4);

            $info->setAdditionalInformation('cc_token', false);
            $info->setAdditionalInformation('cc_description', false);
            $info->setAdditionalInformation('cc_customer_id_paggi', false);
            $info->setAdditionalInformation('cc_save_card', $saveCard);
        }

        $info->setAdditionalInformation('cpf_cnpj', $cpfCnpj);
        $interestRate = $this->getHelper()->getConfig('interest_rate', $this->getCode());
        $installmentsWithoutInterest = $this->getHelper()->getConfig('installments_without_interest_rate', $this->getCode());
        if ($installmentsWithoutInterest >= $installments) {
            $interestRate = null;
        }

        if ($installments > 1) {
            $installmentsValue = $this->getHelper()->getInstallmentValue($grandTotal, $installments);
            $totalOrderWithInterest = $installmentsValue * $installments;
            $interestValue = $totalOrderWithInterest - $grandTotal;
            $info->setAdditionalInformation('cc_interest_amount', $interestValue);
            $info->setAdditionalInformation('cc_total_with_interest', $totalOrderWithInterest);
            $info->setAdditionalInformation('cc_interest_value', $this->getHelper()->getInstallmentValue($grandTotal, $installments));
        }

        $info->setAdditionalInformation('cc_has_interest', ($interestRate) ? true : false);
        $info->setAdditionalInformation('cc_interest_rate', $interestRate);
        $info->setAdditionalInformation('cc_installment_value', $this->getHelper()->getInstallmentValue($grandTotal, $installments));
        $info->setAdditionalInformation('cc_installments', $installments);
        $info->setAdditionalInformation('installments', $installments);
        $info->setAdditionalInformation('base_grand_total', $grandTotal);

        $info->setCcType($ccType);
        $info->setCcInstallments($installments);

        Mage::unregister('paggi_cc_cid');
        Mage::register('paggi_cc_cid', $ccCid);

        return $this;
    }

    /**
     * @param Mage_Sales_Model_Order_Payment $payment
     * @param float $amount
     * @return $this
     * @throws Mage_Core_Exception
     */
    public function order(Varien_Object $payment, $amount)
    {
        $errors = null;
        try {
            /** @var Paggi_Payment_Model_Api $api */
            $api = $this->getHelper()->getApi();
            $response = $api->ccMethod($this, $payment, $amount);
            if ($response) {
                $responseData = Mage::helper('core')->jsonDecode($response->getBody(), false);

                if (property_exists($responseData, 'id')) {

                    $tid = $responseData->id;
                    $payment->setCcTransId($tid);
                    $payment->setLastTransId($tid);

                    $payment = $this->setAdditionalInfo($payment, $responseData);

                    if ($this->getHelper()->getIsDeniedState($responseData->status)) {

                        if ($this->getHelper()->getConfig('stop_processing')) {
                            $errors = $this->getHelper()->__('The transaction wasn\'t authorized by the issuer, please check your data and try again');
                            Mage::throwException($errors);
                        }
                        $payment->setSkipOrderProcessing(true);
                    }
                } else {
                    Mage::throwException(Mage::helper('payment')->__('There was an error processing your request. Please contact us or try again later.'));
                }
            } else {
                Mage::throwException(Mage::helper('payment')->__('There was an error processing your request. Please contact us or try again later.'));
            }

        } catch (Exception $e) {
            Mage::getSingleton('checkout/session')->getQuote()->setReservedOrderId(null);
            Mage::logException($e);
            $this->getHelper()->log($e->getMessage());
            $exception = $errors ?: Mage::helper('payment')->__('There was an error processing your request. Please contact us or try again later.');
            Mage::throwException($exception);
        }

        return $this;
    }

    /**
     * @param Mage_Sales_Model_Order_Payment $payment
     * @return Mage_Sales_Model_Order_Payment
     */
    protected function setAdditionalInfo(Mage_Sales_Model_Order_Payment $payment, $response)
    {
        //Set Transaction Id
        if (property_exists($response, 'id')) {
            $paggiOrderId = $response->id;
            $payment->setAdditionalInformation('order_id', $paggiOrderId);
        }

        if (property_exists($response, 'external_identifier')) {
            $payment->setAdditionalInformation('external_identifier', $response->external_identifier);
        }

        if (property_exists($response, 'status')) {
            $payment->setAdditionalInformation('status', $response->status);
        }

        if (property_exists($response, 'charges')) {

            foreach ($response->charges as $i => $charge) {
                $tid = $charge->id;
                if ($i == 0) {
                    $payment->setCcTransId($tid);
                    $payment->setTransactionId($tid);
                    $payment->setAdditionalInformation('transaction_id', $tid);
                }

                $payment->setAdditionalInformation('charge_id_' . $i , $tid);
                $payment->setAdditionalInformation('charge_amount_' . $i , $charge->amount);
                $payment->setLastTransId($tid);
            }
        }

        return $payment;
    }

    /**
     * @param Mage_Sales_Model_Order_Payment $payment
     * @param float $amount
     * @return Mage_Payment_Model_Method_Abstract
     */
    public function capture(Varien_Object $payment, $amount)
    {
        if (!$payment->getAdditionalInformation('recurring_profile')) {

            if (!$amount) {
                Mage::throwException($this->getHelper()->__('There was an error capturing your order at Paggi'));
            }

            /** @var Mage_Sales_Model_Order $order */
            $order = $payment->getOrder();

            if ($payment->canCapture()) {
                $paggiOrderId = $payment->getAdditionalInformation('order_id');

                /** @var Paggi_Payment_Model_Api $this ->getOrderModel() */
                $response = $this->getHelper()->getApi()->capture($order, $paggiOrderId, $amount);

                if (
                    property_exists($response, 'id')
                    && $response->id
                    && $response->status == 'captured'
                ) {
                    $transactionId = $response->id;
                    $payment->setAdditionalInformation('captured', true);
                    $payment->setAdditionalInformation('captured_date', date('Y-m-d'));
                    $payment->setParentTransactionId($transactionId);
                    $payment->save();
                } else {
                    Mage::throwException($this->getHelper()->__('There was an error capturing your order at Paggi'));
                }
            }
        }

        return parent::capture($payment, $amount);
    }

    /**
     * @param Mage_Sales_Model_Order_Payment $payment
     * @param float $amount
     * @return Mage_Payment_Model_Abstract
     */
    public function refund(Varien_Object $payment, $amount)
    {
        if (!$payment->getAdditionalInformation('recurring_profile')) {
            if ($payment->canRefund()) {

                /** @var Mage_Sales_Model_Order $order */
                $order = $payment->getOrder();
                $this->cancelOrder($order, $payment);

            }
        }
        return parent::refund($payment, $amount);
    }

    /**
     * @param Mage_Sales_Model_Order_Payment $payment
     * @return Mage_Payment_Model_Abstract
     */
    public function void(Varien_Object $payment)
    {
        if (!$payment->getAdditionalInformation('recurring_profile')) {
            if ($payment->canVoid($payment)) {

                /** @var Mage_Sales_Model_Order $order */
                $order = $payment->getOrder();
                $this->cancelOrder($order, $payment);

            }
        }
        return parent::void($payment);
    }

    /**
     * @param Mage_Sales_Model_Order $order
     * @param Mage_Sales_Model_Order_Payment $payment
     * @throws Mage_Core_Exception
     */
    protected function cancelOrder($order, $payment)
    {
        $paggiOrderId = $payment->getAdditionalInformation('order_id');
        $response = $this->getHelper()->getApi()->refund($order, $paggiOrderId);

        if (
            property_exists($response, 'id')
            && $response->id
            && $response->status == 'cancelled'
        ) {
            $payment->setAdditionalInformation('cancelled', true);
            $payment->setAdditionalInformation('cancelled_date', Mage::getSingleton('core/date')->gmtDate());
            $payment->save();
        } else {
            Mage::throwException($this->getHelper()->__('There was an error capturing your order at Paggi'));
        }

        return true;
    }

    /**
     * @param Mage_Sales_Model_Order_Payment $payment
     * @return Mage_Payment_Model_Abstract
     */
    public function cancel(Varien_Object $payment)
    {
        if (!$payment->getAdditionalInformation('recurring_profile')) {
            /** @var Mage_Sales_Model_Order $order */
            $order = $payment->getOrder();
            $paggiOrderId = $payment->getAdditionalInformation('order_id');
            $this->getHelper()->getApi()->refund($order, $paggiOrderId);
        }

        return parent::cancel($payment);
    }

    public function isAvailable($quote = null)
    {
        $methodEnabled = $this->getHelper()->getMethodsEnabled();
        if (empty($methodEnabled)) {
            return false;
        }

        return parent::isAvailable($quote);
    }

    /**
     * @return Paggi_Payment_Helper_Data|Mage_Core_Helper_Abstract
     */
    protected function getHelper()
    {
        if (!$this->helper) {
            $this->helper = Mage::helper('paggi');
        }
        return $this->helper;
    }

    /**
     * @return Paggi_Payment_Helper_Order|Mage_Core_Helper_Abstract
     */
    protected function getOrderHelper()
    {
        if (!$this->helperOrder) {
            $this->helperOrder = Mage::helper('paggi/order');
        }
        return $this->helperOrder;
    }
}
