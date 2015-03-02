<?php

require('config.php');
require('./class/asset.class.php');
require('./class/ordre_fabrication_asset.class.php');
require('./lib/asset.lib.php');
require_once DOL_DOCUMENT_ROOT.'/core/lib/ajax.lib.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/product.lib.php';
include_once DOL_DOCUMENT_ROOT.'/core/lib/admin.lib.php';
include_once DOL_DOCUMENT_ROOT.'/product/class/product.class.php';
include_once DOL_DOCUMENT_ROOT.'/commande/class/commande.class.php';
include_once DOL_DOCUMENT_ROOT.'/fourn/class/fournisseur.commande.class.php';


if(!$user->rights->asset->all->lire) accessforbidden();
if(!$user->rights->asset->of->write) accessforbidden();


// Load traductions files requiredby by page
$langs->load("other");
$langs->load("asset@asset");

// Get parameters
_action();

// Protection if external user
if ($user->societe_id > 0)
{
	//accessforbidden();
}

function _action() {
	global $user, $db, $conf, $langs;	
	$PDOdb=new TPDOdb;
	//$PDOdb->debug=true;
	
	/*******************************************************************
	* ACTIONS
	*
	* Put here all code to do according to value of "action" parameter
	********************************************************************/

	$action=__get('action','view');
	
	switch($action) {
		case 'new':
		case 'add':
			$assetOf=new TAssetOF;
			$assetOf->set_values($_REQUEST);

			$fk_product = __get('fk_product',0,'int');

			_fiche($PDOdb, $assetOf,'edit', $fk_product);

			break;

		case 'edit':
			$assetOf=new TAssetOF;
			$assetOf->load($PDOdb, $_REQUEST['id']);

			_fiche($PDOdb,$assetOf,'edit');
			break;
		
		case 'create':
		case 'save':
			$assetOf=new TAssetOF;
			
			if(!empty($_REQUEST['id'])) {
				$assetOf->load($PDOdb, $_REQUEST['id'], false);
				$mode = 'view';
			}
			else {
				$mode = $action == 'create' ? 'view' : 'edit';
			}

			$assetOf->set_values($_REQUEST);
			
			$fk_product = __get('fk_product_to_add',0);
			if($fk_product > 0) {
				$assetOf->addLine($PDOdb, $fk_product, 'TO_MAKE');	
				$assetOf->addWorkstation($PDOdb, $db, $fk_product);
			}

			if(!empty($_REQUEST['TAssetOFLine'])) {
				foreach($_REQUEST['TAssetOFLine'] as $k=>$row) {
					if(!isset( $assetOf->TAssetOFLine[$k] ))  $assetOf->TAssetOFLine[$k] = new TAssetOFLine;
					
					if (!empty($conf->global->ASSET_DEFINED_WORKSTATION_BY_NEEDED))
					{
						$assetOf->TAssetOFLine[$k]->set_workstations($PDOdb, $row['fk_workstation']);
						unset($row['fk_workstation']);	
					}

					$assetOf->TAssetOFLine[$k]->set_values($row);
				}

				foreach($assetOf->TAssetOFLine as &$line) {
					$line->TAssetOFLine=array();
				}
			}

			if(!empty($_REQUEST['TAssetWorkstationOF'])) {
				foreach($_REQUEST['TAssetWorkstationOF'] as $k=>$row) 
				{
					if (!empty($conf->global->ASSET_DEFINED_USER_BY_WORKSTATION))
					{
						$assetOf->TAssetWorkstationOF[$k]->set_users($PDOdb, $row['fk_user']);
						unset($row['fk_user']);
					}
					
					$assetOf->TAssetWorkstationOF[$k]->set_values($row);
				}
			}
			
			$assetOf->entity = $conf->entity;

			//Permet de mettre à jour le lot de l'OF parent
			if (!empty($assetOf->fk_assetOf_parent)) $assetOf->update_parent = true;
			$assetOf->save($PDOdb);
			
			//Si on créé un OF il faut recharger la page pour avoir le bon rendu
			if ($action == 'create') header('Location: '.$_SERVER["PHP_SELF"].'?id='.$assetOf->getId());
			
			//Si on viens d'un produit alors je recharge les enfants
			if ($fk_product > 0) $assetOf->loadChild($PDOdb);
			
			_fiche($PDOdb,$assetOf, $mode);

			break;

		case 'valider':
			$assetOf=new TAssetOF;
			if(!empty($_REQUEST['id'])) $assetOf->load($PDOdb, $_REQUEST['id'], false);
			
			//Si use_lot alors check de la saisie du lot pour chaque ligne avant validation
			if (!empty($conf->global->USE_LOT_IN_OF)) {
				if (!$assetOf->checkLotIsFill())
				{
					setEventMessage($langs->trans('OFAssetLotEmpty'), 'errors');
					_fiche($PDOdb,$assetOf, 'view');
					break;
				}
			}
					
			$assetOf->status = "VALID";

			if(!empty($_REQUEST['TAssetOFLine'])) {
				foreach($_REQUEST['TAssetOFLine'] as $k=>$row) {
					$assetOf->TAssetOFLine[$k]->set_values($row);
				}
			}
			$assetOf->createOfAndCommandesFourn($PDOdb);
			$assetOf->unsetChildDeleted = true;
			
			$assetOf->save($PDOdb);
			
			//Relaod de l'objet OF parce que createOfAndCommandesFourn() fait tellement de truc que c'est le bordel
			$assetOf=new TAssetOF;
			if(!empty($_REQUEST['id'])) $assetOf->load($PDOdb, $_REQUEST['id'], false);
			
			_fiche($PDOdb,$assetOf, 'view');

			break;

		case 'lancer':
			$assetOf=new TAssetOF;
			if(!empty($_REQUEST['id'])) $assetOf->load($PDOdb, $_REQUEST['id'], false);
			
			if ($assetOf->checkQtyAsset($PDOdb, $conf))
			{
				$assetOf->status = "OPEN";
				$assetOf->setEquipement($PDOdb); 
				$assetOf->save($PDOdb);
			}
						
			//$assetOf->openOF($PDOdb);
			_fiche($PDOdb, $assetOf, 'view');

			break;

		case 'terminer':
			$assetOf=new TAssetOF;
			if(!empty($_REQUEST['id'])) $assetOf->load($PDOdb, $_REQUEST['id'], false);
			
			if (!$assetOf->checkCommandeFournisseur($PDOdb))
			{
				setEventMessage($langs->trans('OFAssetCmdFournNotFinish'), 'errors');
				_fiche($PDOdb,$assetOf, 'view');
				break;
			}
			
			$assetOf->status = "CLOSE";
			
			$assetOf->closeOF($PDOdb);
			$assetOf->save($PDOdb);
			
			_fiche($PDOdb,$assetOf, 'view');
			
			break;

		case 'delete':
			$assetOf=new TAssetOF;
			$assetOf->load($PDOdb, $_REQUEST['id'], false);
			
			//$PDOdb->db->debug=true;
			$assetOf->delete($PDOdb);
			
			?>
			<script language="javascript">
				document.location.href="<?=dol_buildpath('/asset/liste_of.php',1); ?>?delete_ok=1";					
			</script>
			<?
			
			break;

		case 'view':
			$assetOf=new TAssetOF;
			$assetOf->load($PDOdb, $_REQUEST['id'], false);

			_fiche($PDOdb, $assetOf, 'view');

			break;
		case 'createDocOF':
			
			generateODTOF($PDOdb);

			break;
			
		case 'control':
			$assetOf=new TAssetOF;
			$assetOf->load($PDOdb, $_REQUEST['id'], false);
		
			$subAction = __get('subAction', false);
			if ($subAction) $assetOf->updateControl($PDOdb, $subAction);
			
			_fiche_control($PDOdb, $assetOf);
		
			break;
			
		default:
			$assetOf=new TAssetOF;
			$assetOf->load($PDOdb, $_REQUEST['id'], false);

			_fiche($PDOdb, $assetOf, 'view');
			
			break;
	}
	
}



