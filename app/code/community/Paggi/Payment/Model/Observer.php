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
class Paggi_Payment_Model_Observer extends Varien_Event_Observer
{
    protected $helper;
    protected $helperOrder;

    /**
     * @param Varien_Event_Observer $observer
     * @return $this
     */
    public function salesOrderPaymentPlaceEnd(Varien_Event_Observer $observer)
    {
        try {
            /** @var Mage_Sales_Model_Order_Payment $payment */
            $payment = $observer->getEvent()->getPayment();
            $methodCode = $payment->getMethod();
            $status = false;
            $message = '';

            if ($methodCode == 'paggi_cc') {
                $tid = $payment->getAdditionalInformation('transaction_id');
                $capture = $this->getHelper()->getConfig('capture', 'paggi_cc');

                /** @var Mage_Sales_Model_Order $order */
                $order = $payment->getOrder();

                if ($capture) {
                    if ($payment->getAdditionalInformation('status') == 'captured') {
                        $status = $this->getHelper()->getConfig('captured_order_status', 'paggi_cc');
                        $this->getOrderHelper()->createInvoice($order);
                    }
                } else {
                    if ($payment->getAdditionalInformation('status') == 'authorized') {
                        $status = $this->getHelper()->getConfig('authorized_order_status', 'paggi_cc');
                        $message = $this->getHelper()->__('The payment was authorized - Transaction ID: %s', (string)$tid);
                    }
                }

                if ($status) {
                    $state = $this->getOrderHelper()->getAssignedState($status);
                    $order->setState($state, $status, $message, true);
                    $order->save();
                }

            }
        } catch (Exception $e) {
            Mage::logException($e);
        }

        return $this;
    }

    /**
     * @param Varien_Event_Observer $observer
     * @return $this
     */
    public function salesOrderPaymentCancel(Varien_Event_Observer $observer)
    {
        try {
            /** @var Mage_Sales_Model_Order $order */
            $order = $observer->getEvent()->getPayment()->getOrder();
            /** @var Mage_Sales_Model_Order_Payment $payment */
            $payment = $order->getPayment();

            $methodCode = $payment->getMethod();
            if ($methodCode == 'paggi_cc') {
                $cancelled = $payment->getAdditionalInformation('cancelled');
                if (!$cancelled) {

                    $paggiOrderId = $payment->getAdditionalInformation('order_id');
                    $this->getHelper()->getApi()->refund($order, $paggiOrderId);

                    $payment->setAdditionalInformation('cancelled', true);
                    $payment->setAdditionalInformation('cancelled_date', Mage::getSingleton('core/date')->gmtDate());
                    $payment->save();
                }
            }

        } catch (Exception $e) {
            Mage::logException($e);
            Mage::throwException($e->getMessage());
        }

        return $this;
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
