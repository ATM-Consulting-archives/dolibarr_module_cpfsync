<?php

define('INC_FROM_CRON_SCRIPT', true);
set_time_limit(0);
require('../config.php');
require('../class/cpfsync.class.php');


//A retirer pour le create des produits de dolibarr => fait des if sur des variables potentiellements non initialisées
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
			,'facnumber' => $ATMdb->Get_field('facnumber')
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
	try 
	{
		dol_include_once('/core/lib/admin.lib.php');
		dol_include_once('/user/class/user.class.php');
		dol_include_once('/societe/class/client.class.php');
		dol_include_once('/product/class/product.class.php');
		dol_include_once('/compta/facture/class/facture.class.php');
		dol_include_once('/compta/paiement/class/paiement.class.php');
		dol_include_once('/core/lib/price.lib.php');
		
		//Je lock le trigger du module pour éviter des ajouts dans llx_sync_event via le script
		dolibarr_set_const($db, 'CPFSYNC_LOCK', 1);
	
		if (!($id_user = (int) $conf->global->CPFSYNC_ID_USER) || $id_user <= 0) return 'ko';
		
		$user = new User($db);
		$user->fetch($id_user);
		$user->getrights();
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
				_update($db, $user, $class, $object, $doli_action, $row['facnumber']);
			}
			elseif (in_array($doli_action, SyncEvent::$TActionDelete))
			{
				_delete($db, $class, $object);
			}
			elseif (in_array($doli_action, SyncEvent::$TActionValidate))
			{
				$exist = _isExistingObject($db, strtolower($class), (int) $object->id);
				
				if ($exist) _update($db, $user, $class, $object, $doli_action);
				else _create($db, $user, $class, $object);
			}	
			
		}
	}
	finally
	{
		dolibarr_set_const($db, 'CPFSYNC_LOCK', '');
	}
		
	return 'ok';
}

function _create(&$db, &$user, $class, $object)
{	
	$localObject = clone $object;
	$localObject->id = 0;
	
	//Closure PHP c'est magique => http://www.thedarksideofthewebblog.com/les-closure-en-php/
	$initDb = function(&$db) { $this->db = &$db; };
	$initDb = Closure::bind($initDb , $localObject, $class);
	$initDb($db);
	
	if ($class == 'Facture')
	{
		$localObject->facnumber = $localObject->getNextNumRef($localObject->client);
		_initDbFacture($db, $localObject);
	}
	
	$localObject->create($user);
	
	if ($class == 'Facture')
	{
		//Permet de générer la référence
		$localObject->validate($user, $pbject->facnumber);
	}
}

function _update(&$db, &$user, $class, $object, $doli_action, $facnumber = '')
{
	$localObject = new $class($db);
	
	if (_fetch($db, $localObject, $object, $class, $facnumber))
	{
		if ($class == 'Facture')
		{
			$oldLines = $localObject->lines;	
		}
		
		$oldId = $localObject->id;
		$localObject = clone $object;
		$localObject->id = $oldId;
		
		$initDb = function(&$db) { $this->db = &$db; };
		$initDb = Closure::bind($initDb , $localObject, $class);
		$initDb($db);
		
		if ($class == 'Facture')
		{
			_initDbFacture($db, $localObject);
			_updateLines($db, $localObject, $oldLines);
		}
		
		switch ($class) {
			case 'Societe':
				$localObject->update($localObject->id, $user);
				break;
				
			case 'Product':
				if ($doli_action == 'PRODUCT_PRICE_MODIFY') $localObject->updatePrice($localObject->price, $localObject->price_base_type, $user, $localObject->tva_tx, $localObject->price_min);
				else $localObject->update($localObject->id, $user);
				break;
			
			default:
				$localObject->update($user);
				break;
		}
		
	}
	else 
	{
		_create($db, $user, $class, $object);
	}
}

function _delete(&$db, $class, $object)
{
	$localObject = new $class($db);
	$localObject->fetch($object->id);
	
	switch ($class) {
		case 'Societe':
			$localObject->delete($localObject->id);
			break;
		
		default:
			$localObject->delete();
			break;
	}
}

function _fetch(&$db, &$localObject, $object, $class, $facnumber = '')
{
	$sql = 'SELECT rowid FROM '.MAIN_DB_PREFIX;
	switch ($class) 
	{
		case 'Societe':
			//Recherche sur code_client ou si non renseigné code_fournisseur
			$sql.= 'societe WHERE ';
			
			if ($object->code_client) $sql .= 'code_client = "'.$db->escape($object->code_client).'"';
			elseif ($object->code_client) $sql .= 'code_fournisseur = "'.$db->escape($object->code_fournisseur).'"';
			else return false;
			
			break;
		
		case 'Product':
			//Recherche sur la ref produit
			return $localObject->fetch(null, $object->ref);
			
			break;
		
		case 'Facture':
			//Recherche sur le facnumber facture
			return $localObject->fetch(null, $object->facnumber);
			
			break;
			
		case 'Paiement':
			//Ligne de facture
			$facture = new Facture($db);
			$facture->fetch(null, $facnumber);
			
			break;
			
		default:
			return false;
			break;
	}
	
	$resql = $db->query($sql);
	if ($db->num_rows($resql))
	{
		$obj = $db->fetch_object($resql);
		return $localObject->fetch($obj->rowid);	
	}
	
	return false;
}

function _initDbFacture(&$db, &$localObject)
{
	foreach ($localObject->lines as $factureLigne)
	{
		$initDbFactureLigne = function(&$db) { $this->db = &$db; };
		$initDbFactureLigne = Closure::bind($initDbFactureLigne , $factureLigne, 'FactureLigne');
		$initDbFactureLigne($db);
	}
	
	$initDbFactureClient = function(&$db) { $this->db = &$db; };
	$initDbFactureClient = Closure::bind($initDbFactureClient , $localObject->client, 'Societe');
	$initDbFactureClient($db);
}

function _updateLines(&$db, &$localObject, $oldLines)
{
	//Pour supprimer les lignes de la facture ou pour en ajouter l'objet facture doit être en "brouillon"
	$localObject->brouillon = 1;
	
	foreach ($oldLines  as $line)
	{
		$localObject->deleteline($line->rowid);
	}
	
	foreach ($localObject->lines as $newline)
	{
		$localObject->addline($newline->desc, $newline->subprice, $newline->qty, $newline->tva_tx, $newline->localtax1_tx, $newline->localtax2_tx, $newline->fk_product, $newline->remise_percent, $newline->date_start, $newline->date_end, $newline->fk_code_ventilation, $newline->info_bits, $newline->fk_remise_except, ($newline->total_tva > 0 || !$newline->fk_product ? 'HT' : 'TTC'), ($newline->subprice * (1 + ($newline->tva_tx / 100))), $newline->product_type, $newline->rang, $newline->special_code, $newline->origin, $newline->origin_id, $newline->fk_parent_line, $newline->fk_fournprice, $newline->pa_ht, $newline->label, $newline->array_options);
	}
	
	$localObject->brouillon = 0;
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
