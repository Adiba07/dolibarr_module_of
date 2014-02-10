<?php

class TAssetOF extends TObjetStd{
/*
 * Ordre de fabrication d'équipement
 * */
	
	function __construct() {
		$this->set_table(MAIN_DB_PREFIX.'assetOf');
    	$this->TChamps = array(); 	  
		$this->add_champs('numero,entity,fk_user','type=entier;');
		$this->add_champs('entity,temps_estime_fabrication,temps_reel_fabrication','type=float;');
		$this->add_champs('ordre,status','type=chaine;');
		$this->add_champs('date_besoin,date_lancement','type=date;');
		
		//clé étrangère : atelier
		parent::add_champs('fk_asset_workstation','type=entier;index;');
		
		parent::add_champs('fk_assetOf_parent','type=entier;index;');
		
	    $this->start();
		
		$this->TOrdre=array(
			'ASAP'=>'Au plut tôt'
			,'TODAY'=>'Dans la journée'
			,'TOMORROW'=> 'Demain'
			,'WEEK'=>'Dans la semaine'
			,'MONTH'=>'Dans le mois'
			
		);
		$this->TStatus=array(
			'DRAFT'=>'Brouillon'
			,'VALID'=>'Validé'
			,'OPEN'=>'Lancé'
			,'CLOSE'=>'Terminé'
		);
		
		$this->workstation=null;
		
		$this->setChild('TAssetOFLine','fk_assetOf');
		$this->setChild('TAssetOF','fk_assetOf_parent');
		
	}
	
	function load(&$db, $id) {
		global $conf;
		
		$res = parent::load($db,$id);
		$this->loadWorkstation($db);
		
		return $res;
	}
	
	//Associe les équipements à l'OF
	function setEquipement(&$ATMdb){
		
		foreach($this->TAssetOFLine as $TAssetOFLine){
			
			$TAssetOFLine->setAsset($ATMdb);	
		}
		
		return true;
	}
	
	function delLine(&$ATMdb,$iline){
		
		$this->TAssetOFLine[$iline]->to_delete=true;
		
	}
	
	//Ajout d'un produit TO_MAKE à l'OF
	function addProductComposition(&$ATMdb, $fk_product, $quantite_to_make=1, $fk_assetOf_line_parent=0){
		
		$Tab = $this->getProductComposition($ATMdb,$fk_product, $quantite_to_make);
		echo "<pre>";
		print_r($Tab);
		echo "</pre>";
		
		foreach($Tab as $prod) {
			
			$this->addLine($ATMdb, $prod->fk_product, 'NEEDED', $prod->qty * $quantite_to_make,$fk_assetOf_line_parent);
			
		}
		
		return true;
	}
	
	//Retourne les produits NEEDED de l'OF concernant le produit $id_produit
	function getProductComposition(&$ATMdb,$id_product, $quantite_to_make){
		include_once DOL_DOCUMENT_ROOT.'/product/class/product.class.php';
		global $db;	
		
		$Tab=array();
		$product = new Product($db);
		$product->fetch($id_product);
		$TRes = $product->getChildsArbo($product->id);
		
		$this->getProductComposition_arrayMerge($ATMdb,$Tab, $TRes, $quantite_to_make);
		
		return $Tab;
	}
	
	private function getProductComposition_arrayMerge(&$ATMdb,&$Tab, $TRes, $qty_parent=1, $createOF=true) {
		
		foreach($TRes as $row) {
			
			$prod = new stdClass;
			$prod->fk_product = $row[0];
			$prod->qty = $row[1];
			
			if(isset($Tab[$prod->fk_product])) {
				$Tab[$prod->fk_product]->qty += $prod->qty * $qty_parent;
			}
			else {
				$Tab[$prod->fk_product]=$prod;	
			}
			
			if(!empty($row['childs'])) {
				
				if($createOF) {
					$this->createOFifneeded($ATMdb, $prod->fk_product, $prod->qty * $qty_parent);
				}
				else {
					$this->getProductComposition_arrayMerge($Tab, $row['childs'], $prod->qty * $qty_parent);	
				}
			}
		}
		
	} 
	
