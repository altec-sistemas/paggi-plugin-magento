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

class Paggi_Payment_Helper_Customer extends Mage_Core_Helper_Data
{
    protected $_helper;

    /**
     * @param Mage_Sales_Model_Order|Mage_Sales_Model_Quote $order
     * @param Mage_Sales_Model_Order_Payment|Mage_Payment_Model_Info $payment
     * @param string $type
     * @return array
     */
    public function getCustomerData($order, $payment)
    {
        $data = array();

        return $data;
    }

    /**
     * @param $email
     * @return false|Mage_Core_Model_Abstract
     */
    public function getCustomerByEmail($email)
    {
        $customer = Mage::getModel("customer/customer");
        $customer->setWebsiteId(Mage::app()->getStore()->getWebsiteId());
        $customer->loadByEmail($email);

        return $customer;
    }

    /**
     * @return Paggi_Payment_Helper_Data|Mage_Core_Helper_Abstract
     */
    public function _getHelper()
    {
        if (!$this->_helper) {
            /** @var Paggi_Payment_Helper_Data _helper */
            $this->_helper = Mage::helper('paggi');
        }

        return $this->_helper;
    }
}
