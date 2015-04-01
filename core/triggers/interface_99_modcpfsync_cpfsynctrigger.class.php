<?php
/* <one line to give the program's name and a brief idea of what it does.>
 * Copyright (C) 2015 ATM Consulting <support@atm-consulting.fr>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 */

/**
 * 	\file		core/triggers/interface_99_modMyodule_cpfsynctrigger.class.php
 * 	\ingroup	cpfsync
 * 	\brief		Sample trigger
 * 	\remarks	You can create other triggers by copying this one
 * 				- File name should be either:
 * 					interface_99_modMymodule_Mytrigger.class.php
 * 					interface_99_all_Mytrigger.class.php
 * 				- The file must stay in core/triggers
 * 				- The class name must be InterfaceMytrigger
 * 				- The constructor method must be named InterfaceMytrigger
 * 				- The name property name must be Mytrigger
 */

/**
 * Trigger class
 */
class Interfacecpfsynctrigger
{

    private $db;

    /**
     * Constructor
     *
     * 	@param		DoliDB		$db		Database handler
     */
    public function __construct($db)
    {
        $this->db = $db;

        $this->name = preg_replace('/^Interface/i', '', get_class($this));
        $this->family = "ATM";
        $this->description = "Trigger du module cpfsync.";
        // 'development', 'experimental', 'dolibarr' or version
        $this->version = 'development';
        $this->picto = 'cpfsync@cpfsync';
    }

    /**
     * Trigger name
     *
     * 	@return		string	Name of trigger file
     */
    public function getName()
    {
        return $this->name;
    }

    /**
     * Trigger description
     *
     * 	@return		string	Description of trigger file
     */
    public function getDesc()
    {
        return $this->description;
    }

    /**
     * Trigger version
     *
     * 	@return		string	Version of trigger file
     */
    public function getVersion()
    {
        global $langs;
        $langs->load("admin");

        if ($this->version == 'development') {
            return $langs->trans("Development");
        } elseif ($this->version == 'experimental')

                return $langs->trans("Experimental");
        elseif ($this->version == 'dolibarr') return DOL_VERSION;
        elseif ($this->version) return $this->version;
        else {
            return $langs->trans("Unknown");
        }
    }

