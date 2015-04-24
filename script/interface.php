<?php

define('INC_FROM_CRON_SCRIPT', true);

require('../config.php');
require('../class/cpfsync.class.php');

set_time_limit(119); //cron toute les 2min
ini_set('memory_limit', '512M');

//En phase de test, à retirer pour le create des produits de dolibarr => fait des if sur des variables potentiellements non initialisées
//ini_set('display_errors',1);
//error_reporting(E_ALL);

$ATMdb=new TPDOdb;
$action = __get('action', 0);

traite_get($ATMdb, $action);

function traite_get(&$ATMdb, $action) 
{
	global $db,$conf;

	switch ($action) 
	{
		case 'ping':
			if ($conf->cpfsync->enabled) __out('ok', __get('format', 'json'));
			else __out('ko', __get('format', 'json'));
			break;
			
		case 'test':
			__out(_test(), __get('format', 'json'));
			break;
			
		case 'sendData':
			__out(_sendData($ATMdb, $conf));
			break;
			
		case 'refreshData':
			__out(_refreshData($ATMdb, $conf, $db), __get('format', 'json'));
			break;
			
		case 'getObject':
			__out(_sendObject($ATMdb, $conf, $db), __get('format', 'json'));
			
		default:
			exit;
			break;
	}
}

function _test()
{
	$url = __get('url', null);
	return _askPing($url);
}

function _askPing($url = null, $format = null)
{
	global $conf;
	if (!$url) $url = $conf->global->CPFSYNC_URL_DISTANT;
	
	$url .= '/custom/cpfsync/script/interface.php';
	
	$data['action'] = 'ping';
	$data_build  = http_build_query($data);
	
	$context = stream_context_create(array(
		'http' => array(
		    'method' => 'POST'
		    ,'content' => $data_build
		    ,'timeout' => 10 //Si je n'ai pas de réponse dans les 10sec ma requête http s'arrête
		    ,'header'=> "Content-type: application/x-www-form-urlencoded\r\n"
                . "Content-Length: " . strlen($data_build) . "\r\n",
		)
	));
	
	$res = json_decode(file_get_contents($url, false, $context));
	return $res;
}

function _sendData(&$ATMdb, $conf)
{
	if (!$conf->cpfsync->enabled || _askPing() != "ok") return 'ko';
	
	//Formatage du tableau pour la réception en POST
	$data = array('data' => array());
	
	$sql = 'SELECT * FROM '.MAIN_DB_PREFIX.'sync_event ORDER BY rowid LIMIT 20';
	$ATMdb->Execute($sql);
	
	while ($ATMdb->Get_line())
	{
		$data['data'][] = array(
			'rowid' => $ATMdb->Get_field('rowid')
			,'object_serialize' => $ATMdb->Get_field('object')
			,'type_object' => $ATMdb->Get_field('type_object')
			,'doli_action' => $ATMdb->Get_field('doli_action')
			,'facnumber' => $ATMdb->Get_field('facnumber')
			,'entity' => $ATMdb->Get_field('entity')
		);
	}

	$data['action'] = 'refreshData';

	$url_distant = $conf->global->CPFSYNC_URL_DISTANT;
	$url_distant.= '/custom/cpfsync/script/interface.php';

	$data_build  = http_build_query($data);

	$context = stream_context_create(array(
		'http' => array(
		    'method' => 'POST'
		    ,'content' => $data_build
		    ,'timeout' => 100 //Si je n'ai pas de réponse dans les 100sec ma requête http s'arrête
		    ,'header'=> "Content-type: application/x-www-form-urlencoded\r\n"
                . "Content-Length: " . strlen($data_build) . "\r\n",
		)
	));
	
	$res = file_get_contents($url_distant, false, $context);
//print $res;
	$res = json_decode($res);

	_deleteCurrentEvent($ATMdb, $res->TIdSyncEvent);
	
	if ($res->msg == 'ok') return 'Synchronisation sans erreur'; 
	else return 'Synchronisation partielle';
}

function _deleteCurrentEvent(&$ATMdb, $TIdSyncEvent)
{
	if (!is_array($TIdSyncEvent)) return 0;
	
	foreach ($TIdSyncEvent as $id_sync_event)
	{
		$syncEvent = new SyncEvent;
		$syncEvent->load($ATMdb, $id_sync_event);
		$syncEvent->delete($ATMdb);
	}
	
	return 1;
}

