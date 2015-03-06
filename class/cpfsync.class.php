<?php

class SyncEvent extends TObjetStd
{
	// Tableau d'action pour savoir si l'action doit créer une nouvelle entrée sur le dolibarr distant
	public static $TActionCreate = array('COMPANY_CREATE', 'PRODUCT_CREATE'); 
	
	// Tableau d'action pour savoir si l'action doit modifier une entrée sur le dolibarr distant
	public static $TActionModify = array('COMPANY_MODIFY', 'PRODUCT_MODIFY', 'PRODUCT_PRICE_MODIFY');
	
	// Tableau d'action pour savoir si l'action doit supprimer une entrée sur le dolibarr distant
	public static $TActionDelete = array('COMPANY_DELETE', 'PRODUCT_DELETE', 'BILL_DELETE');
	
	// Tableau d'action pour savoir si l'action doit valider une entrée sur le dolibarr distant (Peut donner lieu à une création ou une modification)
	public static $TActionValidate = array('BILL_VALIDATE');
	
	
	public function __construct()
	{
		$this->set_table(MAIN_DB_PREFIX.'sync_event');
		
		$this->TChamps = array();
		$this->add_champs('object', 'type=text;');
		$this->add_champs('type_object,doli_action', 'type=chaine;');
		
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
