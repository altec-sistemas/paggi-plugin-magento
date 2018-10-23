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
class Paggi_Payment_Model_Service_Order
    extends Zend_Service_Abstract
{
    /**
     * @var Paggi_Payment_Helper_Data
     */
    protected $helper;

    /**
     * @var string Auth Token
     */
    protected $token = null;

    /**
     * @param $params
     * @return Zend_Http_Response
     * @throws Zend_Http_Client_Exception
     */
    public function doPostRequest($path, $params = null)
    {
        $requestParams['method'] = Zend_Http_Client::POST;
        $requestParams['body'] = $params;
        $requestParams['path'] = $path;
        return $this->doRequest($requestParams);
    }

    /**
     * @param $params
     * @return Zend_Http_Response
     * @throws Zend_Http_Client_Exception
     */
    public function doGetRequest($path, $params = null)
    {
        $requestParams['method'] = Zend_Http_Client::GET;
        $requestParams['query'] = $params;
        $requestParams['path'] = $path;
        return $this->doRequest($requestParams);
    }

    /**
     * @param $params
     * @return Zend_Http_Response
     * @throws Zend_Http_Client_Exception
     */
    public function doDeleteRequest($path, $params = null)
    {
        $requestParams['method'] = Zend_Http_Client::DELETE;
        $requestParams['query'] = $params;
        $requestParams['path'] = $path;
        return $this->doRequest($requestParams);
    }

    /**
     * @param $params
     * @return Zend_Http_Response
     * @throws Zend_Http_Client_Exception
     */
    public function doPutRequest($path, $params = null)
    {

        $requestParams['method'] = Zend_Http_Client::PUT;
        $requestParams['body'] = $params;
        $requestParams['path'] = $path;
        return $this->doRequest($requestParams);
    }

    /**
     * @param $params
     * @return Zend_Http_Response
     * @throws Zend_Http_Client_Exception
     */
    private function doRequest($params)
    {
        $method = null;
        $token = $this->getHelper()->getConfig('token');

        $this->getHttpClient()->resetParameters(true);
        $this->getHttpClient()->setHeaders('Content-Type', 'application/json');
        $this->getHttpClient()->setHeaders('Accept', 'application/json;charset=UTF8');
        $this->getHttpClient()->setHeaders('Authorization', 'Bearer ' . $token);

        $path = "";

        $method = isset($params['method']) ? $params['method'] : Zend_Http_Client::GET;

        if (isset($params['path'])) {
            $path = $params['path'];
        }

        if (isset($params['query'])) {
            $this->getHttpClient()->setParameterGet($params['query']);
        }

        if (isset($params['post'])) {
            $this->getHttpClient()->setParameterPost($params['post']);
        }

        if (isset($params['body'])) {
            $this->getHttpClient()->setRawData($params['body'], 'UTF-8');
        }

        $url = $this->getServiceUrl() . $path;

        $this->getHttpClient()->setUri($url);
        $this->getHttpClient()->setMethod($method);

        $response = $this->getHttpClient()->request();

        if ($this->getHelper()->getConfig('enable_log')) {
            $this->saveRequestLog();
            $this->saveResponseLog();
        }

        return $response;
    }

    /**
     * Logging Requests sent to Api
     */
    public function saveRequestLog()
    {
        $_helper = $this->getHelper();
        $_helper->log('=====================');
        $_helper->log('REQUEST');
        $_helper->log($this->getHttpClient()->getLastRequest());
    }

    /**
     * Logging Response returned from Api
     */
    public function saveResponseLog()
    {
        $_helper = $this->getHelper();
        $_helper->log('RESPONSE');
        $_helper->log($this->getHttpClient()->getLastResponse());
    }

    protected function getServiceUrl()
    {
        $url = $this->getHelper()->getConfig('api_url');
        if ($this->getHelper()->getConfig('sandbox')) {
            $url = $this->getHelper()->getConfig('sandbox_url');
        }
        return $url;
    }

    /**
     * @return Paggi_Payment_Helper_Data
     */
    protected function getHelper()
    {
        if (!$this->helper) {
            $this->helper = Mage::helper('paggi');
        }

        return $this->helper;
    }

    public function getOrders($limit = 250, $page = 1)
    {
        try {
            $path = $this->getHelper()->getEndpoint('orders');
            $params = 'limit=' . $limit . '&p=' . $page;
            $response = $this->doGetRequest($path, $params);

            if ($response['status'] == 200) {
                $body = Mage::helper('core')->jsonDecode($response['body'], false);
                if ($body->{'data'}) {

                    foreach ($body->{'data'} as $order) {
                        $order->{'order_id'};
                        $order->{'pedido_venda'};
                        $order->{'supplier_id'};
                        $order->{'status'}; //1 - Novo | 4 - Pendente | 5 - Processando | 9 - Finalizado
                        $order->{'priority'};
                        $order->{'created_at'};
                        $order->{'assistence'};
                        $order->{'data_retorno_nf'};
                        $order->{'data_estimada_entrega_cd'};
                        $order->{'prazo_para_faturamento'};


                        foreach ($order->{'items'} as $item) {
                            $item->{'item'};
                            $item->{'mm_product_id'};
                            $item->{'sku'};
                            $item->{'description'};
                            $item->{'quantity'};
                            $item->{'UN'};
                            $item->{'price_unity'};
                            $item->{'price_total'};
                            $item->{'cst'};
                        }

                        foreach ($order->{'cliente'} as $customer) {
                            $customer->{'email'};
                            $customer->{'nome'};
                            $customer->{'cep'};
                            $customer->{'endereco'};//explode a vírgula
                            $customer->{'bairro'};
                            $customer->{'cidade'};
                            $customer->{'cpfcnpj'};
                            $customer->{'uf'};
                            $customer->{'ibge'};
                            $customer->{'ibgecompleto'};
                            $customer->{'incricaoestadual'};
                            $customer->{'telefone'};
                            $customer->{'tipocli'};
                            $customer->{'tipocontrib'};
                        }

                        foreach ($order->{'transportadora'} as $shipping) {
                            $shipping->{'nome'};
                            $shipping->{'nreduzido'};
                            $shipping->{'cep'};
                            $shipping->{'endereco'};//explode,
                            $shipping->{'bairro'};
                            $shipping->{'cidade'};
                            $shipping->{'cnpj'};
                            $shipping->{'uf'};
                            $shipping->{'incricaoestadual'};
                            $shipping->{'tipocli'};
                            $shipping->{'tipocontrib'};
                        }
                    }

                    $pagination = $body->{'pagination'};
                    $nextPage = (int)$pagination->{'page'} + 1;
                    $i = 0;
                    while ($pagination->{'next'}) {
                        $this->getOrders($limit, $nextPage);

                        //Safety way out
                        $i++;
                        if ($i == 10)
                            break;
                    }

                }
            }
        } catch (Exception $e) {
            Mage::logException($e);
        }

        return $this;

    }

    /**
Status
1 - Novo
4 - Pendente
5 - Processando
9 - Finalizado
     *
     * @param $orderId
     */
    public function getOrder($orderId)
    {
        try {
            $path = $this->getHelper()->getEndpoint('order');
            $path = str_replace('{{order_id}}', $orderId, $path);

            $response = $this->doGetRequest($path);

            if ($response['status'] == 200) {
                $body = Mage::helper('core')->jsonDecode($response['body'], false);
                if ($body->{'data'}) {
                    $order = $body->{'data'};

                    //Order Aqui
                    $order->{'order_id'};
                }
            }

        } catch (Exception $e) {
            Mage::logException($e);
        }
    }

    public function accept($orderId)
    {

    }

    public function authorizeInvoice($orderId)
    {

    }

    public function getMarketplaceInvoice($orderId)
    {

    }

    public function sendInvoices($orderId)
    {

    }
}