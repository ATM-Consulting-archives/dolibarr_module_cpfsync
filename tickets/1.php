<?php

	require('../config.php');



	$PDOdb=new TPDOdb;

	$Tab = $PDOdb->ExecuteAsArray("SELECT rowid FROM llx_societe WHERE code_client LIKE 'CY%'");


	dol_include_once('/societe/class/societe.class.php');
//var_dump($Tab);

	foreach($Tab as $row ) {

		$societe=new Societe($db);
		$societe->fetch($row->rowid);
		$societe->update($row->rowid, $user);
//var_dump($societe);
	}
