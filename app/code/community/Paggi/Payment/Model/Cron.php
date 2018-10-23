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
class Paggi_Payment_Model_Cron
{
    CONST CRON_FILE = 'paggi-cron.log';

    protected $helper;
    protected $helperOrder;

    public function queryPayments()
    {
        $this->getHelper()->log('STARTING CRON', self::CRON_FILE);
        $ninStatuses = array(
            'complete',
            'canceled',
            'closed',
            'holded'
        );

        $date = new DateTime('-15 DAYS'); // first argument uses strtotime parsing
        $fromDate = $date->format('Y-m-d');

        /** @var Mage_Sales_Model_Resource_Order_Collection $collection */
        $collection = Mage::getModel('sales/order')->getCollection()
            ->join(
                array('payment' => 'sales/order_payment'),
                'main_table.entity_id=payment.parent_id',
                array('payment_method' => 'payment.method')
            )
            ->addFieldToFilter('payment.method', array('like' => 'paggi_%'))
            ->addFieldToFilter('state', array('nin' => array($ninStatuses)))
            ->addFieldToFilter('created_at', array('gt' => $fromDate))
        ;

        /** @var Mage_Sales_Model_Order $order */
        foreach ($collection as $order) {

            if ($order->getId()) {

                /** @var Mage_Sales_Model_Order $order */
                $order = Mage::getModel('sales/order')->load($order->getId());
                $payment = $order->getPayment();
                $paggiOrderId = $payment->getAdditionalInformation('order_id');
                $this->getHelper()->log('getOrder ' . $order->getIncrementId(), self::CRON_FILE);

                $response = $this->getHelper()->getApi()->getOrder($order, $paggiOrderId);
                if ($response) {
                    $this->getOrderHelper()->updatePayment($order, $response);
                }

            }

        }
        $this->getHelper()->log('ENDING CRON', self::CRON_FILE);
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