	/*
	 * Crée une OF si produit composé pas en stock
	 */
	function createOFifneeded(&$ATMdb,$fk_product, $qty_needed) {
		
		$reste = $this->getProductStock($fk_product)-$qty_needed;
		
		if($reste>0) {
			null;
		}
		else {
			
			$k=$this->addChild($ATMdb,'TAssetOF');
			$this->TAssetOF[$k]->addLine($ATMdb, $fk_product, 'TO_MAKE', abs($qty_needed));
			
		}
		
	}
	/*
	 * retourne le stock restant du produit
	 */
	function getProductStock($fk_product) {
		global $db;
		include_once DOL_DOCUMENT_ROOT.'/product/class/product.class.php';
		
		$product = new Product($db);
		$product->fetch($fk_product);
		$product->load_stock();
		
		return $product->stock_reel;
		
	}
	
	/*function createCommandeFournisseur($type='externe'){
		global $db,$conf,$user;
		include_once DOL_DOCUMENT_ROOT.'/fourn/class/fournisseur.class.php';
		
		$id_fourn = $this->getFournisseur();
		
		$cmdFour = new CommandeFournisseur($db);
		$cmdFour->ref_supplier = "";
       	$cmdFour->note_private = "";
        $cmdFour->note_public = "";
        $cmdFour->socid;
		
		return $id_cmd_four;
	}
	
	function getFournisseur(){
		global $db;
		
		return 1;
	}*/
	
	function loadWorkstation(&$ATMdb){
		if(empty($this->workstation)) {
			$this->workstation=new TAssetWorkstation;
			$this->workstation->load($ATMdb, $this->fk_asset_workstation);
		}
	}
	
	//Ajoute une ligne de produit à l'OF
	function addLine(&$ATMdb, $fk_product, $type, $quantite=1,$fk_assetOf_line_parent=0){
		global $user;
		
		$k = $this->addChild($ATMdb, 'TAssetOFLine');
		
		$TAssetOFLine = &$this->TAssetOFLine[$k];
		$TAssetOFLine->fk_assetOf_line_parent = $fk_assetOf_line_parent;
		$TAssetOFLine->entity = $user->entity;
		$TAssetOFLine->fk_product = $fk_product;
		$TAssetOFLine->fk_asset = 0;
		$TAssetOFLine->type = $type;
		$TAssetOFLine->qty = $quantite;
		$TAssetOFLine->qty_used = $quantite;
		
		$idAssetOFLine = $TAssetOFLine->save($ATMdb);
		
		if($type=='TO_MAKE') {
			$this->addProductComposition($ATMdb,$fk_product, $quantite,$idAssetOFLine);
		}
	}
	
	function updateLines(&$ATMdb,$TQty){
		
		foreach($this->TAssetOFLine as $TAssetOFLine){
			$TAssetOFLine->qty_used = $TQty[$TAssetOFLine->getId()];
			$TAssetOFLine->save($ATMdb);
		}
	}
	
	//Finalise un OF => incrémention/décrémentation du stock
	function closeOF(&$ATMdb){
		include_once DOL_DOCUMENT_ROOT.'/product/class/product.class.php';
		
		foreach($this->TAssetOFLine as $AssetOFLine){
			$asset = new TAsset;
			
			if($AssetOFLine->type == "TO_MAKE"){
				$AssetOFLine->makeAsset($ATMdb,$AssetOFLine->fk_product,$AssetOFLine->qty_used);
			}
		}
	}
	
	function openOF(&$ATMdb){
		include_once DOL_DOCUMENT_ROOT.'/product/class/product.class.php';
		
		foreach($this->TAssetOFLine as $AssetOFLine){
			$asset = new TAsset;
			
			if($AssetOFLine->type == "NEEDED"){
				//TODO v2 : sélection d'un équipement à associé et décrémenter son stock
				$asset->addStockMouvementDolibarr($AssetOFLine->fk_product,-$AssetOFLine->qty_used,'Utilisation via Ordre de Fabrication');
			}
		}
	}
	
