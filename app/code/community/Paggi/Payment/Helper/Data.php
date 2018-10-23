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
class Paggi_Payment_Helper_Data extends Mage_Core_Helper_Data
{
    const LOG_FILE = 'paggi.log';
    const DEFAULT_IP = '127.0.0.1';

    protected $_api = null;

    protected $_interestMethods = array(
        'paggi_cc'
    );

    protected $_availableMethods = array(
        'paggi_cc'
    );

    protected $_transactionStates = array(
        'authorized' => array(
            'type' => 'approved',
            'title' => 'Autorizado',
            'description' => 'Pedido autorizado mas ainda não capturado'
        ),
        'pending_authorization' => array(
            'type' => 'pending',
            'title' => 'Autorizando',
            'description' => 'Pedido com a autorização pendente. Você será notificado por webhook quando ela for processada.'
        ),
        'captured' => array(
            'type' => 'approved',
            'title' => 'Capturado',
            'description' => 'Pedido autorizado e capturado'
        ),
        'pending_capture' => array(
            'type' => 'pending',
            'title' => 'Capturando',
            'description' => 'Pedido autorizado e com a captura pendente. Você será notificado por webhook quando ela for processada.'
        ),
        'not_cleared' => array(
            'type' => 'not_approved',
            'title' => 'Com risco',
            'description' => 'Pedido negado pelo antifraude'
        ),
        'declined' => array(
            'type' => 'not_approved',
            'title' => 'Negado',
            'description' => 'Pedido negado pelo emissor do cartão'
        ),
        'cancelled' => array(
            'type' => 'not_approved',
            'title' => 'Cancelado',
            'description' => 'Pedido cancelado'
        ),
        'pending_cancellation' => array(
            'type' => 'not_approved',
            'title' => 'Cancelando',
            'description' => 'Pedido com o cancelamento pendente. Você será notificado por webhook quando ela for processada.'
        ),
    );

    public function getMethodsEnabled($path = 'paggi_cc')
    {
        /** @var Paggi_Payment_Model_Source_Cctype $methods */
        $methods = Mage::getModel('paggi/source_cctype');
        $allMethods = $methods->toOptionArray();
        $allowedMethods = ($allMethods) ? $allMethods  : array(
        );
        $allowedBrands = explode(',', $this->getConfig('allowed_brands', $path));
        $i = 0;
        foreach ($allMethods as $method) {

            if (!$method['value'] || !in_array($method['value'], $allowedBrands)) {
                unset($allowedMethods[$i]);
            }
            $i++;

        }

        return $allowedMethods;
    }

    public function getInterestMethods()
    {
        return $this->_interestMethods;
    }

    /**
     * @param $code
     * @return null|string
     */
    public function getTransactionStateLabel($code)
    {
        foreach ($this->_transactionStates as $transactionState)
            if ($transactionState['code'] == $code) {
                return $this->__($transactionState['label']);
            }

        return null;
    }

    /**
     * Receive a transaction state string code and return the state number
     *
     * @param $state
     * @return null|string
     */
    public function getTransactionState($state)
    {
        if (isset($this->_transactionStates[$state])) {
            return $this->_transactionStates[$state]['code'];
        }

        return null;
    }