function _refreshData(&$ATMdb, &$conf, &$db)
{
	global $user;

	try 
	{
		dol_include_once('/core/lib/admin.lib.php');
		dol_include_once('/user/class/user.class.php');
		dol_include_once('/societe/class/client.class.php');
		dol_include_once('/product/class/product.class.php');
		dol_include_once('/fourn/class/fournisseur.product.class.php');
		dol_include_once('/product/stock/class/mouvementstock.class.php');
		dol_include_once('/compta/facture/class/facture.class.php');
		dol_include_once('/compta/paiement/class/paiement.class.php');
		dol_include_once('/compta/bank/class/account.class.php');
		dol_include_once('/core/lib/price.lib.php');
		dol_include_once('/custom/caisse/config.php');
		dol_include_once('/custom/caisse/class/caisse.class.php');
		dol_include_once('/core/class/discount.class.php');
	
		//Je lock le trigger du module pour éviter des ajouts dans llx_sync_event via le script
		dolibarr_set_const($db, 'CPFSYNC_LOCK', 1);
		dolibarr_set_const($db, 'CPFSYNC_INTERFACE_RUNNING', 1);
		
		if (!($id_user = (int) $conf->global->CPFSYNC_ID_USER) || $id_user <= 0) return 'ko';
		
		$user = new User($db);
		$user->fetch($id_user);
		if (!$user->admin) return 'ko';		
		$user->getrights(); //Load des droits
		//L'utilisateur doit avoir les droits sur les Sociétés, produits, factures et stock
		
		$data = __get('data', array());
		$res_id = array(); //Tableau contenant les rowid de la table llx_sync_event
		$msg = 'ok';
		
		$TErrors=array();
		
		foreach ($data as $row)
		{
			$object = $ATMdb->Get_field('object');
			$object = base64_decode($object_serialize, true);
			if ($object === false) $object = $ATMdb->Get_field('object');
		
			$object = unserialize($row['object_serialize']);
			
			$class = $row['type_object'];
			$doli_action = $row['doli_action'];
			
			if ($doli_action == 'PAYMENT_ADD_TO_BANK' || $object == null)
			{
				$res_id[] = $row['rowid'];
				continue;
			}
			
			
			$conf->entity = (int) $row['entity'];
		
			if (in_array($doli_action, SyncEvent::$TActionCreate))
			{
				if (_create($ATMdb, $db, $conf, $user, $class, $object, $row['facnumber']) > 0) $res_id[] = $row['rowid'];			
			}
			elseif (in_array($doli_action, SyncEvent::$TActionModify))
			{
				if (_update($ATMdb, $db, $conf, $user, $TErrors[$row['rowid']], $class, $object, $doli_action) > 0) $res_id[] = $row['rowid'];
			}
			elseif (in_array($doli_action, SyncEvent::$TActionDelete))
			{
				if (_delete($db, $conf, $class, $object, $row['facnumber']) > 0) $res_id[] = $row['rowid'];
			}
			elseif (in_array($doli_action, SyncEvent::$TActionValidate))
			{
				$exist = _isExistingObject($db, strtolower($class), $object);
				
				if ($exist) 
				{
					if (_update($ATMdb, $db, $conf, $user,$TErrors[$row['rowid']], $class, $object, $doli_action) > 0) $res_id[] = $row['rowid'];
				}
				else
				{
					if (_create($ATMdb, $db, $conf, $user, $class, $object, $row['facnumber'], $doli_action) > 0) $res_id[] = $row['rowid'];
				}
			}
			elseif (in_array($doli_action, SyncEvent::$TActionSave))
			{
				if (_save($ATMdb, $db, $conf, $class, $object) > 0) $res_id[] = $row['rowid'];
			}
			elseif (in_array($doli_action, SyncEvent::$TActionOther))
			{
				if (_other($ATMdb, $db, $conf, $class, $object, $doli_action) > 0) $res_id[] = $row['rowid'];
			}
			
		}
	}
	//finally //PHP min 5.5
	catch (Exception $e)
	{
		$msg = $e;
		mail('phf@atm-consulting.fr', 'EspritMaison', $e);
	}
	
	//Devraient être dans le finally
	dolibarr_del_const($db, 'CPFSYNC_LOCK');
	dolibarr_del_const($db, 'CPFSYNC_INTERFACE_RUNNING');
	
	return array('msg' => $msg, 'TIdSyncEvent' => $res_id, 'TErrors'=>$TErrors);
}