function generateODTOF(&$PDOdb) {
	
	global $db,$conf;

	$assetOf=new TAssetOF;
	$assetOf->load($PDOdb, $_REQUEST['id'], false);
	foreach($assetOf as $k => $v) {
		print $k."<br />";
	}
	
	$TBS=new TTemplateTBS();
	dol_include_once("/product/class/product.class.php");

	$TToMake = array(); // Tableau envoyé à la fonction render contenant les informations concernant les produit à fabriquer
	$TNeeded = array(); // Tableau envoyé à la fonction render contenant les informations concernant les produit nécessaires
	$TWorkstations = array(); // Tableau envoyé à la fonction render contenant les informations concernant les stations de travail
	$TWorkstationUser = array(); // Tableau de liaison entre les postes et les utilisateurs
	$TAssetWorkstation = array(); // Tableau de liaison entre les composants et les postes de travails
	$TControl = array(); // Tableau de liaison entre l'OF et les controles associés
	
	$societe = new Societe($db);
	$societe->fetch($assetOf->fk_soc);
	
	//pre($societe,true); exit;
	
	if (!empty($conf->global->ASSET_USE_CONTROL))
	{
		$TControl = $assetOf->getControlPDF($PDOdb);
	}
	
	// On charge les tableaux de produits à fabriquer, et celui des produits nécessaires
	foreach($assetOf->TAssetOFLine as $k=>$v) {

		$prod = new Product($db);
		$prod->fetch($v->fk_product);
		$prod->fetch_optionals($prod->id);
		
		if($v->type == "TO_MAKE") {
			
			/*echo "<pre>";
			print_r($prod);
			echo "</pre>";
			exit;*/

			$TToMake[] = array(
				'type' => $v->type
				, 'qte' => $v->qty
				, 'nomProd' => $prod->ref
				, 'designation' => utf8_decode($prod->label)
				, 'dateBesoin' => date("d/m/Y", $assetOf->date_besoin)
				, 'lot_number' => $v->lot_number ? "\n(Lot numero ".$v->lot_number.")" : ""
				, 'code_suivi_ponderal' => $prod->array_options['options_suivi_ponderal'] ? "\n".$prod->array_options['options_suivi_ponderal'] : "\n(Aucun)"
			);

		}
		
		if($v->type == "NEEDED") {
	
			$unitLabel = "";

			if($prod->weight_units == 0) {
				$unitLabel = "Kg";
			} else if ($prod->weight_units == -3) {
				$unitLabel = "g";
			} else if ($prod->weight_units == -6) {
				$unitLabel = "mg";
			} else if ($prod->weight_units == 99) {
				$unitLabel = "livre(s)";
			}
								
			$TNeeded[] = array(
				'type' => $v->type
				, 'qte' => $v->qty
				, 'nomProd' => $prod->ref
				, 'designation' => utf8_decode($prod->label)
				, 'dateBesoin' => date("d/m/Y", $assetOf->date_besoin)
				, 'poids' => $prod->weight
				, 'unitPoids' => $unitLabel
				, 'finished' => $prod->finished?"PM":"MP"
				, 'lot_number' => $v->lot_number ? "\n(Lot numero ".$v->lot_number.")" : ""
				, 'code_suivi_ponderal' => $prod->array_options['options_suivi_ponderal'] ? "\n(Code suivi ponderal : ".$prod->array_options['options_suivi_ponderal'].")" : ""
			);
			
			if (!empty($conf->global->ASSET_DEFINED_WORKSTATION_BY_NEEDED))
			{
				$TAssetWorkstation[] = array(
					'nomProd'=>utf8_decode($prod->label)
					,'workstations'=>utf8_decode($v->getWorkstationsPDF($db))
				);
			}
			
		}

	}

	// On charge le tableau d'infos sur les stations de travail de l'OF courant
	foreach($assetOf->TAssetWorkstationOF as $k => $v) {
		
		$TWorkstations[] = array(
			'libelle' => utf8_decode($v->ws->libelle)
			,'nb_hour_real' => utf8_decode($v->nb_hour_real)
			,'nb_heures_prevues' => utf8_decode($v->nb_hour)
		);
		
		if (!empty($conf->global->ASSET_DEFINED_USER_BY_WORKSTATION))
		{
			$TWorkstationUser[] = array(
				'workstation'=>utf8_decode($v->ws->libelle)
				,'users'=>utf8_decode($v->getUsersPDF($db,$PDOdb))
			);
		}

	}
	
	$dirName = 'OF'.$_REQUEST['id'].'('.date("d_m_Y").')';
	$dir = DOL_DATA_ROOT.'/asset/'.$dirName.'/';
	
	@mkdir($dir, 0777, true);
	
	if(defined('TEMPLATE_OF')){
		$template = TEMPLATE_OF;
	}
	else{
		$template = "templateOF.odt";
		//$template = "templateOF.doc";
	}
	
	$TBS->render(dol_buildpath('/asset/exempleTemplate/'.$template)
		,array(
			'lignesToMake'=>$TToMake
			,'lignesNeeded'=>$TNeeded
			,'lignesWorkstation'=>$TWorkstations
			,'lignesAssetWorkstations'=>$TAssetWorkstation
			,'lignesUser'=>$TWorkstationUser
			,'lignesControl'=>$TControl
		)
		,array(
			'date'=>date("d/m/Y")
			,'numeroOF'=>$assetOf->numero
			,'statutOF'=>TAssetOF::$TStatus[$assetOf->status]
			,'prioriteOF'=>utf8_decode(TAssetOF::$TOrdre[$assetOf->ordre])
			,'date'=>date("d/m/Y")
			,'societe'=>$societe->name
			,'logo'=>DOL_DATA_ROOT."/mycompany/logos/".MAIN_INFO_SOCIETE_LOGO
			,'use_lot'=>(int) $conf->global->ASSET_DEFINED_WORKSTATION_BY_NEEDED
			,'defined_user'=>(int) $conf->global->ASSET_DEFINED_USER_BY_WORKSTATION
			,'use_control'=>(int) $conf->global->ASSET_USE_CONTROL
		)
		,array()
		,array(
			'outFile'=>$dir.$assetOf->numero.".odt"
			,"convertToPDF"=>true
			//'outFile'=>$dir.$assetOf->numero.".doc"
		)
		
	);	
	
	header("Location: ".DOL_URL_ROOT."/document.php?modulepart=asset&entity=1&file=".$dirName."/".$assetOf->numero.".pdf");
	//header("Location: ".DOL_URL_ROOT."/document.php?modulepart=asset&entity=1&file=".$dirName."/".$assetOf->numero.".doc");

}