	public function insert_sync_event(&$conf, $object, $type_object, $action, $facnumber='', $entity=1)
	{
		$PDOdb = new TPDOdb;
		$event = new SyncEvent;
		
		$event->object = serialize($object);
		$event->type_object = $type_object;
		$event->doli_action = $action;
		$event->facnumber = $facnumber;
		$event->entity = __val($entity, $conf->entity, 'int', true);
		
		$event->save($PDOdb);
	}
	
	
    /**
     * Function called when a Dolibarrr business event is done.
     * All functions "run_trigger" are triggered if file
     * is inside directory core/triggers
     *
     * 	@param		string		$action		Event action code
     * 	@param		Object		$object		Object
     * 	@param		User		$user		Object user
     * 	@param		Translate	$langs		Object langs
     * 	@param		conf		$conf		Object conf
     * 	@return		int						<0 if KO, 0 if no triggered ran, >0 if OK
     */
    public function run_trigger($action, $object, $user, $langs, $conf)
    {
    	global $db;
		
    	if (!defined('INC_FROM_DOLIBARR')) define('INC_FROM_DOLIBARR',true);
    	dol_include_once('/cpfsync/config.php');
		dol_include_once('/cpfsync/class/cpfsync.class.php');
		
		//Module Caisse - Quand on créé un bon d'achat, l'objet TBonAchat va exécuter un BILL_VALIDATE et un PAYMENT_CUSTOMER_CREATE - l'objectif est de laisser TBonAchat
		//faire la création sur le 2ème Dolibarr car le module caisse fait du traitement supplémentaire
		if (empty($conf->global->CPFSYNC_INTERFACE_RUNNING) && $action == 'CAISSE_BON_ACHAT_BEFORE_CREATE_FACTURE')
		{
			dolibarr_set_const($db, 'CPFSYNC_LOCK', 1);
		}
		elseif (empty($conf->global->CPFSYNC_INTERFACE_RUNNING) && $action == 'CAISSE_BON_ACHAT_AFTER_CREATE_FACTURE')
		{
			dolibarr_del_const($db, 'CPFSYNC_LOCK');
		}
		
		//Permet de bloquer les actions
    	if (!empty($conf->global->CPFSYNC_LOCK)) return 0;
				
		$type_object = false;
		$facnumber = '';
		
		// Companies / Customers
        if (!empty($conf->global->CPFSYNC_SHARE_CUSTOMER) && ($action == 'COMPANY_CREATE' || $action == 'COMPANY_MODIFY' || $action == 'COMPANY_DELETE')) 
        {
        	$this->insert_sync_event($conf, $object, 'Societe', $action, '', $object->entity);
			
            dol_syslog("Trigger '" . $this->name . "' for action '$action' launched by " . __FILE__ . ". id=" . $object->id);
        }
		
		// Products / services
		elseif (!empty($conf->global->CPFSYNC_SHARE_PRODUCT) && ($action == 'PRODUCT_CREATE' || $action == 'PRODUCT_MODIFY' || $action == 'PRODUCT_DELETE' || $action == 'PRODUCT_PRICE_MODIFY')) 
		{
			$this->insert_sync_event($conf, $object, 'Product', $action, '', $object->entity);
			
            dol_syslog("Trigger '" . $this->name . "' for action '$action' launched by " . __FILE__ . ". id=" . $object->id);
        } 
		
		// Supplier price
		elseif (!empty($conf->global->CPFSYNC_SHARE_PRODUCT) && ($action == 'SUPPLIER_PRODUCT_BUYPRICE_UPDATE' || $action == 'SUPPLIER_PRODUCT_BUYPRICE_REMOVE'))
		{
			if ($action == 'SUPPLIER_PRODUCT_BUYPRICE_REMOVE')
			{
				$object->fetch_product_fournisseur_price(GETPOST('rowid'));
				
				$fourn = new Fournisseur($db);
				$fourn->fetch((int) GETPOST('socid'));
				$object->code_fournisseur = $fourn->code_fournisseur; //Référence du fournisseur (permet le fetch dans interface)
			}
			else
			{
				$object->fourn_ref = $object->ref_supplier = GETPOST('ref_fourn'); //Référence de la ligne prix attention ->fourn_ref deprecated
				$object->price = GETPOST('price');
				$object->qty = GETPOST('qty');
				$object->remise_percent = GETPOST('remise_percent');
				
				$fourn = new Fournisseur($db);
				$fourn->fetch((int) GETPOST('id_fourn'));
				$object->code_fournisseur = $fourn->code_fournisseur; //Référence du fournisseur (permet le fetch dans interface)
			}
			
			$this->insert_sync_event($conf, $object, 'ProductFournisseur', $action, '', $object->entity);
			
			dol_syslog("Trigger '" . $this->name . "' for action '$action' launched by " . __FILE__ . ". id=" . $object->id);
		}
		
		// Bills
		elseif (!empty($conf->global->CPFSYNC_SHARE_INVOICE) && ($action == 'BILL_VALIDATE' || $action == 'BILL_DELETE' || $action == 'BILL_PAYED')) 
		{
			$this->insert_sync_event($conf, $object, 'Facture', $action, '', $object->entity);
			
            dol_syslog("Trigger '" . $this->name . "' for action '$action' launched by " . __FILE__ . ". id=" . $object->id);
        }
        
		// Module caisse - bon achat / cadeau
		elseif ($action == 'CAISSE_BON_ACHAT_SAVE')
		{
			//l'objet contient déjà ->numero == ref facture
			
			//Récupération du code_client et code_fournisseur
			$soc = new Societe($db);
			$soc->fetch($object->fk_soc);
			$object->code_client = $soc->code_client;
			$object->code_fournisseur = $soc->code_fournisseur;
			
			$this->insert_sync_event($conf, $object, 'TBonAchat', $action, '', $object->entity);
			
			dol_syslog("Trigger '" . $this->name . "' for action '$action' launched by " . __FILE__ . ". id=" . $object->getId());
		}
		
		// Association du bon d'achat à la facture
		if ($action == 'DISCOUNT_LINK_TO_INVOICE' || $action == 'DISCOUNT_UNLINK_INVOICE')
		{
			/*
			 * $object->ref_facture //by me == ticket
			 * $object->ref_facture_source //natif facture d'avoir
			 */ 
			
        	//Récupération du facnumber
        	$facture = new Facture($db);
			$facture->fetch($object->fk_facture);
			$object->ref_facture = $facture->ref; // ref == facnumber        
			
			
			if (substr($object->ref_facture, 0, 5) == '(PROV') return 0;
			//if (preg_match('/^[\(]?PROV/i', $this->ref))
			
			//Récupération du code_client et code_fournisseur
			$soc = new Societe($db);
			$soc->fetch($object->fk_soc);
			$object->code_client = $soc->code_client;
			$object->code_fournisseur = $soc->code_fournisseur;
			
			$this->insert_sync_event($conf, $object, 'DiscountAbsolute', $action, '', $conf->entity);
			
			dol_syslog("Trigger '" . $this->name . "' for action '$action' launched by " . __FILE__ . ". id=" . $object->id);
		}
		
		// Payments
        elseif (!empty($conf->global->CPFSYNC_SHARE_INVOICE) && ($action == 'PAYMENT_CUSTOMER_CREATE' || $action == 'PAYMENT_DELETE' || $action == 'PAYMENT_ADD_TO_BANK')) 
        {
			//TODO dans le cas d'un PAYMENT_DELETE il faudrait trouver le moyen de récupérer le facid de l'object ($object->facid = null et impossible de faire une requête sql)
			//$object->getBillsArray() est senssé renvoyer la liste des factures sur lesquels porte le paiement mais retourne array vide
			$facture = new Facture($db);
			$facture->fetch(GETPOST('facid') ? GETPOST('facid') : $object->facid);
			$facnumber = $facture->ref; // ref == facnumber
			
			$this->insert_sync_event($conf, $object, 'Paiement', $action, $facnumber, $object->entity);
			
            dol_syslog("Trigger '" . $this->name . "' for action '$action' launched by " . __FILE__ . ". id=" . $object->id);
        }
		
		// Movement stock
		elseif (!empty($conf->global->CPFSYNC_SHARE_STOCK) && $action == 'STOCK_MOVEMENT')
		{			
			$product = new Product($db);
			$product->fetch($object->product_id);
			
			$object->product_ref = $product->ref;
			if (isset($object->origin))
			{
				$object->type = (substr($object->qty, 0,1) == '-') ? 2 : 3;
				$object->price = '';
				$object->label = '';
			}
			else 
			{
				$object->type = GETPOST('mouvement'); //type du mouvement
				$object->price = GETPOST('price');
				$object->label = GETPOST('label');
			}
					
			$this->insert_sync_event($conf, $object, 'MouvementStock', $action, '', $object->entity);
				
			dol_syslog("Trigger '" . $this->name . "' for action '$action' launched by " . __FILE__ . ". entrepot_id=" . $object->entrepot_id);
		}
		
		
        return 0;
        
        // Put here code you want to execute when a Dolibarr business events occurs.
        // Data and type of action are stored into $object and $action
        // Users
        /*
        if ($action == 'USER_LOGIN') {
            dol_syslog(
                "Trigger '" . $this->name . "' for action '$action' launched by " . __FILE__ . ". id=" . $object->id
            );
        } elseif ($action == 'USER_UPDATE_SESSION') {
            // Warning: To increase performances, this action is triggered only if
            // constant MAIN_ACTIVATE_UPDATESESSIONTRIGGER is set to 1.
            dol_syslog(
                "Trigger '" . $this->name . "' for action '$action' launched by " . __FILE__ . ". id=" . $object->id
            );
        } elseif ($action == 'USER_CREATE') {
            dol_syslog(
                "Trigger '" . $this->name . "' for action '$action' launched by " . __FILE__ . ". id=" . $object->id
            );
        } elseif ($action == 'USER_CREATE_FROM_CONTACT') {
            dol_syslog(
                "Trigger '" . $this->name . "' for action '$action' launched by " . __FILE__ . ". id=" . $object->id
            );
        } elseif ($action == 'USER_MODIFY') {
            dol_syslog(
                "Trigger '" . $this->name . "' for action '$action' launched by " . __FILE__ . ". id=" . $object->id
            );
        } elseif ($action == 'USER_NEW_PASSWORD') {
            dol_syslog(
                "Trigger '" . $this->name . "' for action '$action' launched by " . __FILE__ . ". id=" . $object->id
            );
        } elseif ($action == 'USER_ENABLEDISABLE') {
            dol_syslog(
                "Trigger '" . $this->name . "' for action '$action' launched by " . __FILE__ . ". id=" . $object->id
            );
        } elseif ($action == 'USER_DELETE') {
            dol_syslog(
                "Trigger '" . $this->name . "' for action '$action' launched by " . __FILE__ . ". id=" . $object->id
            );
        } elseif ($action == 'USER_LOGOUT') {
            dol_syslog(
                "Trigger '" . $this->name . "' for action '$action' launched by " . __FILE__ . ". id=" . $object->id
            );
        } elseif ($action == 'USER_SETINGROUP') {
            dol_syslog(
                "Trigger '" . $this->name . "' for action '$action' launched by " . __FILE__ . ". id=" . $object->id
            );
        } elseif ($action == 'USER_REMOVEFROMGROUP') {
            dol_syslog(
                "Trigger '" . $this->name . "' for action '$action' launched by " . __FILE__ . ". id=" . $object->id
            );
        }

        // Groups
        elseif ($action == 'GROUP_CREATE') {
            dol_syslog(
                "Trigger '" . $this->name . "' for action '$action' launched by " . __FILE__ . ". id=" . $object->id
            );
        } elseif ($action == 'GROUP_MODIFY') {
            dol_syslog(
                "Trigger '" . $this->name . "' for action '$action' launched by " . __FILE__ . ". id=" . $object->id
            );
        } elseif ($action == 'GROUP_DELETE') {
            dol_syslog(
                "Trigger '" . $this->name . "' for action '$action' launched by " . __FILE__ . ". id=" . $object->id
            );
        }
		*/
		
        

		/*
        // Contacts
        elseif ($action == 'CONTACT_CREATE') {
            dol_syslog(
                "Trigger '" . $this->name . "' for action '$action' launched by " . __FILE__ . ". id=" . $object->id
            );
        } elseif ($action == 'CONTACT_MODIFY') {
            dol_syslog(
                "Trigger '" . $this->name . "' for action '$action' launched by " . __FILE__ . ". id=" . $object->id
            );
        } elseif ($action == 'CONTACT_DELETE') {
            dol_syslog(
                "Trigger '" . $this->name . "' for action '$action' launched by " . __FILE__ . ". id=" . $object->id
            );
        }
		*/


		/*
        // Customer orders
        elseif ($action == 'ORDER_CREATE') {
            dol_syslog(
                "Trigger '" . $this->name . "' for action '$action' launched by " . __FILE__ . ". id=" . $object->id
            );
        } elseif ($action == 'ORDER_CLONE') {
            dol_syslog(
                "Trigger '" . $this->name . "' for action '$action' launched by " . __FILE__ . ". id=" . $object->id
            );
        } elseif ($action == 'ORDER_VALIDATE') {
            dol_syslog(
                "Trigger '" . $this->name . "' for action '$action' launched by " . __FILE__ . ". id=" . $object->id
            );
        } elseif ($action == 'ORDER_DELETE') {
            dol_syslog(
                "Trigger '" . $this->name . "' for action '$action' launched by " . __FILE__ . ". id=" . $object->id
            );
        } elseif ($action == 'ORDER_BUILDDOC') {
            dol_syslog(
                "Trigger '" . $this->name . "' for action '$action' launched by " . __FILE__ . ". id=" . $object->id
            );
        } elseif ($action == 'ORDER_SENTBYMAIL') {
            dol_syslog(
                "Trigger '" . $this->name . "' for action '$action' launched by " . __FILE__ . ". id=" . $object->id
            );
        } elseif ($action == 'LINEORDER_INSERT') {
            dol_syslog(
                "Trigger '" . $this->name . "' for action '$action' launched by " . __FILE__ . ". id=" . $object->id
            );
        } elseif ($action == 'LINEORDER_DELETE') {
            dol_syslog(
                "Trigger '" . $this->name . "' for action '$action' launched by " . __FILE__ . ". id=" . $object->id
            );
        }

        // Supplier orders
        elseif ($action == 'ORDER_SUPPLIER_CREATE') {
            dol_syslog(
                "Trigger '" . $this->name . "' for action '$action' launched by " . __FILE__ . ". id=" . $object->id
            );
        } elseif ($action == 'ORDER_SUPPLIER_VALIDATE') {
            dol_syslog(
                "Trigger '" . $this->name . "' for action '$action' launched by " . __FILE__ . ". id=" . $object->id
            );
        } elseif ($action == 'ORDER_SUPPLIER_SENTBYMAIL') {
            dol_syslog(
                "Trigger '" . $this->name . "' for action '$action' launched by " . __FILE__ . ". id=" . $object->id
            );
        } elseif ($action == 'SUPPLIER_ORDER_BUILDDOC') {
            dol_syslog(
                "Trigger '" . $this->name . "' for action '$action' launched by " . __FILE__ . ". id=" . $object->id
            );
        }

        // Proposals
        elseif ($action == 'PROPAL_CREATE') {
            dol_syslog(
                "Trigger '" . $this->name . "' for action '$action' launched by " . __FILE__ . ". id=" . $object->id
            );
        } elseif ($action == 'PROPAL_CLONE') {
            dol_syslog(
                "Trigger '" . $this->name . "' for action '$action' launched by " . __FILE__ . ". id=" . $object->id
            );
        } elseif ($action == 'PROPAL_MODIFY') {
            dol_syslog(
                "Trigger '" . $this->name . "' for action '$action' launched by " . __FILE__ . ". id=" . $object->id
            );
        } elseif ($action == 'PROPAL_VALIDATE') {
            dol_syslog(
                "Trigger '" . $this->name . "' for action '$action' launched by " . __FILE__ . ". id=" . $object->id
            );
        } elseif ($action == 'PROPAL_BUILDDOC') {
            dol_syslog(
                "Trigger '" . $this->name . "' for action '$action' launched by " . __FILE__ . ". id=" . $object->id
            );
        } elseif ($action == 'PROPAL_SENTBYMAIL') {
            dol_syslog(
                "Trigger '" . $this->name . "' for action '$action' launched by " . __FILE__ . ". id=" . $object->id
            );
        } elseif ($action == 'PROPAL_CLOSE_SIGNED') {
            dol_syslog(
                "Trigger '" . $this->name . "' for action '$action' launched by " . __FILE__ . ". id=" . $object->id
            );
        } elseif ($action == 'PROPAL_CLOSE_REFUSED') {
            dol_syslog(
                "Trigger '" . $this->name . "' for action '$action' launched by " . __FILE__ . ". id=" . $object->id
            );
        } elseif ($action == 'PROPAL_DELETE') {
            dol_syslog(
                "Trigger '" . $this->name . "' for action '$action' launched by " . __FILE__ . ". id=" . $object->id
            );
        } elseif ($action == 'LINEPROPAL_INSERT') {
            dol_syslog(
                "Trigger '" . $this->name . "' for action '$action' launched by " . __FILE__ . ". id=" . $object->id
            );
        } elseif ($action == 'LINEPROPAL_MODIFY') {
            dol_syslog(
                "Trigger '" . $this->name . "' for action '$action' launched by " . __FILE__ . ". id=" . $object->id
            );
        } elseif ($action == 'LINEPROPAL_DELETE') {
            dol_syslog(
                "Trigger '" . $this->name . "' for action '$action' launched by " . __FILE__ . ". id=" . $object->id
            );
        }

        // Contracts
        elseif ($action == 'CONTRACT_CREATE') {
            dol_syslog(
                "Trigger '" . $this->name . "' for action '$action' launched by " . __FILE__ . ". id=" . $object->id
            );
        } elseif ($action == 'CONTRACT_MODIFY') {
            dol_syslog(
                "Trigger '" . $this->name . "' for action '$action' launched by " . __FILE__ . ". id=" . $object->id
            );
        } elseif ($action == 'CONTRACT_ACTIVATE') {
            dol_syslog(
                "Trigger '" . $this->name . "' for action '$action' launched by " . __FILE__ . ". id=" . $object->id
            );
        } elseif ($action == 'CONTRACT_CANCEL') {
            dol_syslog(
                "Trigger '" . $this->name . "' for action '$action' launched by " . __FILE__ . ". id=" . $object->id
            );
        } elseif ($action == 'CONTRACT_CLOSE') {
            dol_syslog(
                "Trigger '" . $this->name . "' for action '$action' launched by " . __FILE__ . ". id=" . $object->id
            );
        } elseif ($action == 'CONTRACT_DELETE') {
            dol_syslog(
                "Trigger '" . $this->name . "' for action '$action' launched by " . __FILE__ . ". id=" . $object->id
            );
        }
        */

        /*
        // Payments
        elseif ($action == 'PAYMENT_CUSTOMER_CREATE') {
            dol_syslog(
                "Trigger '" . $this->name . "' for action '$action' launched by " . __FILE__ . ". id=" . $object->id
            );
        } elseif ($action == 'PAYMENT_SUPPLIER_CREATE') {
            dol_syslog(
                "Trigger '" . $this->name . "' for action '$action' launched by " . __FILE__ . ". id=" . $object->id
            );
        } elseif ($action == 'PAYMENT_ADD_TO_BANK') {
            dol_syslog(
                "Trigger '" . $this->name . "' for action '$action' launched by " . __FILE__ . ". id=" . $object->id
            );
        } elseif ($action == 'PAYMENT_DELETE') {
            dol_syslog(
                "Trigger '" . $this->name . "' for action '$action' launched by " . __FILE__ . ". id=" . $object->id
            );
        }

        // Interventions
        elseif ($action == 'FICHEINTER_CREATE') {
            dol_syslog(
                "Trigger '" . $this->name . "' for action '$action' launched by " . __FILE__ . ". id=" . $object->id
            );
        } elseif ($action == 'FICHEINTER_MODIFY') {
            dol_syslog(
                "Trigger '" . $this->name . "' for action '$action' launched by " . __FILE__ . ". id=" . $object->id
            );
        } elseif ($action == 'FICHEINTER_VALIDATE') {
            dol_syslog(
                "Trigger '" . $this->name . "' for action '$action' launched by " . __FILE__ . ". id=" . $object->id
            );
        } elseif ($action == 'FICHEINTER_DELETE') {
            dol_syslog(
                "Trigger '" . $this->name . "' for action '$action' launched by " . __FILE__ . ". id=" . $object->id
            );
        }

        // Members
        elseif ($action == 'MEMBER_CREATE') {
            dol_syslog(
                "Trigger '" . $this->name . "' for action '$action' launched by " . __FILE__ . ". id=" . $object->id
            );
        } elseif ($action == 'MEMBER_VALIDATE') {
            dol_syslog(
                "Trigger '" . $this->name . "' for action '$action' launched by " . __FILE__ . ". id=" . $object->id
            );
        } elseif ($action == 'MEMBER_SUBSCRIPTION') {
            dol_syslog(
                "Trigger '" . $this->name . "' for action '$action' launched by " . __FILE__ . ". id=" . $object->id
            );
        } elseif ($action == 'MEMBER_MODIFY') {
            dol_syslog(
                "Trigger '" . $this->name . "' for action '$action' launched by " . __FILE__ . ". id=" . $object->id
            );
        } elseif ($action == 'MEMBER_NEW_PASSWORD') {
            dol_syslog(
                "Trigger '" . $this->name . "' for action '$action' launched by " . __FILE__ . ". id=" . $object->id
            );
        } elseif ($action == 'MEMBER_RESILIATE') {
            dol_syslog(
                "Trigger '" . $this->name . "' for action '$action' launched by " . __FILE__ . ". id=" . $object->id
            );
        } elseif ($action == 'MEMBER_DELETE') {
            dol_syslog(
                "Trigger '" . $this->name . "' for action '$action' launched by " . __FILE__ . ". id=" . $object->id
            );
        }

        // Categories
        elseif ($action == 'CATEGORY_CREATE') {
            dol_syslog(
                "Trigger '" . $this->name . "' for action '$action' launched by " . __FILE__ . ". id=" . $object->id
            );
        } elseif ($action == 'CATEGORY_MODIFY') {
            dol_syslog(
                "Trigger '" . $this->name . "' for action '$action' launched by " . __FILE__ . ". id=" . $object->id
            );
        } elseif ($action == 'CATEGORY_DELETE') {
            dol_syslog(
                "Trigger '" . $this->name . "' for action '$action' launched by " . __FILE__ . ". id=" . $object->id
            );
        }

        // Projects
        elseif ($action == 'PROJECT_CREATE') {
            dol_syslog(
                "Trigger '" . $this->name . "' for action '$action' launched by " . __FILE__ . ". id=" . $object->id
            );
        } elseif ($action == 'PROJECT_MODIFY') {
            dol_syslog(
                "Trigger '" . $this->name . "' for action '$action' launched by " . __FILE__ . ". id=" . $object->id
            );
        } elseif ($action == 'PROJECT_DELETE') {
            dol_syslog(
                "Trigger '" . $this->name . "' for action '$action' launched by " . __FILE__ . ". id=" . $object->id
            );
        }

        // Project tasks
        elseif ($action == 'TASK_CREATE') {
            dol_syslog(
                "Trigger '" . $this->name . "' for action '$action' launched by " . __FILE__ . ". id=" . $object->id
            );
        } elseif ($action == 'TASK_MODIFY') {
            dol_syslog(
                "Trigger '" . $this->name . "' for action '$action' launched by " . __FILE__ . ". id=" . $object->id
            );
        } elseif ($action == 'TASK_DELETE') {
            dol_syslog(
                "Trigger '" . $this->name . "' for action '$action' launched by " . __FILE__ . ". id=" . $object->id
            );
        }

        // Task time spent
        elseif ($action == 'TASK_TIMESPENT_CREATE') {
            dol_syslog(
                "Trigger '" . $this->name . "' for action '$action' launched by " . __FILE__ . ". id=" . $object->id
            );
        } elseif ($action == 'TASK_TIMESPENT_MODIFY') {
            dol_syslog(
                "Trigger '" . $this->name . "' for action '$action' launched by " . __FILE__ . ". id=" . $object->id
            );
        } elseif ($action == 'TASK_TIMESPENT_DELETE') {
            dol_syslog(
                "Trigger '" . $this->name . "' for action '$action' launched by " . __FILE__ . ". id=" . $object->id
            );
        }

        // Shipping
        elseif ($action == 'SHIPPING_CREATE') {
            dol_syslog(
                "Trigger '" . $this->name . "' for action '$action' launched by " . __FILE__ . ". id=" . $object->id
            );
        } elseif ($action == 'SHIPPING_MODIFY') {
            dol_syslog(
                "Trigger '" . $this->name . "' for action '$action' launched by " . __FILE__ . ". id=" . $object->id
            );
        } elseif ($action == 'SHIPPING_VALIDATE') {
            dol_syslog(
                "Trigger '" . $this->name . "' for action '$action' launched by " . __FILE__ . ". id=" . $object->id
            );
        } elseif ($action == 'SHIPPING_SENTBYMAIL') {
            dol_syslog(
                "Trigger '" . $this->name . "' for action '$action' launched by " . __FILE__ . ". id=" . $object->id
            );
        } elseif ($action == 'SHIPPING_DELETE') {
            dol_syslog(
                "Trigger '" . $this->name . "' for action '$action' launched by " . __FILE__ . ". id=" . $object->id
            );
        } elseif ($action == 'SHIPPING_BUILDDOC') {
            dol_syslog(
                "Trigger '" . $this->name . "' for action '$action' launched by " . __FILE__ . ". id=" . $object->id
            );
        }

        // File
        elseif ($action == 'FILE_UPLOAD') {
            dol_syslog(
                "Trigger '" . $this->name . "' for action '$action' launched by " . __FILE__ . ". id=" . $object->id
            );
        } elseif ($action == 'FILE_DELETE') {
            dol_syslog(
                "Trigger '" . $this->name . "' for action '$action' launched by " . __FILE__ . ". id=" . $object->id
            );
        }
		*/
		
    }
}