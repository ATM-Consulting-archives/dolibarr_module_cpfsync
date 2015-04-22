<?php

class SyncEvent extends TObjetStd
{
	// Tableau d'action pour savoir si l'action doit créer une nouvelle entrée sur le dolibarr distant
	public static $TActionCreate = array('COMPANY_CREATE', 'PRODUCT_CREATE', 'PAYMENT_CUSTOMER_CREATE', 'STOCK_MOVEMENT', 'PAYMENT_ADD_TO_BANK'); 
	
	// Tableau d'action pour savoir si l'action doit modifier une entrée sur le dolibarr distant
	public static $TActionModify = array('COMPANY_MODIFY', 'PRODUCT_MODIFY', 'PRODUCT_PRICE_MODIFY', 'SUPPLIER_PRODUCT_BUYPRICE_UPDATE');
	
	// Tableau d'action pour savoir si l'action doit supprimer une entrée sur le dolibarr distant
	public static $TActionDelete = array('COMPANY_DELETE', 'PRODUCT_DELETE', 'BILL_DELETE', 'PAYMENT_DELETE', 'SUPPLIER_PRODUCT_BUYPRICE_REMOVE');
	
	// Tableau d'action pour savoir si l'action doit valider une entrée sur le dolibarr distant (Peut donner lieu à une création ou une modification)
	public static $TActionValidate = array('BILL_VALIDATE', 'BILL_PAYED');
	
	// Tableau d'action pour les créations ou modifications des objets standard Abricot
	public static $TActionSave = array('CAISSE_BON_ACHAT_SAVE');
	
	// Tableau d'action pour tous le reste
	public static $TActionOther = array('DISCOUNT_LINK_TO_INVOICE', 'DISCOUNT_UNLINK_INVOICE');
	
	public function __construct()
	{
		$this->set_table(MAIN_DB_PREFIX.'sync_event');
		
		$this->TChamps = array();
		$this->add_champs('object', 'type=text;');
		$this->add_champs('type_object,doli_action,facnumber', 'type=chaine;'); //facnumber utile dans le cas d'un paiement, permet de faire la liaison avec la facture distante pcq l'object paiement converse qu'un id facture et non sa référence
		$this->add_champs('entity', 'type=integer;');
		
		$this->start();
	}
	
	public function load(&$db, $id)
	{
		$res = parent::load($db, $id);
		return $res;
	}
	
	public function save(&$db)
	{
		parent::save($db);
	}
	
}
