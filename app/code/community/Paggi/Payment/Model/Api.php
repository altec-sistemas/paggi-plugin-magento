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
    protected $paggi;
    protected $helper;

    /**
     * Paggi lib Object
     * @return Paggi_Payment_Model_Service_Order|false
     */
    public function getService()
    {
        if (!$this->paggi) {
            /** @var Paggi_Payment_Model_Service_Order paggi */
            $this->paggi = Mage::getModel('paggi/service_order');
        }

        return $this->paggi;
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

        $taxVat = $this->getHelper()->digits($payment->getAdditionalInformation('cpf_cnpj'));

        $token = $payment->getAdditionalInformation('cc_token');
        $amount = $amount * 100;
        $ccCid = Mage::registry('paggi_cc_cid');

        $charge = new stdClass();
        $charge->amount = (int) $amount;
        $charge->installments = $ccInstallments;

        if ($token) {

            $charge->card = new stdClass();
            $charge->card->id = $token;
            if ($ccCid) {
                $charge->card->cvc = $ccCid;
            }

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
            $charge->card->document = $taxVat;

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
        $customer->document = $order->getCustomerTaxvat() ? $order->getCustomerTaxvat() : $taxVat;

        if (!$this->getHelper()->getConfigData('show_taxvat_field')) {
            $customer->document = $this->getHelper()->getTaxvatValue();
        }

        $paggiOrder->capture = $this->getHelper()->getConfig('capture', $code) ? true : false;
        $paggiOrder->external_identifier = $orderId;
        $paggiOrder->ip = $order->getRemoteIp() ? $order->getRemoteIp() : Paggi_Payment_Helper_Data::DEFAULT_IP;
        $paggiOrder->charges = $charges;
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
     * @return stdClass
     */
    public function capture($order, $paggiOrderId, $amount)
    {
        $error = $this->getHelper()->__('Error capturing order %s', $order->getIncrementId());

        $endpoint = $this->getHelper()->getEndpoint('capture', $paggiOrderId);
        $response = $this->getService()->doPutRequest($endpoint);

        if ($response) {

            $responseData = Mage::helper('core')->jsonDecode($response->getBody(), false);
            $this->getHelper()->saveTransaction($endpoint, $responseData, $order->getIncrementId());

            if (!property_exists($responseData, 'id')) {
                Mage::throwException($error);
            }
        } else {
            Mage::throwException($error);
        }

        return $responseData;
    }

    /**
     * @param Mage_Sales_Model_Order $order
     * @param string $paggiOrderId
     * @param $amount
     * @return stdClass
     */
    public function getOrder($order, $paggiOrderId)
    {
        $error = $this->getHelper()->__('Error getting order %s', $order->getIncrementId());

        $endpoint = $this->getHelper()->getEndpoint('get_order', $paggiOrderId);
        $response = $this->getService()->doGetRequest($endpoint);

        if ($response) {

            $responseData = Mage::helper('core')->jsonDecode($response->getBody(), false);

            if (!property_exists($responseData, 'id')) {
                Mage::throwException($error);
            }

        } else {
            Mage::throwException($error);
        }

        return $responseData;
    }

    /**
     * @param Mage_Sales_Model_Order $order
     * @param string $paggiOrderId
     * @param $amount
     * @return stdClass
     */
    public function refund($order, $paggiOrderId)
    {
        $error = $this->getHelper()->__('Error voiding order %s', $order->getIncrementId());
        $endpoint = $this->getHelper()->getEndpoint('void', $paggiOrderId);

        $response = $this->getService()->doPutRequest($endpoint);

        if ($response) {
            $responseData = Mage::helper('core')->jsonDecode($response->getBody(), false);
            $this->getHelper()->saveTransaction($endpoint, $responseData, $order->getIncrementId());

            if (!property_exists($responseData, 'id')) {
                Mage::throwException($error);
            }

        } else {
            Mage::throwException($error);
        }

        return $responseData;

    }

    /**
     * @return Paggi_Payment_Helper_Data|Mage_Core_Helper_Abstract
     */
    public function getHelper()
    {
        if (!$this->helper) {
            /** @var Paggi_Payment_Helper_Data helper */
            $this->helper = Mage::helper('paggi');
        }

        return $this->helper;
    }
}