function _fiche_ligne(&$form, &$of, $type){
	global $db, $conf, $langs;

	$TRes = array();
	foreach($of->TAssetOFLine as $k=>$TAssetOFLine){
		$product = new Product($db);
		$product->fetch($TAssetOFLine->fk_product);
		$product->load_stock();

		if($TAssetOFLine->type == "NEEDED" && $type == "NEEDED"){
			$TRes[]= array(
				'id'=>$TAssetOFLine->getId()
				,'idprod'=>$form->hidden('TAssetOFLine['.$k.'][fk_product]', $product->id)
				,'lot_number'=>($of->status=='DRAFT') ? $form->texte('', 'TAssetOFLine['.$k.'][lot_number]', $TAssetOFLine->lot_number, 15,50,'fk_product="'.$product->id.'"','TAssetOFLineLot') : $TAssetOFLine->lot_number
				,'libelle'=>'<a href="'.DOL_URL_ROOT.'/product/fiche.php?id='.$product->id.'">'.img_picto('', 'object_product.png').$product->libelle.'</a> - '.$langs->trans("Stock")." : ".$product->stock_reel
				,'qty_needed'=>$TAssetOFLine->qty_needed
				,'qty'=>($of->status=='DRAFT') ? $form->texte('', 'TAssetOFLine['.$k.'][qty]', $TAssetOFLine->qty, 5,50) : $TAssetOFLine->qty
				,'qty_used'=>($of->status=='OPEN') ? $form->texte('', 'TAssetOFLine['.$k.'][qty_used]', $TAssetOFLine->qty_used, 5,50) : $TAssetOFLine->qty_used
				,'qty_toadd'=> $TAssetOFLine->qty - $TAssetOFLine->qty_used
				,'workstations'=>$TAssetOFLine->visu_checkbox_workstation($db, $of, $form, 'TAssetOFLine['.$k.'][fk_workstation][]')
				,'delete'=> '<a href="javascript:deleteLine('.$TAssetOFLine->getId().',\'NEEDED\');">'.img_picto('Supprimer', 'delete.png').'</a>'
			);
		}
		elseif($TAssetOFLine->type == "TO_MAKE" && $type == "TO_MAKE"){
		
			if(empty($TAssetOFLine->TFournisseurPrice)) {
				$ATMdb=new TPDOdb;
				$TAssetOFLine->loadFournisseurPrice($ATMdb);
			}
		
			$Tab=array();
			foreach($TAssetOFLine->TFournisseurPrice as &$objPrice) {
				
				$label = "";

				//Si on a un prix fournisseur pour le produit
				if($objPrice->price > 0){
					$label .= floatval($objPrice->price).' '.$conf->currency;
				}
				
				//Affiche le nom du fournisseur
				$label .= ' (Fournisseur "'.utf8_encode ($objPrice->name).'"';

				//Prix unitaire minimum si renseigné dans le PF
				if($objPrice->quantity > 0){
					' '.$objPrice->quantity.' pièce(s) min,';
				} 
				
				//Affiche le type du PF :
				if($objPrice->compose_fourni){//			soit on fabrique les composants
					$label .= ' => Fabrication interne';
				}
				elseif($objPrice->quantity <= 0){//			soit on a le produit finis déjà en stock
					$label .= ' => Sortie de stock';
				}

				if($objPrice->quantity > 0){//				soit on commande a un fournisseur
					$label .= ' => Commande fournisseur';
				}
				
				$label .= ")";

				$Tab[ $objPrice->rowid ] = array(
												'label' => $label,
												'compose_fourni' => ($objPrice->compose_fourni) ? $objPrice->compose_fourni : 0
											);

			}
		
			$TRes[]= array(
				'id'=>$TAssetOFLine->getId()
				,'idprod'=>$form->hidden('TAssetOFLine['.$k.'][fk_product]', $product->id)
				,'lot_number'=>($of->status=='DRAFT') ? $form->texte('', 'TAssetOFLine['.$k.'][lot_number]', $TAssetOFLine->lot_number, 15,50,'fk_product="'.$product->id.'"','TAssetOFLineLot') : $TAssetOFLine->lot_number
				,'libelle'=>'<a href="'.DOL_URL_ROOT.'/product/fiche.php?id='.$product->id.'">'.img_picto('', 'object_product.png').$product->libelle.'</a> - '.$langs->trans("Stock")." : ".$product->stock_reel
				,'addneeded'=> '<a href="#null" statut="'.$of->status.'" onclick="addAllLines('.$of->getId().','.$TAssetOFLine->getId().',this);">'.img_picto('Mettre à jour les produits nécessaires', 'previous.png').'</a>'
				,'qty'=>($of->status=='DRAFT') ? $form->texte('', 'TAssetOFLine['.$k.'][qty]', $TAssetOFLine->qty, 5,5,'','') : $TAssetOFLine->qty 
				,'fk_product_fournisseur_price' => $form->combo('', 'TAssetOFLine['.$k.'][fk_product_fournisseur_price]', $Tab, $TAssetOFLine->fk_product_fournisseur_price )
				,'delete'=> '<a href="#null" onclick="deleteLine('.$TAssetOFLine->getId().',\'TO_MAKE\');">'.img_picto('Supprimer', 'delete.png').'</a>'
			);
		}
	}
	
	return $TRes;
}



