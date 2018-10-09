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
class Paggi_Payment_Block_Customer_Cc_List extends Mage_Customer_Block_Account_Dashboard
{
    /**
     * Product reviews collection
     *
     * @var Mage_Review_Model_Resource_Review_Product_Collection
     */
    protected $_collection;

    /**
     * Initializes collection
     */
    protected function _construct()
    {
        $customerId = Mage::getSingleton('customer/session')->getCustomerId();
        $this->_collection = Mage::getModel('paggi/card')->getCollection();
        $this->_collection->addFieldToFilter('customer_id', $customerId);
    }

    /**
     * Gets collection items count
     *
     * @return int
     */
    public function count()
    {
        return $this->_collection->getSize();
    }

    /**
     * Get html code for toolbar
     *
     * @return string
     */
    public function getToolbarHtml()
    {
        return $this->getChildHtml('toolbar');
    }

    /**
     * Initializes toolbar
     *
     * @return Mage_Core_Block_Abstract
     */
    protected function _prepareLayout()
    {
        $toolbar = $this->getLayout()->createBlock('page/html_pager', 'paggi_customer_cc_list.toolbar')
            ->setCollection($this->getCollection());

        $this->setChild('toolbar', $toolbar);
        return parent::_prepareLayout();
    }

    /**
     * Get collection
     *
     * @return Mage_Review_Model_Resource_Review_Product_Collection
     */
    protected function _getCollection()
    {
        return $this->_collection;
    }

    /**
     * Get collection
     *
     * @return Mage_Review_Model_Resource_Review_Product_Collection
     */
    public function getCollection()
    {
        return $this->_getCollection();
    }

    /**
     * Get remove link
     *
     * @return string
     */
    public function getRemoveLink()
    {
        return Mage::getUrl('paggi/cc/delete', array('_secure' => Mage::app()->getStore()->isCurrentlySecure()));
    }

    /**
     * @return Mage_Core_Block_Abstract
     */
    protected function _beforeToHtml()
    {
        $this->_getCollection()->load();
        return parent::_beforeToHtml();
    }
}
