<?php

define('INC_FROM_CRON_SCRIPT', true);
set_time_limit(0);
require('../config.php');
require('../class/cpfsync.class.php');

$ATMdb=new TPDOdb;
$action = __get('action', 0);

traite_get($ATMdb, $action);

function traite_get(&$ATMdb, $case) 
{
	switch (strtolower($case)) 
	{
		case 'ping':
			__out('ok', 'json');
			break;
		case 'test':
			__out(_test(), 'json');
			break;
		case 'senddata':
			__out(_sendData($ATMdb));
			break;
		case 'refreshdata':
			__out(_refreshData($ATMdb));
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

function _sendData(&$ATMdb)
{
	global $conf;
			
	if (_askPing() != "ok") return 'ko';
	
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

	$url_distant = $conf->global->CPFSYNC_URL_DISTANT;
	$url_distant.= '/custom/cpfsync/script/interface.php?action=refreshData';

	$context = stream_context_create(array(
		'http' => array(
		    'method' => 'POST',
		    'content' => http_build_query($data),
		    'timeout' => 40, //Si je n'ai pas de réponse dans les 40sec ma requête http s'arrête
		)
	));
	
	$res = file_get_contents($url_distant, false, $context);
	var_dump($res);
	/*
	if ($res) return _deleteCurrentEvent($ATMdb, $data);
	else return "Traitement des données impossible";*/
}

function _deleteCurrentEvent(&$ATMdb, $data)
{
	/*foreach ($data as $row)
	{
		$syncEvent = new SyncEvent;
		$syncEvent->load($ATMdb, $row['rowid']);
		$syncEvent->delete($ATMdb);
	}*/

	return 1;
}

function _refreshData($ATMdb)
{
	global $db;
	
	dol_include_once('/societe/class/societe.class.php');
	dol_include_once('/product/class/product.class.php');
	dol_include_once('/compta/facture/class/facture.class.php');
	
	//On utilise tjr l'utilisateur 1 (atm)
	$user = new User($db);
	$user->fetch(1);
	
	$data = __get('data', array());
	/*
	$data = array();
	
	$sql = 'SELECT * FROM '.MAIN_DB_PREFIX.'sync_event';
	$ATMdb->Execute($sql);
	
	while ($ATMdb->Get_line())
	{
		$data[] = array(
			'rowid' => $ATMdb->Get_field('rowid')
			,'object_serialize' => $ATMdb->Get_field('object')
			,'type_object' => $ATMdb->Get_field('type_object')
			,'doli_action' => $ATMdb->Get_field('doli_action')
		);
	}
	*/
	
	foreach ($data as $row)
	{
		$object = unserialize($row['object_serialize']);
		$class = $row['type_object'];
		$doli_action = $row['doli_action'];
			
		$localObject = new $class($db);
			
		if (in_array($doli_action, SyncEvent::$TActionCreate))
		{
			$localObject = $object;
			$localObject->create($user, 1);
		}
		elseif (in_array($doli_action, SyncEvent::$TActionModify))
		{
			$localObject->fetch($object->id);
			$localObject = $object;
			$localObject->update($user, 1);
		}
		elseif (in_array($doli_action, SyncEvent::$TActionDelete))
		{
			$localObject->fetch($object->id);
			$localObject->delete(0, 1); // Attention certain objets ne prennent pas le 2eme paramètre qui normalement désactive l'appel des triggers
		}
		elseif (in_array($doli_action, SyncEvent::$TActionValidation))
		{
			//si un fetch renvoi un id alors l'objet existe donc update sinon create
			if ($class::isExistingObject(strtolower($class), $object->id))
			{
				$localObject->fetch($object->id);
				$localObject->update($user, 1);
			}
			else 
			{
				$localObject = $object;
				$localObject->create($user, 1);
			}
		}		
		
	}

	return 1;
}