function _fiche(&$PDOdb, &$assetOf, $mode='edit',$fk_product_to_add=0) {
	global $langs,$db,$conf;
	/***************************************************
	* PAGE
	*
	* Put here all code to build page
	****************************************************/
	
	//pre($assetOf,true);
	llxHeader('',$langs->trans('OFAsset'),'','');
	print dol_get_fiche_head(assetPrepareHead( $assetOf, 'assetOF') , 'fiche', $langs->trans('OFAsset'));

	?><style type="text/css">
		#assetChildContener .OFMaster {
			
			background:#fff;
			-webkit-box-shadow: 4px 4px 5px 0px rgba(50, 50, 50, 0.52);
			-moz-box-shadow:    4px 4px 5px 0px rgba(50, 50, 50, 0.52);
			box-shadow:         4px 4px 5px 0px rgba(50, 50, 50, 0.52);
			
			margin-bottom:20px;
		}
		
	</style>
		<div class="OFContent" rel="<?=$assetOf->getId() ?>">	<?php
	
	$TPrixFournisseurs = array();
	
	//$form=new TFormCore($_SERVER['PHP_SELF'],'formeq'.$assetOf->getId(),'POST');
	
	//Affichage des erreurs
	if(!empty($assetOf->errors)){
		?>
		<br><div class="error">
		<?php
		foreach($assetOf->errors as $error){
			echo $error."<br>";
		}
		$assetOf->errors = array();
		?>
		</div><br>
		<?php
	}	
	
	$form=new TFormCore();
	$form->Set_typeaff($mode);
	
	$doliform = new Form($db);
	
	if(!empty($_REQUEST['fk_product'])) echo $form->hidden('fk_product', $_REQUEST['fk_product']);
	
	$TBS=new TTemplateTBS();
	$liste=new TListviewTBS('asset');

	$TBS->TBS->protect=false;
	$TBS->TBS->noerr=true;
	
	$PDOdb = new TPDOdb;
	
	$TNeeded = array();
	$TToMake = array();
	
	$TNeeded = _fiche_ligne($form, $assetOf, "NEEDED");
	$TToMake = _fiche_ligne($form, $assetOf, "TO_MAKE");
	
	$TIdCommandeFourn = $assetOf->getElementElement($PDOdb);
	
	$HtmlCmdFourn = '';
	
	if(count($TIdCommandeFourn)){
		foreach($TIdCommandeFourn as $idcommandeFourn){
			$cmd = new CommandeFournisseur($db);
			$cmd->fetch($idcommandeFourn);

			$HtmlCmdFourn .= $cmd->getNomUrl(1)." ";
		}
	}
	
	ob_start();
	$doliform->select_produits('','fk_product','',$conf->product->limit_size,0,1,2,'',3,array());
	$select_product = ob_get_clean();
	
	$Tid = array();
	//$Tid[] = $assetOf->rowid;
	if($assetOf->getId()>0) $assetOf->getListeOFEnfants($PDOdb, $Tid);
	
	$TWorkstation=array();
	foreach($assetOf->TAssetWorkstationOF as $k=>$TAssetWorkstationOF) {
		$ws = & $TAssetWorkstationOF->ws;
		
		$TWorkstation[]=array(
			'libelle'=>'<a href="workstation.php?action=view&id='.$ws->rowid.'">'.$ws->libelle.'</a>'
			,'fk_user' => $TAssetWorkstationOF->visu_checkbox_user($db, $form, $ws->fk_usergroup, 'TAssetWorkstationOF['.$k.'][fk_user][]')
			,'nb_hour'=> ($assetOf->status=='DRAFT' && $mode == "edit") ? $form->texte('','TAssetWorkstationOF['.$k.'][nb_hour]', $TAssetWorkstationOF->nb_hour,3,10) : $TAssetWorkstationOF->nb_hour  
			,'nb_hour_real'=>($assetOf->status=='OPEN' && $mode == "edit") ? $form->texte('','TAssetWorkstationOF['.$k.'][nb_hour_real]', $TAssetWorkstationOF->nb_hour_real,3,10) : $TAssetWorkstationOF->nb_hour_real
			,'delete'=> '<a href="javascript:deleteWS('.$assetOf->getId().','.$TAssetWorkstationOF->getId().');">'.img_picto('Supprimer', 'delete.png').'</a>'
			,'id'=>$ws->getId()
		);
		
	}
	
	$client=new Societe($db);
	if($assetOf->fk_soc>0) $client->fetch($assetOf->fk_soc);
	
	$commande=new Commande($db);
	if($assetOf->fk_commande>0) $commande->fetch($assetOf->fk_commande);
	
	$TOFParent = array_merge(array(0=>'')  ,$assetOf->getCanBeParent($PDOdb));

	$hasParent = false;
	if (!empty($assetOf->fk_assetOf_parent))
	{
		$TAssetOFParent = new TAssetOF;
		$TAssetOFParent->load($PDOdb, $assetOf->fk_assetOf_parent);
		$hasParent = true;
	}
	
	print $TBS->render('tpl/fiche_of.tpl.php'
		,array(
			'TNeeded'=>$TNeeded
			,'TTomake'=>$TToMake
			,'workstation'=>$TWorkstation
		)
		,array(
			'assetOf'=>array(
				'id'=> $assetOf->getId()
				,'numero'=> ($mode=='edit') ? $form->texte('', 'numero', ($assetOf->numero) ? $assetOf->numero : 'OF'.str_pad( $assetOf->getLastId($PDOdb) +1 , 5, '0', STR_PAD_LEFT), 20,255,'','','à saisir') : '<a href="fiche_of.php?id='.$assetOf->getId().'">'.$assetOf->numero.'</a>'
				,'ordre'=>$form->combo('','ordre',TAssetOf::$TOrdre,$assetOf->ordre)
				,'fk_commande'=>($assetOf->fk_commande==0) ? '' : $commande->getNomUrl(1)
				,'statut_commande'=> $commande->getLibStatut(0)
				,'commande_fournisseur'=>$HtmlCmdFourn
				,'date_besoin'=>$form->calendrier('','date_besoin',$assetOf->date_besoin,12,12)
				,'date_lancement'=>$form->calendrier('','date_lancement',$assetOf->date_lancement,12,12)
				,'temps_estime_fabrication'=>$assetOf->temps_estime_fabrication
				,'temps_reel_fabrication'=>$assetOf->temps_reel_fabrication
				
				,'fk_soc'=> ($mode=='edit') ? $doliform->select_company($assetOf->fk_soc,'fk_soc','client=1',1) : (($client->id) ? $client->getNomUrl(1) : '')
				
				,'note'=>$form->zonetexte('', 'note', $assetOf->note, 80,5)
				
				,'status'=>$form->combo('','status',TAssetOf::$TStatus,$assetOf->status)
				,'statustxt'=>TAssetOf::$TStatus[$assetOf->status]
				,'idChild' => (!empty($Tid)) ? '"'.implode('","',$Tid).'"' : ''
				,'url' => dol_buildpath('/asset/fiche_of.php', 2)
				,'url_liste' => ($assetOf->getId()) ? dol_buildpath('/asset/fiche_of.php?id='.$assetOf->getId(), 2) : dol_buildpath('/asset/liste_of.php', 2)
				,'fk_product_to_add'=>$fk_product_to_add
				,'fk_assetOf_parent'=>($assetOf->fk_assetOf_parent ? $assetOf->fk_assetOf_parent : '')
				,'link_assetOf_parent'=>($hasParent ? '<a href="'.dol_buildpath('/asset/fiche_of.php?id='.$TAssetOFParent->rowid, 2).'">'.$TAssetOFParent->numero.'</a>' : '')
			)
			,'view'=>array(
				'mode'=>$mode
				,'status'=>$assetOf->status
				,'select_product'=>$select_product
				,'select_workstation'=>$form->combo('', 'fk_asset_workstation', TAssetWorkstation::getWorstations($PDOdb), -1)			
				,'actionChild'=>($mode == 'edit')?__get('actionChild','edit'):__get('actionChild','view')
				,'use_lot_in_of'=>(int) $conf->global->USE_LOT_IN_OF
				,'defined_user_by_workstation'=>(int) $conf->global->ASSET_DEFINED_USER_BY_WORKSTATION
				,'defined_workstation_by_needed'=>(int) $conf->global->ASSET_DEFINED_WORKSTATION_BY_NEEDED
				,'hasChildren' => (int) !empty($Tid)
			)
		)
	);
	
	echo $form->end_form();
	
	llxFooter('$Date: 2011/07/31 22:21:57 $ - $Revision: 1.19 $');
}

