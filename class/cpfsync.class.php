<?php

class SyncEvent extends TObjetStd
{
	public $errors = array();
	
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