function _save(&$PDOdb, &$db, &$conf, $class, &$object)
{
	$sql = 'SELECT rowid FROM '.MAIN_DB_PREFIX.'caisse_bonachat WHERE numero=\''.$object->numero.'\'';
	$PDOdb->Execute($sql);
	
	if ($PDOdb->Get_line())
	{
		$ba = new TBonAchat;
		$ba->load($PDOdb, $PDOdb->Get_field('rowid'));
		
		$ba->statut = $object->statut;
		
		return $ba->save($PDOdb);
	}
	else 
	{
		_reloadMask($db, $conf);
		
		$object->rowid = 0;
		$object->force_facnumber = true;
		
		$soc = new Societe($db);
		if (_fetch($db, $conf, $soc, $object, 'Societe') > 0)
		{
			if ($object->type == 'AVOIR' && $object->ref_facture_source) { //A ne pas confondre avec $object->numero - le module caisse crée l'avoir automatiquement donc à ne pas gérer au part sinon création de 2 avoirs
				$fac = new Facture($db);
				$r = $fac->fetch(null, $object->ref_facture_source);
				
				if ($r <= 0) return 1; // On force la suppression de l'évènement car dans ce cas, si la facture n'existe le module caisse retourne une erreur
				
				$object->fk_facture = $fac->id;
			}
			
			$object->fk_soc = $soc->id;
			
			return $object->save($PDOdb);
		}
	}
	
	return -1;
}

function _create(&$ATMdb, &$db, &$conf, &$user, $class, &$object, $facnumber = '', $doli_action = '')
{
	if (_existObject($db, $conf, $object, $class) > 0) return 1; 
	
	if ($doli_action == 'BILL_PAYED') return 1; //Pas de create pour un BILL_PAYED, si on ici avec ça c'est que la facture d'avant n'existe pas.
	
	if (!is_object($object)) return 1;
	
	$localObject = clone $object;
	$localObject->id = 0;
	
	//Closure PHP c'est magique => http://www.thedarksideofthewebblog.com/les-closure-en-php/
	$initDb = function(&$db) { $this->db = &$db; };
	$initDb = Closure::bind($initDb , $localObject, $class);
	$initDb($db);
	
	if ($class == 'Facture')
	{
		//$localObject->facnumber = $localObject->getNextNumRef($localObject->client);
		_initDbFacture($db, $localObject);
	
		
	
		//Récupération du bon client en distant
		if (_fetch($db, $conf, $localObject->client, $localObject->client, 'Societe') <= 0) return -1;
		$localObject->socid = $localObject->client->id;
	
		//Récupère l'id des produits qui correspond aux référence pour garder le/les bons produits dans la facture
		foreach ($localObject->lines as &$facLine)
		{
			if ($facLine->product_ref)
			{
				$product = new Product($db);
				$product->fetch(null, $facLine->product_ref);
				$facLine->fk_product = $product->id;
			}
		}
	}
	elseif ($class == 'Paiement')
	{
		$facture = new Facture($db);
		$facture->fetch(null, $facnumber);
		//Merci dolibarr de mettre le facid dans l'indice du tableau de amount plutôt que dans l'objet Paiement
		foreach ($localObject->amounts as $key => $amount) 
		{
			unset($localObject->amounts[$key]);
			$localObject->amounts[$facture->id] = $amount;
		}
	}
	
	//La Class MouvementStock a sa propre fonction create
	if ($class == 'MouvementStock')
	{
		$product = new Product($db);
		$r = $product->fetch(null, $localObject->product_ref);
		
		if ($r <= 0)
		{
			$product = _getObjectDistance($db, $localObject, 'product');
			$product->create($user);	
		}
		
		if ($localObject->type === '')
		{
			if ($localObject->qty < 0) $localObject->type = 1; //decrease
			else $localObject->type = 0; //increase
		}
		
		//_create fonction custom de l'objet
		$res = $localObject->_create($user, $product->id, $localObject->entrepot_id, $localObject->qty, $localObject->type, $localObject->price, $localObject->label);
	}
	elseif ($class == 'ProductFournisseur')
	{
		$localObject->product_fourn_price_id = 0;
		$localObject->id = $object->id;
		$object->qty = (int)$object->qty;
		if (!$object->qty) $object->qty = 1;
		
		if (!isset($object->fournisseur)) $object->fournisseur = _getObjectDistance($db, $object, 'price_fournisseur');
		
		//update_buyprice return 0 if ok
		$res = $localObject->update_buyprice($object->qty, $object->price, $user, $object->price_base_type, $object->fournisseur, 0, $object->fourn_ref, $object->tva_tx, 0, $object->remise_percent);
		
		if ($res !== 0) $res = -1;
		else $res = 1;
	}
	else 
	{
		$res = $localObject->create($user);
	}
	
	if ($class == 'Facture' && $res)
	{
		//decompte()
		if (isset($localObject->TRefRemise))
		{
			$montant_reste = $localObject->total_ttc; //
			foreach ($localObject->TRefRemise as $ref)
			{
				//Load BonAchat by ref	
				$ba = new TBonAchat;
				$ba->loadByNumero($ATMdb, $ref);
				
				$remise = new DiscountAbsolute($db);
	    		$result = $remise->fetch($ba->fk_discount);
				
				if ($result > 0) 
				{
					$montant_reste = $ba->decompte($ATMdb, $montant_reste, $localObject->id);
				}
				
			}
		}
		
		//Permet de générer la référence
		$res = $localObject->validate($user, $object->facnumber);
	}
	
	return $res;
}