function _fiche_ligne_control(&$PDOdb, $fk_assetOf, $assetOf=-1)
{
	$res = array();
	
	if ($assetOf == -1)
	{
		$sql = 'SELECT rowid as id, libelle, question, type, "" as response, "" as id_assetOf_control FROM '.MAIN_DB_PREFIX.'asset_control WHERE rowid NOT IN (SELECT fk_control FROM '.MAIN_DB_PREFIX.'assetOf_control WHERE fk_assetOf ='.(int) $fk_assetOf.')';	
	}
	else 
	{
		if (empty($assetOf->TAssetOFControl)) return $res;
		
		$ids = array();
		foreach ($assetOf->TAssetOFControl as $ofControl)
		{
			$ids[] = $ofControl->getId();
		}
		
		$sql = 'SELECT c.rowid as id, c.libelle, c.question, c.type, ofc.response, ofc.rowid as id_assetOf_control FROM '.MAIN_DB_PREFIX.'asset_control c';
		$sql.= ' INNER JOIN '.MAIN_DB_PREFIX.'assetOf_control ofc ON (ofc.fk_control = c.rowid)';
		$sql.= ' WHERE ofc.rowid IN ('.implode(',', $ids).')';
		
	}
	
	$PDOdb->Execute($sql);
	while ($PDOdb->Get_line())
	{
		$res[] = array(
			'id' => $PDOdb->Get_field('id')
			,'libelle' => '<a href="'.DOL_URL_ROOT.'/custom/asset/control.php?id='.$PDOdb->Get_field('id').'">'.$PDOdb->Get_field('libelle').'</a>'
			,'type' => TAssetControl::$TType[$PDOdb->Get_field('type')]
			,'action' => '<input type="checkbox" value="'.$PDOdb->Get_field('id').'" name="TControl[]" />'
			,'question' => $PDOdb->Get_field('question')
			,'response' => ($assetOf == -1 ? '' : $assetOf->generate_visu_control_value($PDOdb->Get_field('id'), $PDOdb->Get_field('type'), $PDOdb->Get_field('response'), 'TControlResponse['.$PDOdb->Get_field('id_assetOf_control').'][]'))
			,'delete' => '<input type="checkbox" value="'.$PDOdb->Get_field('id_assetOf_control').'" name="TControlDelete[]" />'
		);
	}
	
	return $res;
}