    /**
     * Receive a transaction state string code and return the state number
     *
     * @param $state
     * @return null|string
     */
    public function getIsDeniedState($searchState)
    {
        foreach ($this->_transactionStates as $code => $state) {
            if ($code == $searchState) {
                if ($state['type'] == 'not_approved') {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Slugify string
     *
     * @param string $phrase
     * @return string
     */
    public function slugify($str)
    {
        // Clean Currency Symbol
        $str = Mage::helper('core')->removeAccents($str);
        $urlKey = preg_replace('#[^0-9a-z+]+#i', '-', $str);
        $urlKey = strtolower($urlKey);
        $urlKey = trim($urlKey, '-');
        return $urlKey;
    }

    /**
     * @param $request
     * @param $response
     * @param boolean $orderId
     */
    public function saveTransaction($request, $response, $orderId = null)
    {
        try {

            //Mask sensible data
            if (property_exists($request, 'charges') && is_array($request->charges)) {
                $i = 0;
                foreach ($request->charges as $charge) {
                    if (property_exists($charge, 'card')) {

                        if (property_exists($charge->card, 'number')) {
                            $ccNumber = substr($charge->card->number, 0, 6) . 'XXXXXX' . substr($charge->card->number, -4, 4);
                            $request->charges[$i]->card->number = $ccNumber;
                        }

                        if (property_exists($charge->card, 'cvc')) {
                            $cvc = 'XXX';
                            $request->charges[$i]->card->cvc = $cvc;
                        }

                        if (property_exists($charge->card, 'token')) {
                            $token = 'XXXXXXXXX';
                            $request->charges[$i]->card->token = $token;
                        }

                    }
                    $i++;
                }
            }


            $paggiOrderId = null;
            if (is_object($request) || is_array($request)) {
                $request = json_encode($request);
            }

            if (is_object($response) || is_array($response)) {
                $paggiOrderId = property_exists($response, 'id') ? $response->id : null;
                $response = json_encode($response);
            }

            /** @var Paggi_Payment_Model_Transaction $transaction */
            $transaction = Mage::getModel('paggi/transaction');
            $data = array(
                'order_id' => $orderId,
                'paggi_id' => $paggiOrderId,
                'request' => $request,
                'response' => $response
            );

            $transaction->setData($data);
            $transaction->save();

        } catch (Exception $e) {
            Mage::logException($e);
        }
    }

    /**
     * @param $customerId
     * @return Paggi_Payment_Model_Resource_Card_Collection
     */
    public function getSavedCards($customerId)
    {
        /** @var Paggi_Payment_Model_Resource_Card_Collection $collection */
        $collection = Mage::getModel('paggi/card')->getCollection();
        $collection->addFieldToFilter('customer_id', $customerId);
        return $collection;
    }

    /**
     * @param int $entity_id
     * @return Paggi_Payment_Model_Card
     */
    public function getSavedCard($entity_id)
    {
        /** @var Paggi_Payment_Model_Card $creditCard */
        $creditCard = Mage::getModel('paggi/card')->load($entity_id);
        return $creditCard;
    }

    /**
     * @return array
     */
    public function getInstallmentsInformation($code = 'paggi_cc')
    {
        /** @var Mage_Sales_Model_Quote $quote */
        $quote = $this->getSession()->getQuote();

        $installmentsInformation = array();
        $paymentAmount = $quote->getBaseGrandTotal();
        $installments = $this->getConfig('max_installments', $code);
        $installmentsWithoutInterest = (int)$this->getConfig(
            'installments_without_interest_rate',
            $code
        );

        $irPerInstallments = false;
        if ($this->getConfig('use_interest_per_installments', $code) && $this->getConfig('interest_rate_per_installments', $code)) {
            $irPerInstallments = unserialize($this->getConfig('interest_rate_per_installments', $code));
            $installmentsWithoutInterest = 0;
        }

        $i = 1;
        $installmentsInformation[$i] = array(
            'installments' => 1,
            'value' => $paymentAmount,
            'total' => $paymentAmount,
            'interest_rate' => 0,
        );

        for ($i = 2; $i <= $installments; $i++) {

            if (($installments > $installmentsWithoutInterest) && ($i > $installmentsWithoutInterest)) {
                $interestRate = $this->getConfig('interest_rate', $code);
                $value = $this->getInstallmentValue($paymentAmount, $i);
                if (!$value)
                    continue;

                if (
                    $irPerInstallments
                    && isset($irPerInstallments['value'])
                    && isset($irPerInstallments['value'][$i])
                ) {
                    $interestRate = $irPerInstallments['value'][$i];
                }
            } else {
                $interestRate = 0;
                $value = $paymentAmount / $i;
            }

            //If the instalment is lower than 5.00
            if ($value < $this->getConfig('minimum_installments_value', $code) && $i > 1) {
                continue;
            }

            $installmentsInformation[$i] = array(
                'installments' => $i,
                'value' => $value,
                'total' => $value * $i,
                'interest_rate' => $interestRate,
            );

        }

        return $installmentsInformation;
    }

    /**
     * @return Mage_Checkout_Model_Session|Mage_Adminhtml_Model_Session_Quote|Mage_Core_Model_Abstract
     */
    public function getSession()
    {
        if (Mage::app()->getStore()->isAdmin()) {
            return Mage::getSingleton('adminhtml/session_quote');
        }

        return Mage::getSingleton('checkout/session');
    }

    /**
     * Get the paggi Config
     * @param $config
     * @param string $path
     * @return mixed
     */
    public function getConfig($config, $path = 'paggi_settings')
    {
        return Mage::getStoreConfig('payment/' . $path . '/' . $config);
    }

    /**
     * @param $paymentMethod
     * @param $total
     *
     * @param $installments
     * @return float|boolean
     */
    public function getInstallmentValue($total, $installments, $code = 'paggi_cc')
    {
        $installmentsWithoutInterestRate = (int)$this->getConfig('installments_without_interest_rate', $code);
        $interestRate = $this->getConfig('interest_rate', $code);
        $interestType = $this->getConfig('interest_type', $code);

        if ($this->getConfig('use_interest_per_installments', $code) && $this->getConfig('interest_rate_per_installments', $code)) {
            $irPerInstallments = unserialize($this->getConfig('interest_rate_per_installments', $code));
            if (!isset($irPerInstallments['value']) || !isset($irPerInstallments['value'][$installments])) {
                return false;
            }
            $interestRate = ($irPerInstallments['value'][$installments] / $installments);
            $interestType = 'simple';
            $installmentsWithoutInterestRate = 0;
        }

        $interestRate = (float)(str_replace(',', '.', $interestRate)) / 100;

        if ($installments > 0) {
            $valorParcela = $total / $installments;
        } else {
            $valorParcela = $total;
        }

        try {
            if ($installments > $installmentsWithoutInterestRate && $interestRate > 0) {
                switch ($interestType) {
                    case 'price':
                        $value = $total * (($interestRate * pow((1 + $interestRate), $installments)) / (pow((1 + $interestRate), $installments) - 1));
                        $valorParcela = round($value, 2);
                        break;
                    case 'compound':
                        //M = C * (1 + i)^n
                        $valorParcela = ($total * pow(1 + $interestRate, $installments)) / $installments;
                        break;
                    case 'simple':
                        //M = C * ( 1 + ( i * n ) )
                        $valorParcela = ($total * (1 + ($installments * $interestRate))) / $installments;
                        break;
                }
            }


        } catch (Exception $e) {
            $this->log($e->getMessage());
        } finally {
            return $valorParcela;
        }
    }

    /**
     * Log the message
     * @param string $message
     * @param string $file
     */
    public function log($message, $file = null)
    {
        $file = ($file) ? $file : self::LOG_FILE;
        if ($this->getConfig('debug')) {
            if (is_object($message)) {
                if (property_exists($message, 'charges')) {

                    if (is_array($message->{'charges'})) {
                        foreach ($message->{'charges'} as $i => $charge) {
                            if (
                            property_exists($message->{'charges'}[$i], 'card')

                            ) {
                                if (property_exists($message->{'charges'}[$i]->{'card'}, 'number')) {
                                    $number = $message->{'charges'}[$i]->{'card'}->{'number'};
                                    $message->{'charges'}[$i]->{'card'}->{'number'} = substr($number, 0, 6) . 'XXXXXX' . substr($number, -4, 4);
                                }

                                if (property_exists($message->{'charges'}[$i]->{'card'}, 'cvc')) {
                                    $message->{'charges'}[$i]->{'card'}->{'cvc'} = 'XXXX';
                                }

                                if (property_exists($message->{'charges'}[$i]->{'card'}, 'token')) {
                                    $message->{'charges'}[$i]->{'card'}->{'token'} = 'XXXXXXXXX';
                                }
                            }

                        }
                    }
                }
            }

            Mage::log($message, Zend_Log::INFO, $file);
        }
    }

    /**
     * @return array
     */
    public function getAvailableMethods()
    {
        return $this->_availableMethods;
    }

    /**
     * @return Paggi_Payment_Model_Api|Mage_Core_Model_Abstract
     */
    public function getApi()
    {
        if (!$this->_api) {
            $this->_api = Mage::getModel('paggi/api');
        }

        return $this->_api;
    }

    /**
     * @param Mage_Sales_Model_Order $order
     * @return string
     */
    public function getRequestOrderButton($order)
    {
        /** @var Mage_Core_Block_Template $block */
        $block = Mage::app()->getLayout()->createBlock('core/template')->setOrder($order)->setTemplate('paggi/info/report/button.phtml');
        if ($block) {
            return $block->toHtml();
        }

        return '';
    }

    /**
     * @return null
     */
    public function getTaxvatValue($forceCustomer = false)
    {
        $quote = $this->getSession()->getQuote();
        $taxvatValue = null;
        $showTaxvatField = $this->getConfig('show_taxvat_field');

        if (!$showTaxvatField || $forceCustomer) {

            $attributeCode = $this->getConfig('cpf_customer_attribute');
            $isCorporate = $this->isCorporate();
            if ($isCorporate) {
                $attributeCode = $this->getConfig('cnpj_customer_attribute');
            }

            if ($quote->getCustomer() && $quote->getCustomer()->getId()) {
                /** @var Mage_Customer_Model_Customer $_customer */
                $_customer = Mage::getModel('customer/customer')->load($quote->getCustomer()->getId());

                //If the value is numeric, verify the text of attribute and compare
                $taxvatValue = $_customer->getResource()->getAttribute($attributeCode)->getFrontend()->getValue($_customer);
            } else {
                $taxvatValue = $quote->getData('customer_' . $attributeCode);
            }
        }

        return $taxvatValue;
    }

    /**
     * @return bool
     */
    public function isCorporate()
    {
        $isCorporate = false;
        $attributeCode = $this->getConfig('customer_attribute_type');
        $attributeCodeValue = $this->getConfig('customer_attribute_type_value_corporate');
        //Verify if the type of buyer attribute is set and if is corporate
        if (
            $attributeCode
            && $attributeCodeValue
        ) {
            $quote = $this->getSession()->getQuote();
            if ($quote->getCustomer() && $quote->getCustomer()->getId()) {
                /** @var Mage_Customer_Model_Customer $_customer */
                $_customer = Mage::getModel('customer/customer')->load($quote->getCustomer()->getId());
                $customerType = $_customer->getData($attributeCode);
                $isCorporate = ($customerType == $attributeCodeValue);

                //If the value is numeric, verify the text of attribute and compare
                if (is_numeric($customerType) && !$isCorporate) {
                    $_customerTypeValue = $_customer->getResource()->getAttribute($attributeCode)->getFrontend()->getValue($_customer);
                    $isCorporate = ($customerType == $_customerTypeValue);
                }
            } else {
                $customerType = $quote->getData('customer_' . $attributeCode);
                $isCorporate = ($customerType == $attributeCodeValue);
            }
        }
        return $isCorporate;
    }

    /**
     * @param $string
     * @return mixed
     */
    public function digits($string)
    {
        return preg_replace('/[^0-9]/', '', $string);
    }

    /**
     * @param $config
     * @return mixed
     */
    public function getConfigData($config)
    {
        return Mage::getStoreConfig('payment/paggi_settings/' . $config);
    }

    /**
     * @param $dob
     * @param $format
     */
    public function getDate($dob, $format = 'Y-m-d')
    {
        $date = new DateTime($dob);
        return $date->format($format);
    }

    /**
     * @return string
     */
    public function getReservedOrderId()
    {
        //Order Increment ID
        $incrementOrderId = $this->getSession()->getQuote()->getReservedOrderId();
        if (!$incrementOrderId) {
            $this->getSession()->getQuote()->reserveOrderId();
        }

        return $this->getSession()->getQuote()->getReservedOrderId();
    }

    /**
     * Get Endpoint
     * @param $endpoint string
     * @return mixed
     */
    public function getEndpoint($endpoint, $orderId = null, $cardId = null)
    {
        $fullEndpoint = Mage::getStoreConfig('paggi/endpoints/' . $endpoint);
        $url = str_replace(
            array(
                '{{partner_id}}',
                '{{order_id}}',
                '{{card_id}}',
            ),
            array(
                $this->getConfig('partner_id'),
                $orderId,
                $cardId,
            ),
            $fullEndpoint
        );
        return $url;
    }

}