function _update(&$ATMdb, &$db, &$conf, &$user,&$TError, $class, &$object, $doli_action)
{
	$localObject = new $class($db);

    $res = _fetch($db, $conf, $localObject, $object, $class);
	
	if ($res>0)
	{
		if ($doli_action == 'BILL_PAYED')
		{
			$localObject->set_paid($user);
			return 1;
		}
		
		if ($class == 'Facture')
		{
			$oldLines = $localObject->lines;	
		}
		elseif ($class == 'ProductFournisseur')
		{
			$object->product_fourn_price_id = $localObject->product_fourn_price_id;
			$object->fk_product = $object->product_id = $localObject->fk_product;
		}
		
		$object->id = $localObject->id;
		
		if (isset($localObject->socid)) $object->socid = $localObject->socid;
		
		$localObject = clone $object;
		
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
				return $localObject->update($localObject->id, $user);
				break;
				
			case 'Product':			
				if ($doli_action == 'PRODUCT_PRICE_MODIFY') 
				{
					$newprice = $localObject->price_base_type == 'TTC' ? $localObject->price_ttc : $localObject->price;
					return $localObject->updatePrice($newprice, $localObject->price_base_type, $user, $localObject->tva_tx, $localObject->price_min);
				}
				else return $localObject->update($localObject->id, $user);
				break;
			
			case 'ProductFournisseur':
				//update_buyprice return 0 if ok
				if ($object->qty == '') $object->qty = 1;
				$res = $localObject->update_buyprice($object->qty, $object->price, $user, $object->price_base_type, $object->fournisseur, 0, $object->fourn_ref, $object->tva_tx, 0, $object->remise_percent);
				if ($res !== 0) {
					$TError[] = 'update_buyprice '.$res;
					return -1;
				
				}
				else return 1;
				
				break;
			
			default:
				return $localObject->update($user);
				break;
		}
		
	}
	else 
	{
		$TError[] = 'WARNING not udpate > create '.$class.' '.$object->id.' '.$res;
		
		return _create($ATMdb, $db, $conf, $user, $class, $object);
	}
}

function _delete(&$db, &$conf, $class, &$object, $facnumber)
{
	$localObject = new $class($db);
	
	if ($class == 'Facture' && preg_match('/^\(PROV[0-9]*\)$/', $object->ref)) return 1;
	
	if (_fetch($db, $conf, $localObject, $object, $class, $facnumber) <= 0) return -1;
	
	switch ($class) {
		case 'Societe':
			return $localObject->delete($localObject->id);
			break;
		
		case 'ProductFournisseur':
			$product = new ProductFournisseur($db);
			$product->fetch($localObject->fk_product);
			return $product->remove_product_fournisseur_price($localObject->product_fourn_price_id);
			
			break;
			
		default:
			$res = $localObject->delete();
			if ($class == 'Facture') $res = 1; //On force la suppression de l'évènement pour une facture
			
			return $res;
			break;
	}
}

