<?php

define('INC_FROM_CRON_SCRIPT', true);
set_time_limit(0);
require('../config.php');
require('../class/cpfsync.class.php');

ini_set('display_errors',1);
error_reporting(E_ALL);

$ATMdb=new TPDOdb;
$action = __get('action', 0);

traite_get($ATMdb, $action);

function traite_get(&$ATMdb, $action) 
{
	
	global $db,$conf;

	switch ($action) 
	{
		case 'ping':
			if ($conf->cpfsync->enabled) __out('ok', 'json');
			else __out('ko', 'json');
			break;
			
		case 'test':
			__out(_test(), 'json');
			break;
			
		case 'sendData':
			__out(_sendData($ATMdb, $conf));
			break;
			
		case 'refreshData':
			
			__out(_refreshData($ATMdb, $conf, $db), 'json');
			break;
			
		default:
			exit;
			break;
	}
}

function _test()
{
	$url = __get('url', null);
	return _askPing();
}

function _askPing($url = null)
{
	global $conf;
	if (!$url) $url = $conf->global->CPFSYNC_URL_DISTANT;
	
	$url .= '/custom/cpfsync/script/interface.php?action=ping';
	
	$context = stream_context_create(array(
		'http' => array(
		    'method' => 'GET',
		    'timeout' => 10, //Si je n'ai pas de réponse dans les 10sec ma requête http s'arrête
		)
	));
	
	$res = json_decode(file_get_contents($url, false, $context));
	return $res;
}

function _sendData(&$ATMdb, $conf)
{
	if (_askPing() != "ok") return 'ko';
	
	//Formatage du tableau pour la réception en POST
	$data = array('data' => array());
	
	$sql = 'SELECT * FROM '.MAIN_DB_PREFIX.'sync_event';
	$ATMdb->Execute($sql);
	
	while ($ATMdb->Get_line())
	{
		$data['data'][] = array(
			'rowid' => $ATMdb->Get_field('rowid')
			,'object_serialize' => $ATMdb->Get_field('object')
			,'type_object' => $ATMdb->Get_field('type_object')
			,'doli_action' => $ATMdb->Get_field('doli_action')
		);
	}

	$data['action'] = 'refreshData';

	$url_distant = $conf->global->CPFSYNC_URL_DISTANT;
	$url_distant.= '/custom/cpfsync/script/interface.php';

	$data_build  = http_build_query($data);

	$context = stream_context_create(array(
		'http' => array(
		    'method' => 'POST',
		    'content' => $data_build,
		    'timeout' => 40, //Si je n'ai pas de réponse dans les 40sec ma requête http s'arrête
		    'header'=> "Content-type: application/x-www-form-urlencoded\r\n"
                . "Content-Length: " . strlen($data_build) . "\r\n",
		)
	));
	
	$res = file_get_contents($url_distant, false, $context);
	
	if (json_decode($res) == 'ok') return _deleteCurrentEvent($ATMdb, $data['data']);
	else return 'Traitement des données impossible';
}

function _deleteCurrentEvent(&$ATMdb, $data)
{
	foreach ($data as $row)
	{
		$syncEvent = new SyncEvent;
		$syncEvent->load($ATMdb, $row['rowid']);
		$syncEvent->delete($ATMdb);
	}

	return 1;
}

function _refreshData(&$ATMdb, &$conf, &$db)
{
	dol_include_once('/core/lib/admin.lib.php');
	dol_include_once('/user/class/user.class.php');
	dol_include_once('/societe/class/client.class.php');
	dol_include_once('/product/class/product.class.php');
	dol_include_once('/compta/facture/class/facture.class.php');
	
	//Je lock le trigger du module pour éviter des ajouts dans llx_sync_event via le script
	dolibarr_set_const($db, 'CPFSYNC_LOCK', 1);

	if (!($id_user = (int) $conf->global->CPFSYNC_ID_USER) || $id_user <= 0) return 'ko';
	
	$user = new User($db);
	$user->fetch($id_user);
	//^ vérifier les droits du user
	
	$data = __get('data', array());
	
	foreach ($data as $row)
	{
		$object = unserialize($row['object_serialize']);
		$class = $row['type_object'];
		$doli_action = $row['doli_action'];
		
		if (in_array($doli_action, SyncEvent::$TActionCreate))
		{
			_create($db, $user, $class, $object);			
		}
		elseif (in_array($doli_action, SyncEvent::$TActionModify))
		{
			_update($db, $user, $class, $object);
		}
		elseif (in_array($doli_action, SyncEvent::$TActionDelete))
		{
			_delete($db, $class, $object);
		}
		elseif (in_array($doli_action, SyncEvent::$TActionValidation))
		{
			$exist = _isExistingObject($db, strtolower($class), (int) $object->id);
			
			if ($exist) _update($db, $user, $class, $object);
			else _create($db, $user, $class, $object);
		}	
		
	}
	
	dolibarr_set_const($db, 'CPFSYNC_LOCK', '');
	
	return 'ok';
}

function _create(&$db, &$user, $class, $object)
{	
	$localObject = clone $object;
	$localObject->__construct($db); //Permet de re-définir $localObject->db qui est un attribut protected
	
	$localObject->create($user);
}

function _update(&$db, &$user, $class, $object)
{
	$localObject = new $class($db);
	
	if ($localObject->fetch($object->id))
	{
		$localObject = $object;
		
		switch (strtolower($class)) {
			case 'societe':
			case 'product':
				$localObject->update($localObject->id, $user);
				break;
			
			default:
				$localObject->update($user);
				break;
		}
		
	}
	else 
	{
		_create($db, $class, $object);
	}
}

function _delete(&$db, $class, $object)
{
	$localObject = new $class($db);
	$localObject->fetch($object->id);
	
	switch (strtolower($class)) {
		case 'societe':
			$localObject->delete($localObject->id);
			break;
		
		default:
			$localObject->delete();
			break;
	}
}

/*
 * Fonction custom qui reprend la fonction de la class Commonobject
 * check uniquement sur un id et ne fait pas de select ref qui n'existe pas dans la table llx_societe par exemple
 */
function _isExistingObject($db, $element, $id)
{
	$sql = 'SELECT rowid';
	$sql.= ' FROM '.MAIN_DB_PREFIX.$element;
	if ($id > 0) $sql.= ' WHERE rowid = '.$db->escape($id);
	else {
		return -1;
	}

	$resql = $db->query($sql);
	if ($resql)
	{
		$num = $db->num_rows($resql);
		if ($num > 0) return 1;
		else return 0;
	}
	return -1;
}
