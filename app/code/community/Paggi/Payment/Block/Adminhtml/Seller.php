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
class Paggi_Payment_Block_Adminhtml_Seller
    extends Mage_Adminhtml_Block_Widget_Grid_Container
{
    public function __construct()
    {
        $this->_controller = 'adminhtml_seller';
        $this->_blockGroup = 'paggi';
        $this->_headerText = Mage::helper('paggi')->__('Manage Sellers');
        $this->_addButtonLabel = Mage::helper('paggi')->__('Add New Seller');
        parent::__construct();
    }

}