function _fetch(&$db, &$conf, &$localObject, &$object, $class, $facnumber = '')
{
	$sql = 'SELECT rowid FROM '.MAIN_DB_PREFIX;
	switch ($class) 
	{
		case 'Societe':
			//Recherche sur code_client ou si non renseigné code_fournisseur
			$sql.= 'societe WHERE ';
			
			if (!$object->code_client && !$object->code_fournisseur) _getObjectDistance($db, $object, 'code_client');
			
			if ($object->code_client) $sql .= 'code_client = "'.$db->escape($object->code_client).'"';
			elseif ($object->code_fournisseur) $sql .= 'code_fournisseur = "'.$db->escape($object->code_fournisseur).'"';
			else return -1;
			
			break;
		
		case 'Product':
			//Recherche sur la ref produit
			return $localObject->fetch(null, $object->ref);
			
			break;
		
		case 'Facture':
			//Recherche sur le facnumber facture
			return $localObject->fetch(null, $object->ref);
			
			break;
			
		case 'Paiement':
			//Recherche de la ligne de paiement, impossible de ce référer à la référence facture
			$sql.= 'paiement';
			$sql.= ' WHERE datep = "'.$db->escape(date('Y-m-d H:i:s', $object->datepaye)).'"';
			$sql.= ' AND amount = '.(double) $object->amount;
			$sql.= ' AND num_paiement = "'.$db->escape($object->num_paiement).'"';
			$sql.= ' AND fk_bank = '.(int) $object->bank_line;
			$sql.= ' AND entity = '.(int) $conf->entity;
				
			break;
			
		case 'ProductFournisseur':
			$product = new Product($db);
			$fournisseur = new Societe($db);
			$object->code_client = false;
			
			//Permet de récupérer le bon fournisseur
			if (!$object->code_fournisseur) 
			{
				$societe = _getObjectDistance($db, $object, 'price_fournisseur');
				$object->code_fournisseur = $societe->code_fournisseur;	
			}
	
			//Dans le cas d'un prix fournisseur je doit vérifier si le fournisseur et le produit existe pour récupérer leurs Id
			if (_fetch($db, $conf, $fournisseur, $object, 'Societe') > 0 && $product->fetch(null, $object->ref))
			{
				if (!$object->fourn_ref) $object->fourn_ref = _getObjectDistance($db, $object, 'ref_price_fournisseur');
				
				$object->id = $product->id;
				$object->fk_soc = $fournisseur->id;
				$object->ref_supplier = $fournisseur->code_fournisseur;
				$object->fournisseur = $fournisseur;
				
				//Si le prix fournisseur existe je fait un update_buyprice sinon c'est un add_fournisseur en sortie
				$sql.= 'product_fournisseur_price';
	    		$sql.= ' WHERE fk_soc = '.$fournisseur->id;
	    		$sql.= ' AND ref_fourn = "'.$db->escape($object->fourn_ref).'"'; //Ref de la ligne de prix
	    		$sql.= " AND fk_product = ".$product->id;
	    		$sql.= " AND entity = ".$conf->entity;
			}
			else {
				return -2;
			}
			
			break;
			
		case 'DiscountAbsolute':
			$sql.= 'societe_remise_except WHERE fk_soc = '.(int) $object->fk_soc.' AND fk_facture_source = '.(int) $object->fk_facture_source;
			$sql.= ' ORDER BY rowid DESC LIMIT 1';
			//A voir si on test aussi sur amout_ttc
			break;
			
		default:
			return -1;
			break;
	}
	
	$resql = $db->query($sql);
	if ($db->num_rows($resql))
	{
		$obj = $db->fetch_object($resql);
		
		if ($class == "ProductFournisseur") 
		{
			return $localObject->fetch_product_fournisseur_price($obj->rowid);
		}
		else 
		{
			return $localObject->fetch($obj->rowid);
		}	
	}
	
	return -1;
}