	function TAssetOFLineAsArray($type,&$form){
		global $db;
		
		$TRes = array();
		
		foreach($this->TAssetOFLine as $TAssetOFLine){
			$product = new Product($db);
			$product->fetch($TAssetOFLine->fk_product);
			
			if($TAssetOFLine->type == "NEEDED" && $type == "NEEDED"){
				$TRes[]= array(
					'id'=>$TAssetOFLine->getId()
					,'libelle'=>'<a href="'.DOL_URL_ROOT.'/product/fiche.php?id='.$product->id.'">'.img_picto('', 'object_product.png').$product->libelle.'</a>'
					,'qty_needed'=>$TAssetOFLine->qty
					,'qty'=>$form->texte('', 'qty['.$TAssetOFLine->getId().']', $TAssetOFLine->qty_used, 5,5,'','','à saisir')
					,'qty_toadd'=> $TAssetOFLine->qty - $TAssetOFLine->qty_used
					,'delete'=> '<a href="#null" onclick="deleteLine('.$TAssetOFLine->getId().',\'NEEDED\');">'.img_picto('Supprimer', 'delete.png').'</a>'
				);
			}
			elseif($TAssetOFLine->type == "TO_MAKE" && $type == "TO_MAKE"){
				$TRes[]= array(
					'id'=>$TAssetOFLine->getId()
					,'idProd'=>$product->id
					,'libelle'=>'<a href="'.DOL_URL_ROOT.'/product/fiche.php?id='.$product->id.'">'.img_picto('', 'object_product.png').$product->libelle.'</a>'
					,'addneeded'=> '<a href="#null" onclick="addAllLines('.$TAssetOFLine->getId().',this);">'.img_picto('Ajout des produit nécessaire', 'previous.png').'</a>'
					,'qty'=>$form->texte('', 'qty['.$TAssetOFLine->getId().']', $TAssetOFLine->qty, 5,5,'','','à saisir')
					,'delete'=> '<a href="#null" onclick="deleteLine('.$TAssetOFLine->getId().',\'TO_MAKE\');">'.img_picto('Supprimer', 'delete.png').'</a>'
				);
			}
		}
		
		return $TRes;
	}

	function getOrdre($ordre='ASAP'){
		
		$TOrdre=array(
			'ASAP'=>'Au plut tôt'
			,'TODAY'=>'Dans la journée'
			,'TOMORROW'=> 'Demain'
			,'WEEK'=>'Dans la semaine'
			,'MONTH'=>'Dans le mois'
			
		);
		
		return $TOrdre[$ordre];
	}
	
	function getStatus($status='DRAFT'){
		$TStatus=array(
			'DRAFT'=>'Brouillon'
			,'VALID'=>'Validé'
			,'OPEN'=>'Lancé'
			,'CLOSE'=>'Terminé'
		);
		
		return $TStatus[$status];
	}
	
	/*function getListeOFEnfants($ATMdb, $Tid, $i) {
		
		global $db;
		
		while($i<count($Tid)) {
			$sql = "SELECT rowid";
			$sql.= " FROM ".MAIN_DB_PREFIX."assetOf";
			$sql.= " WHERE fk_assetOf_parent = ".$Tid[$i];

			$resql = $db->query($sql);
			
			$i++;
			
			if($resql->num_rows>0) {
				
				while($res = $db->fetch_object($resql)) {

					$Tid[] = $res->rowid;
					
				}
				
				$this->getListeOFEnfants($ATMdb, $Tid, $i);
			}
						
		}
		
		unset($Tid[0]);
		
		print_r($Tid);
		exit;
		/*echo "<pre>";
		print_r($Tid);
		echo "</pre>";
		exit;

		$TEnfants = array();
		
		foreach($Tid as $id) {
			
			$assetOf = new TAssetOF;
			$assetOf->load($ATMdb, $id);
			$TabEnfants[] = $assetOf;

		}
		
		return $TabEnfants;
	}*/
	
