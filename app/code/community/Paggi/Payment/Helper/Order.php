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
 * to contato@paggi.com.br so we can send you a copy immediately.
 *
 * @category   Paggi
 * @package    Paggi_Payment
 * @author        Thiago Contardi <thiago@contardi.com.br>
 * @license    http://opensource.org/licenses/osl-3.0.php  Open Software License (OSL 3.0)
 */
class Paggi_Payment_Helper_Order extends Mage_Core_Helper_Data
{
    protected $helper;

    /**
     * @param Mage_Sales_Model_Order $order
     * @param null $transactionId
     */
    public function createInvoice(Mage_Sales_Model_Order $order, $transactionId = null, $code = 'paggi_cc')
    {
        $helper = $this->getHelper();
        if ($order->canInvoice()) {

            /** @var Mage_Sales_Model_Order_Payment $payment */
            $payment = $order->getPayment();
            if (!$transactionId) {
                $transactionId = $payment->getAdditionalInformation('transaction_id');
            }

            /** @var Mage_Sales_Model_Order_Invoice $invoice */
            $invoice = Mage::getModel('sales/service_order', $order)->prepareInvoice();

            $invoice->setRequestedCaptureCase(Mage_Sales_Model_Order_Invoice::CAPTURE_OFFLINE);
            $invoice->register();
            $invoice->sendEmail(true);
            $invoice->setEmailSent(true);
            $invoice->getOrder()->setCustomerNoteNotify(true);
            $invoice->getOrder()->setIsInProcess(true);
            $invoice->setTransactionId($transactionId);
            $invoice->setCanVoidFlag(true);

            $payment->setAdditionalInformation('captured', true);
            $payment->setAdditionalInformation('captured_date', Mage::getSingleton('core/date')->gmtDate());

            $transactionSave = Mage::getModel('core/resource_transaction')
                ->addObject($invoice)
                ->addObject($payment)
                ->addObject($invoice->getOrder());
            $transactionSave->save();

            $helper->log('Order: ' . $order->getIncrementId() . " - invoice created");

            $status = $this->getHelper()->getConfig('captured_order_status', $code);
            if ($status) {
                $message = Mage::helper('paggi')->__('The payment was confirmed - Transaction ID: %s', (string)$transactionId);
                $order->addStatusHistoryComment($message, $status)->setIsCustomerNotified(true);
                $order->save();
            }

        }

    }

    /**
     * @param Mage_Sales_Model_Order $order
     * @param null $transactionId
     */
    public function cancelOrder(Mage_Sales_Model_Order $order)
    {
        /** @var Mage_Sales_Model_Order_Payment $payment */
        $payment = $order->getPayment();
        /** @var Mage_Sales_Model_Order $order */
        $order = $payment->getOrder();

        $autoReturnOption = Mage::getStoreConfig("cataloginventory/item_options/auto_return");
        if (!$order->canCancel() && !$order->canCreditmemo()) {
            if ($order->getState() != Mage_Sales_Model_Order::STATE_CANCELED) {
                $payment->setIsTransactionDenied(true);
                $payment->setAdditionalInformation('cancelled', true);
                foreach ($order->getInvoiceCollection() as $invoice) {
                    /** @var $invoice Mage_Sales_Model_Order_Invoice */
                    $invoice = $invoice->load($invoice->getId()); // to make sure all data will properly load (maybe not required)
                    if ($invoice) {
                        $invoice->cancel();
                        $order->addRelatedObject($invoice);
                        $invoice->save();
                    }
                    $message = Mage::helper('sales')->__('Registered update about denied payment.');
                    $order->registerCancellation($message, false);
                }
                $order->save();
            }
        } else if ($order->canCancel()) {
            $order->cancel();
            $order->addStatusHistoryComment('Order cancelled at Paggi', false);
            $payment->setAdditionalInformation('cancelled', true);
            $payment->save;
            $order->save();
        } else if ($order->canCreditmemo()) {
            /** @var $service  Mage_Sales_Model_Service_Order */
            $service = Mage::getModel('sales/service_order', $order);

            /** @var Mage_Sales_Model_Order_Creditmemo $creditmemo */
            $creditmemo = $service->prepareCreditmemo();
            $creditmemo->setOfflineRequested(true);
            $creditmemo->register();

            foreach ($creditmemo->getAllItems() as $creditmemoItem) {
                if ($autoReturnOption) {
                    $creditmemoItem->setBackToStock(true);
                }
            }

            $payment->setAdditionalInformation('cancelled', true);

            /** @var Mage_Core_Model_Resource_Transaction $transaction */
            $transaction = Mage::getModel('core/resource_transaction')
                ->addObject($creditmemo)
                ->addObject($payment)
                ->addObject($creditmemo->getOrder());

            if ($creditmemo->getInvoice()) {
                $transaction->addObject($creditmemo->getInvoice());
            }

            $transaction->save();
        }

        $payment->save();

    }

    /**
     * @param Mage_Sales_Model_Order $order
     * @param stdClass $record
     * @return string
     */
    public function updatePayment($order, $response)
    {
        $helper = $this->getHelper();
        $message = $helper->__('Order synchronized with status <strong>%s</strong>', $response->status);

        try {
            if (property_exists($response, 'status')) {
                $payment = $order->getPayment();
                $currentStatus = $payment->getAdditionalInformation('status');

                if ($currentStatus != $response->status) {
                    if ($response->status == 'captured') {
                        $this->createInvoice($order);
                        $message = $helper->__('Order approved');
                    } else if ($response->status == 'cancelled') {
                        $this->cancelOrder($order);
                        $message = $helper->__('Order cancelled');
                    }
                    $payment->setAdditionalInformation('status', $response->status);
                    $payment->save();
                }

            }
        } catch (Exception $e) {
            Mage::logException($e);
        }

        return $message;
    }

    /**
     * Get the assigned state of an order status
     *
     * @param string order_status
     * @return string
     */
    public function getAssignedState($status)
    {
        /** @var Mage_Sales_Model_Resource_Order_Status_Collection $item */
        $item = Mage::getResourceModel('sales/order_status_collection')
            ->joinStates()
            ->addFieldToFilter('main_table.status', $status);

        /** @var Mage_Sales_Model_Order_Status $status */
        $status = $item->getFirstItem();

        return $status->getState();
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

}