function _fiche_control(&$PDOdb, &$assetOf)
{
	global $langs,$db,$conf;
	
	llxHeader('',$langs->trans('OFAsset'),'','');
	print dol_get_fiche_head(assetPrepareHead( $assetOf, 'assetOF') , 'controle', $langs->trans('OFAsset'));
	
	/******/
	$TBS=new TTemplateTBS();
	$TBS->TBS->protect=false;
	$TBS->TBS->noerr=true;

	$form=new TFormCore($_SERVER['PHP_SELF'], 'form', 'POST');
	$form->Set_typeaff('view');
	
	$TControl = _fiche_ligne_control($PDOdb, $assetOf->getId());
	$TAssetOFControl = _fiche_ligne_control($PDOdb, $assetOf->getId(), $assetOf);
	
	print $TBS->render('tpl/fiche_of_control.tpl.php'
		,array(
			'TControl'=>$TControl
			,'TAssetOFControl'=>$TAssetOFControl
		)
		,array(
			'assetOf'=>array(
				'id'=>(int) $assetOf->getId()
			)
			,'view'=>array(
				'nbTControl'=>count($TControl)
				,'nbTAssetOFControl'=>count($TAssetOFControl)
				,'url'=>DOL_URL_ROOT.'/custom/asset/fiche_of.php'
			)
		)
	);
	
	$form->end();
	
	/******/
	
	llxFooter('$Date: 2011/07/31 22:21:57 $ - $Revision: 1.19 $');
}