function _other(&$PDOdb, &$db, &$conf, $class, &$object, $doli_action)
{
	
	switch ($doli_action) {
		case 'DISCOUNT_LINK_TO_INVOICE':
		case 'DISCOUNT_UNLINK_INVOICE':
			
			//fetch de la facture pour son id
			$facture = new Facture($db);
			if (!$facture->fetch(null, $object->ref_facture)) return -1;
			
			//fetch de la facture source pour son id
			$factureSource = new Facture($db);
			if (!$factureSource->fetch(null, $object->ref_facture_source)) return -2;
			
			//fetch du client pour son id
			$societe = new Societe($db);
			if (_fetch($db, $conf, $societe, $object, 'Societe') <= 0) return -3;
			
			$object->fk_soc = $societe->id;
			$object->fk_facture = $facture->id;
			$object->fk_facture_source = $factureSource->id;
			
			//object DiscountAbsolute
			$localObject = new $class($db);
			if (_fetch($db, $conf, $localObject, $object, $class) <= 0) return -4;
			
			//Link or unlink
			if ($doli_action == 'DISCOUNT_LINK_TO_INVOICE') return $localObject->link_to_invoice(0,$facture->id);
			else return $localObject->unlink_invoice();
			
			break;
		
		case 'CAISSE_BON_ACHAT_DECOMPTE':
			$sql = 'SELECT rowid FROM '.MAIN_DB_PREFIX.'caisse_bonachat WHERE date_cre = "'.$db->escape($object->get_date('date_cre', 'Y-m-d H:i:s')).'"';
			$PDOdb->Execute($sql);
			
			if ($PDOdb->Get_line())
			{
				$ba = new TBonAchat;
				$ba->load($PDOdb, $PDOdb->Get_field('rowid'));
				
				//fetch de la facture pour son id
				$facture = new Facture($db);
				if (!$facture->fetch(null, $object->ref_facture_target)) return -1;
				
				$remise = new DiscountAbsolute($db);
        		$result = $remise->fetch($ba->fk_discount);
				
				if ($result > 0) $ba->decompte($PDOdb, $object->amount_to_substract, $facture->id);
				return 1;
			}
			
			return 1; //On force la suppression de l'évènement 
			break;
			
		default:
			return 0;
			break;
			
	}
	
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
		$fk_product = 0;
		if ($newline->product_ref)
		{
			$product = new Product($db);
			$product->fetch(null, $newline->product_ref);
			$fk_product = $product->id;
		}
		$localObject->addline($newline->desc, $newline->subprice, $newline->qty, $newline->tva_tx, $newline->localtax1_tx, $newline->localtax2_tx, $fk_product, $newline->remise_percent, $newline->date_start, $newline->date_end, $newline->fk_code_ventilation, $newline->info_bits, $newline->fk_remise_except, ($newline->total_tva > 0 || !$newline->fk_product ? 'HT' : 'TTC'), ($newline->subprice * (1 + ($newline->tva_tx / 100))), $newline->product_type, $newline->rang, $newline->special_code, $newline->origin, $newline->origin_id, $newline->fk_parent_line, $newline->fk_fournprice, $newline->pa_ht, $newline->label, $newline->array_options);
	}
	
	$localObject->brouillon = 0;
}

/*
 * Fonction custom qui reprend la fonction de la class Commonobject
 * check uniquement sur un id et ne fait pas de select ref qui n'existe pas dans la table llx_societe par exemple
 */
