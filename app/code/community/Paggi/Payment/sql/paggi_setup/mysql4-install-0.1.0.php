<?php
/** @var Mage_Eav_Model_Entity_Setup $this */
$installer = $this;
$installer->startSetup();

$newTable = $this->getTable('paggi/card');
if (!$installer->tableExists($newTable)) {

    $table = $installer->getConnection()
        ->newTable($installer->getTable($newTable))
        ->addColumn('entity_id', Varien_Db_Ddl_Table::TYPE_INTEGER, null, array(
            'identity'  => true,
            'unsigned'  => true,
            'nullable'  => false,
            'primary'   => true,
        ))
        ->addColumn('customer_id', Varien_Db_Ddl_Table::TYPE_INTEGER, '11', array(
            'nullable'  => false,
            'unsigned'  => true,
        ))
        ->addColumn('token', Varien_Db_Ddl_Table::TYPE_VARCHAR, '100', array(
            'nullable'  => false,
        ))
        ->addColumn('description', Varien_Db_Ddl_Table::TYPE_VARCHAR, '100', array(
            'nullable'  => false,
        ))
        ->addColumn('created_at', Varien_Db_Ddl_Table::TYPE_TIMESTAMP, null, array(
            'nullable'  => false
        ))
        ->addColumn('updated_at', Varien_Db_Ddl_Table::TYPE_DATETIME, null, array(
            'nullable'  => false
        ));

    $installer->getConnection()->createTable($table);
}

//Paggi Transaction table
$newTable = $this->getTable('paggi/transaction');
if (!$installer->tableExists($newTable)) {

    $table = $installer->getConnection()
        ->newTable($installer->getTable($newTable))
        ->addColumn('entity_id', Varien_Db_Ddl_Table::TYPE_INTEGER, null, array(
            'identity'  => true,
            'unsigned'  => true,
            'nullable'  => false,
            'primary'   => true,
        ))
        ->addColumn('order_id', Varien_Db_Ddl_Table::TYPE_INTEGER, '11', array(
            'nullable'  => false,
            'unsigned'  => true,
        ))
        ->addColumn('paggi_id', Varien_Db_Ddl_Table::TYPE_INTEGER, '11', array(
            'nullable'  => false,
            'unsigned'  => true,
        ))
        ->addColumn('request', Varien_Db_Ddl_Table::TYPE_TEXT, null, array(
            'nullable'  => false,
        ))
        ->addColumn('response', Varien_Db_Ddl_Table::TYPE_TEXT, null, array(
            'nullable'  => false,
        ))
        ->addColumn('created_at', Varien_Db_Ddl_Table::TYPE_TIMESTAMP, null, array(
            'nullable'  => false
        ));

    $installer->getConnection()->createTable($table);
}

/**
 * Add 'interest_amount' attribute for entities
 */
$tables = array(
    $installer->getTable('sales/quote_address'),
    $installer->getTable('sales/order'),
    $installer->getTable('sales/invoice'),
    $installer->getTable('sales/creditmemo')
);

$code = 'interest_amount';
foreach ($tables as $table) {
    if (!$installer->getConnection()->tableColumnExists($table, $code)) {
        $installer->getConnection()->addColumn($table, $code, "DECIMAL( 10, 2 ) NOT NULL");
    }
    if (!$installer->getConnection()->tableColumnExists($table, 'base_' . $code)) {
        $installer->getConnection()->addColumn($table, 'base_' . $code, "DECIMAL( 10, 2 ) NOT NULL");
    }
}

$table = $this->getTable('sales/order');
$code = 'paggi_order_id';
if(!$installer->getConnection()->tableColumnExists($table, $code)) {
    $installer->getConnection()->addColumn($table, $code, "VARCHAR(255) NOT NULL");
}

$installer->endSetup();

