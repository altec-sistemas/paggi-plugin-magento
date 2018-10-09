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
class Paggi_Payment_Adminhtml_ReportController
    extends Mage_Adminhtml_Controller_Action
{

    protected $_helper;
    protected $_orderHelper;

    public function preDispatch()
    {
        parent::preDispatch();
    }

    protected function _isAllowed()
    {
        return Mage::getSingleton('admin/session')->isAllowed('admin/sales/order');
    }

    public function indexAction()
    {
        $this->loadLayout();
        $orderId = $this->getRequest()->getParam('order_id');
        /** @var Mage_Core_Block_Template $block */
        $block = Mage::app()->getLayout()
            ->createBlock('core/template')
            ->setOrderId($orderId)
            ->setTemplate('paggi/info/report/list.phtml');
        if ($block) {
            $this->getResponse()->setBody($block->toHtml());
        }
    }

    public function syncAction()
    {
        try {
            $orderId = $this->getRequest()->getParam('order_id');

            if ($orderId) {
                /** @var Mage_Sales_Model_Order $order */
                $order = Mage::getModel('sales/order')->load($orderId);

                if ($order->getId()) {
                    /** @var Paggi_Payment_Helper_Data $helper */
                    $helper = $this->_getHelper();

                    $response = $helper->getApi()->pullReport($order);
                    $record = isset($response['records'][0]) ? $response['records'][0] : null;
                    if ($record) {
                        $this->getOrderHelper()->updatePayment($order, $record);
                    }
                }

            }
        } catch (Exception $e) {
            Mage::logException($e);
        }

        $this->_redirectReferer();
        return $this;
    }


    /**
     * @return Paggi_Payment_Helper_Data|Mage_Core_Helper_Abstract
     */
    protected function _getHelper()
    {
        if (!$this->_helper) {
            /** @var Paggi_Payment_Helper_Data _helper */
            $this->_helper = Mage::helper('paggi');
        }

        return $this->_helper;
    }

    /**
     * @return Paggi_Payment_Helper_Order|Mage_Core_Helper_Abstract
     */
    protected function getOrderHelper()
    {
        if (!$this->_orderHelper) {
            /** @var Paggi_Payment_Helper_Order _orderHelper */
            $this->_orderHelper = Mage::helper('paggi/order');
        }

        return $this->_orderHelper;
    }


}
