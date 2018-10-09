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
    protected $_helper;
    protected $_helperOrder;

    /**
     * @param Varien_Event_Observer $observer
     * @return $this
     */
    public function salesOrderPaymentPlaceEnd(Varien_Event_Observer $observer)
    {
        /** @var Mage_Sales_Model_Order_Payment $payment */
        $payment = $observer->getEvent()->getPayment();
        $availableMethods = $this->_getHelper()->getAvailableMethods();
        $status = false;

        $methodCode = $payment->getMethod();
        $message = '';
        if (in_array($methodCode, $availableMethods)) {
            /** @var Mage_Sales_Model_Order $order */
            $order = $payment->getOrder();

            $responseCode = $payment->getAdditionalInformation('response_code');
            $responseMessage = $payment->getAdditionalInformation('response_message');
            $tid = $payment->getAdditionalInformation('transaction_id');

            $canCancel = $this->_getHelper()->getConfig('automatically_cancel');
            $canInvoice = false;

            // Altera o status do pedido para o valor correto
            if ($responseCode == 0) {
                if ($methodCode == 'paggi_cc') {
                    $paymentAction = $this->_getHelper()->getConfig('cc_payment_action', 'paggi_cc');
                    $authStatus = $this->_getHelper()->getConfig('authorized_order_status', 'paggi_cc');
                    $saleStatus = $this->_getHelper()->getConfig('captured_order_status', 'paggi_cc');
                    $message = Mage::helper('paggi')->__('The payment was authorized - Transaction ID: %s', (string)$tid);
                    if ($paymentAction == 'sale') {
                        $canInvoice = true;
                        $status = $saleStatus;
                    } else {
                        if ($responseMessage == 'CAPTURED') {
                            $canInvoice = $this->_getHelper()->getConfig('capture_on_low_risk');
                        }
                        $status = $authStatus;
                    }
                }
            } elseif ($responseCode != 5) {
                $message = Mage::helper('paggi')->__('The payment was\'t authorized - Transaction ID: %s', (string)$tid);
                //If can cancel, return order
                if ($canCancel) {
                    if ($order->canCancel()) {
                        $order->cancel();
                    }
                }
            }

            if ($canInvoice) {
                $this->getOrderHelper()->createInvoice($order, $tid);
            }

            if ($message) {
                $order->addStatusHistoryComment($message, $status)->setIsCustomerNotified(true);
                $order->save();
            }
        }

        return $this;
    }

    public function salesOrderPlaceAfter(Varien_Event_Observer $observer)
    {
        try {

            $this->sendOrderToFraudAnalysis($observer);

            /** @var $order Mage_Sales_Model_Order */
            $order = $observer->getEvent()->getOrder();

            /** @var array $items */
            $items = $order->getAllItems();

            /** @var Mage_Sales_Model_Order_Item $item */
            foreach ($items as $item) {

                $seller = $this->getOrderHelper()->getSellerByProductId($item->getProduct()->getId());
                if ($seller) {
                    $installments = $order->getPayment()->getAdditionalInformation('installments')
                        ? $order->getPayment()->getAdditionalInformation('installments')
                        : 1;
                    $item->setData('paggi_seller_id', $seller->getId());
                    $item->setData('paggi_seller_mdr', $seller->getData('seller_mdr'));
                    $item->setData('paggi_seller_installments', $installments);
                    $item->save();
                }

            }


        } catch(Exception $e) {
            Mage::logException($e);
        }

        return $this;
    }

    /**
     * @param Varien_Event_Observer $observer
     * @return $this
     */
    public function salesOrderPaymentCapture(Varien_Event_Observer $observer)
    {
        /** @var Mage_Sales_Model_Order_Payment $payment */
        $payment = $observer->getEvent()->getPayment();
        $methodCode = $payment->getMethod();
        if ($methodCode == 'paggi_cc') {
            /** @var Mage_Sales_Model_Order_Invoice $invoice */
            $invoice = $observer->getEvent()->getInvoice();
            $invoice->setTransactionId($payment->getAdditionalInformation('transaction_id'));
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
                    $captured = $payment->getAdditionalInformation('captured');
                    $paggiOrderId = $payment->getAdditionalInformation('order_id');
                    $transactionId = $payment->getAdditionalInformation('transaction_id');
                    if ($captured) {
                        $amount = ($order->getBaseTotalInvoiced()) ? $order->getBaseTotalInvoiced() : $order->getBaseGrandTotal();

                        $capturedDate = $payment->getAdditionalInformation('captured_date');
                        $currentDate = new DateTime();
                        $currentDate = $currentDate->format('Y-m-d');

                        if ($captured && $capturedDate == $currentDate) {
                            $this->_getHelper()->getApi()->void($order, $paggiOrderId);
                        } else {
                            $this->_getHelper()->getApi()->refund($order, $paggiOrderId, $amount);
                        }
                    } else {
                        $this->_getHelper()->getApi()->void($order, $transactionId);
                    }

                    $payment->setAdditionalInformation('cancelled', true);
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
    protected function _getHelper()
    {
        if (!$this->_helper) {
            $this->_helper = Mage::helper('paggi');
        }

        return $this->_helper;
    }

    /**
     * @return Paggi_Payment_Helper_Order|Mage_Core_Helper_Abstract
     */
    protected function getOrderHelper()
    {
        if (!$this->_helperOrder) {
            $this->_helperOrder = Mage::helper('paggi/order');
        }

        return $this->_helperOrder;
    }
}