	function getListeOFEnfants($ATMdb, &$Tid, $id_parent) {
		global $db;
		
		$sql = "SELECT rowid";
		$sql.= " FROM ".MAIN_DB_PREFIX."assetOf";
		$sql.= " WHERE fk_assetOf_parent = ".$id_parent;
		
		$resql = $db->query($sql);
		if($resql->num_rows>0) {
		
			while($res = $db->fetch_object($resql)) {
			
				$Tid[] = $res->rowid;
				$this->getListeOFEnfants($ATMdb, $Tid, $res->rowid);
			
			}
	
		}
		
		/*$TabEnfants = array();
		
		foreach($Tid as $id) {
			$assetOf = new TAssetOF;
			$assetOf->load($ATMdb, $id);
			$TabEnfants[] = $assetOf;
		}
		
		return $TabEnfants;*/
		
	}
}

class TAssetOFLine extends TObjetStd{
/*
 * Ligne d'Ordre de fabrication d'équipement 
 * */
	
	function __construct() {
		$this->set_table(MAIN_DB_PREFIX.'assetOf_line');
    	$this->TChamps = array(); 	  
		$this->add_champs('entity,fk_assetOf,fk_product,fk_asset','type=entier;');
		$this->add_champs('qty,qty_used','type=float;');
		$this->add_champs('type','type=chaine;');
		
		//clé étrangère
		parent::add_champs('fk_assetOf_line_parent','type=entier;index;');
		
		$this->TType=array('NEEDED','TO_MAKE');
		
	    $this->start();
		
		$this->setChild('TAssetOFLine','fk_assetOf_line_parent');
	}
	
	//Affecte l'équipement à la ligne de l'OF
	function setAsset(&$ATMdb){
		global $db, $user;	
		include_once 'asset.class.php';
		
		$asset = new TAsset;
		
		$sql = "SELECT rowid FROM ".MAIN_DB_PREFIX."asset WHERE contenance_reel >= ".$this->qty." ORDER BY contenance_reel ASC LIMIT 1";
		$ATMdb->Execute($sql);
		if($ATMdb->Get_line()){
			$idAsset = $ATMdb->Get_field('rowid');
			$asset->load($ATMdb, $idAsset);
			$asset->status = 'indisponible';
		}
		else{
			$asset = $this->makeAsset($ATMdb, $this->fk_product, $this->qty);
		}
				
		$asset->save($ATMdb);
		
		$this->fk_asset = $idAsset;
		$this->save($ATMdb);
		
		return true;
	}
	
	//Utilise l'équipement affecté à la ligne de l'OF
	function makeAsset(&$ATMdb,$fk_product,$qty){
		global $user,$conf;
		include_once 'asset.class.php';
		
		$TAsset = new TAsset;
		$TAsset->fk_soc = '';
		$TAsset->fk_product = $fk_product;
		$TAsset->entity = $user->entity;
		
		/*echo '<pre>';
		print_r($TAsset);
		echo '</pre>';*/
		
		/*
		 * Empêche l'ajout en stock des sous-produit d'un produit composé
		 */
		$varconf = $conf->global->PRODUIT_SOUSPRODUITS;
		$conf->global->PRODUIT_SOUSPRODUITS = NULL;
		$TAsset->save($ATMdb,$user,'Création via Ordre de Fabrication',$qty);
		$conf->global->PRODUIT_SOUSPRODUITS = $varconf;
	}
}

class TAssetWorkstation extends TObjetStd{
/*
 * Atelier de fabrication d'équipement
 * */
	
	function __construct() {
		$this->set_table(MAIN_DB_PREFIX.'asset_workstation');
    	$this->TChamps = array(); 	  
		$this->add_champs('entity','type=entier;');
		$this->add_champs('libelle','type=chaine;');
		
	    $this->start();
	}
	
	static function getWorstations($ATMdb) {
		$TWorkstation=array();
		$sql = "SELECT rowid, libelle FROM ".MAIN_DB_PREFIX."asset_workstation";
		$ATMdb->Execute($sql);
		while($ATMdb->Get_line()){
			$TWorkstation[$ATMdb->Get_field('rowid')]=$ATMdb->Get_field('libelle');
		}
		return $TWorkstation;
	}
	
}
