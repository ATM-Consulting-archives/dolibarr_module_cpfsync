<?php

	define('INC_FROM_CRON_SCRIPT',true);
	
	require('../config.php');

	$fk_entrepot = GETPOST('fk_entrepot');
	
	if(empty($fk_entrepot)) exit('fk_entrepot ?');

	$PDOdb=new TPDOdb;
	$Tab = $PDOdb->ExecuteAsArray("SELECT fk_product,reel FROM ".MAIN_DB_PREFIX."product_stock WHERE fk_entrepot=".(int)$fk_entrepot);


	dol_include_once('/product/class/product.class.php');

	$TStock=array();

	foreach($Tab as $row ) {

		$product=new Product($db);
		$product->fetch($row->fk_product);
		
//		$product->load_stock();
		
//		$stock = $product->stock_warehouse[$fk_entrepot]->real;
	
		$TStock[]= array(
			'ref'=>$product->ref
			,'fk_entrepot'=>$fk_entrepot
			,'stock'=>$row->reel
		)	;
	}
	
	
	var_dump($TStock);