function _isExistingObject(&$db, $element, &$object)
{
	$champ = "facnumber";
	$ref = $object->ref;
	
	$sql = 'SELECT rowid';
	$sql.= ' FROM '.MAIN_DB_PREFIX.$element;
	if (!empty($ref)) $sql.= ' WHERE '.$champ.' = "'.$db->escape($ref).'"';
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

function _existObject(&$db, &$conf, &$localObject, $class)
{
	switch ($class) {
		case 'Product':
			$product = new Product($db);
			return $product->fetch(null, $localObject->ref);
			break;
		
		case 'Societe':
			$societe = new Societe($db);
			return _fetch($db, $conf, $societe, $localObject, $class);
			break;
		default:
			return 0;
			break;
	}
}

function _getObjectDistance(&$db, &$object, $type)
{
	global $conf;
	$url = $conf->global->CPFSYNC_URL_DISTANT;
	
	$url .= '/custom/cpfsync/script/interface.php';
	
	$data['action'] = 'getObject';
	$data['type'] = $type;
	
	if ($type == 'price_fournisseur')
	{
		$data['ref_product'] = $object->ref;
	}
	elseif ($type == 'product') 
	{
		$data['ref_product'] = $object->product_ref;
	}
	elseif ($type == 'ref_price_fournisseur')
	{
		$data['product_fourn_price_id'] = $object->product_fourn_price_id; //il s'agit de l'id d'origine donc je peux le renvoyer tel quel pour récupérer la référence
	}
	elseif ($type == 'code_client')
	{
		$data['fk_soc'] = $object->id;
	}
	
	$data_build  = http_build_query($data);
	
	$context = stream_context_create(array(
		'http' => array(
		    'method' => 'POST'
		    ,'content' => $data_build
		    ,'timeout' => 5 //Si je n'ai pas de réponse dans les 10sec ma requête http s'arrête
		    ,'header'=> "Content-type: application/x-www-form-urlencoded\r\n"
                . "Content-Length: " . strlen($data_build) . "\r\n",
		)
	));
	
	$res = json_decode(file_get_contents($url, false, $context));
	
	if ($type == 'price_fournisseur')
	{
		$fournisseur = new Societe($db);
		$fournisseur->code_fournisseur = $res;
		_fetch($db, $conf, $fournisseur, $fournisseur, 'Societe');
		return $fournisseur;
	}
	elseif ($type == 'product') 
	{
		$product = new Product($db);
		$res = unserialize($res);
		$product = $res;
		
		$initDb = function(&$db) { $this->db = &$db; };
		$initDb = Closure::bind($initDb , $product, 'Product');
		$initDb($db);
		
		return $product;
	}
	elseif ($type == 'ref_price_fournisseur')
	{
		return $res;
	}
	elseif ($type == 'code_client')
	{
		$res = unserialize($res);
		$object->code_client =	$res['code_client'];
		$object->code_fournisseur =	$res['code_fournisseur'];
		
		return 1;
	}
	
	return 0;	
}

function _sendObject(&$PDOdb, &$conf, &$db)
{
	$type = __get('type');
	
	switch ($type) 
	{
		case 'price_fournisseur':
			dol_include_once('/fourn/class/fournisseur.class.php');
			
			$ref = __get('ref_product');
			$product = new ProductFournisseur($db);
			$product->fetch(null, $ref);
			
			$list = $product->list_product_fournisseur_price($product->id);
			
			if ($list == -1) return false;
		
			$fournisseur = new Societe($db);
			$fournisseur->fetch($list[0]->fourn_id);
			
			return $fournisseur->code_fournisseur;
			break;
		
		case 'product':
			dol_include_once('/product/class/product.class.php');
			
			$product = new Product($db);
			$product->fetch(null, __get('ref_product'));
			
			return serialize($product);
			break;	
			
		case 'ref_price_fournisseur':
			dol_include_once('/fourn/class/fournisseur.product.class.php');
			
			$productFournisseur = new ProductFournisseur($db);
			$productFournisseur->fetch_product_fournisseur_price(__get('product_fourn_price_id'));
			
			return $productFournisseur->fourn_ref;
			break;
			
		case 'code_client':
			dol_include_once('/societe/class/client.class.php');
			
			$res = array('code_client' => '', 'code_fournisseur' => '');
			$client = new Societe($db);
			$client->fetch(__get('fk_soc'));
			
			$res['code_client'] = $client->code_client;
			$res['code_fournisseur'] = $client->code_fournisseur;
			
			return serialize($res);
			break;
			
		default:
			return false;
			break;
	}
}

function _reloadMask(&$db, &$conf)
{
	$conf->global->FACTURE_ADDON = dolibarr_get_const($db, 'FACTURE_ADDON', $conf->entity);
	
	if ($conf->global->FACTURE_ADDON == 'mod_facture_mercure') 
	{
		//Je recharge le mask en fonction de entity
		$conf->global->FACTURE_MERCURE_MASK_INVOICE = dolibarr_get_const($db, 'FACTURE_MERCURE_MASK_INVOICE', $conf->entity);
		$conf->global->FACTURE_MERCURE_MASK_REPLACEMENT = dolibarr_get_const($db, 'FACTURE_MERCURE_MASK_REPLACEMENT', $conf->entity);
		$conf->global->FACTURE_MERCURE_MASK_CREDIT = dolibarr_get_const($db, 'FACTURE_MERCURE_MASK_CREDIT', $conf->entity);
		$conf->global->FACTURE_MERCURE_MASK_DEPOSIT = dolibarr_get_const($db, 'FACTURE_MERCURE_MASK_DEPOSIT', $conf->entity);	
	}
	
}
