<?php



class ExportInventory {

	/**
	 * @var TInventory $inventory;
	 */
	public $inventory;

	/**
	 * Colums definition
	 * @var $cols
	 */
	public $cols;

	/**
	 * @var int $currentRank
	 */
	public $currentRank;

	/**
	 * Le paramètre optionnel delimiter spécifie le délimiteur (un seul caractère).
	 * @var string $delimiter
	 */
	public $delimiter = "," ;

	/**
	 * Le paramètre enclosure spécifie le caractère d'encadrement (un seul caractère).
	 * @var string $enclosure
	 */
	public $enclosure = '"' ;

	/**
	 * Le paramètre optionnel escape_char définit le caractère d'échappement (au plus un caractère). Une chaîne de caractères vide ("") désactive le mécanisme d'échappement propriétaire.
	 * @var string $escape_char
	 */
	public $escape_char = "\\";

	/**
	 * @param int $rank
	 */
	public static function increaseRank(&$rank){
		$rank++;
		return $rank;
	}

	/**
	 * ExportInventory constructor.
	 *
	 * @param TInventory $inventory
	 * @param Translate  $outputLang
	 */
	public function __construct($inventory, $outputLang =  false)
	{
		global $langs;

		if (!$outputLang) {
			$this->outputLang = $langs;
		} else {
			$this->outputLang = $outputLang;
		}

		$this->inventory = $inventory;

		$this->defineColumnField();
	}


	public function outputCsvHeaders(){
		header( 'Content-Type: text/csv' );

		$fileName = 'inventory-'.$this->inventory->getId();
		if(!ctype_space($this->inventory->title)){
			$fileName = file_clean_name($this->inventory->title);
		}

		header('Content-disposition: attachment; filename='. $fileName.'-'.date('Ymd-His').'.csv');
		header('Pragma: no-cache');
		header('Cache-Control: no-store, no-cache, must-revalidate, post-check=0, pre-check=0');
		header('Expires: 0');

	}

	public function exportCSV($outputFile = false) {
		global $conf, $hookmanager;


		$this->outputCsvHeaders();

		// output up to 5MB is kept in memory, if it becomes bigger it will automatically be written to a temporary file
		// $out = fopen('php://temp/maxmemory:'. (5*1024*1024), 'r+');

		if($outputFile){
			$out = fopen('php://output', 'w');
		}else{
			$out = fopen($outputFile, 'w');
		}


		// Export titles
		$defaultCols = $titleCols = array();
		foreach ($this->cols as $colKey => $col){
			if(!$col['status']) continue;

			$defaultCols[$colKey] = ''; // now we have a clean default array mapping columns in right order
			$titleCols[$colKey] = $col['title'];
		}

		fputcsv($out, $titleCols, $this->delimiter, $this->enclosure, $this->escape_char);

		foreach ($this->inventory->TInventorydet as $k => $TInventorydet)
		{

			$row = $this->prepareInventoryDetRow($TInventorydet);

			// init output for this row
			$outputRow = $defaultCols;

			// add content to output
			foreach ($outputRow as $key => $v){
				if(isset($row[$key])){ // bal Boa
					$outputRow = $row[$key];
				}
			}

			fputcsv($out, $outputRow, $this->delimiter, $this->enclosure, $this->escape_char);

			fclose($out);
		}
	}

	/**
	 * @param TInventorydet $TInventorydet
	 * @return array
	 */
	public function prepareInventoryDetRow(&$TInventorydet)
	{
		global $hookmanager;

		$product = & $TInventorydet->product;
		$stock = $TInventorydet->qty_stock;
		$lot = $TInventorydet->lot;

		$pmp = $TInventorydet->pmp;
		$pmp_actual = $pmp * $stock;
		$this->inventory->amount_actual+=$pmp_actual;

		$last_pa = $TInventorydet->pa;
		$current_pa = $TInventorydet->current_pa;

		if(!empty($conf->global->INVENTORY_USE_MIN_PA_OR_LAST_PA_MIN_PMP_IS_NULL) && empty($pmp_actual)) {
			if(!empty($last_pa)){ $pmp_actual = $last_pa* $stock;$pmp=$last_pa;}
			else if(!empty($current_pa)) {$pmp_actual = $current_pa* $stock; $pmp=$current_pa;}
		}


		$row = array(
			'ref' => $product->ref
			, 'label' => $product->label
			, 'barcode' => $product->barcode
			, 'qty_stock' => $stock
			, 'pmp_stock' => round($pmp_actual, 2)
			, 'pa_stock' => round($last_pa * $stock, 2)
			, 'qty_view' => $TInventorydet->qty_view ? $TInventorydet->qty_view : 0
			, 'pmp_actual' => round($pmp * $TInventorydet->qty_view, 2)
			, 'pa_actual' => round($last_pa * $TInventorydet->qty_view, 2)
			, 'qty_regulated' => $TInventorydet->qty_regulated ? $TInventorydet->qty_regulated : 0
		);

		if($this->inventory->per_batch) {
			$row['lot'] = $lot;
		}

		if(!empty($conf->global->INVENTORY_USE_MIN_PA_IF_NO_LAST_PA)) {
			$row['current_pa_stock'] = round($current_pa * $stock, 2);
			$row['current_pa_actual'] = round($current_pa * $TInventorydet->qty_view, 2);
		}

		$parameters = array(
			'row' =>& $row
		);
		$reshook=$hookmanager->executeHooks('inventoryExportColumnContent',$parameters,$this);    // Note that $object may have been modified by hook
		if ($reshook < 0)
		{
			setEventMessages($hookmanager->error, $hookmanager->errors, 'errors');
		}
		elseif (empty($reshook))
		{
			$row = array_replace($this->cols, $hookmanager->resArray); // array_replace is used to preserve keys
		}
		else
		{
			$row = $hookmanager->resArray;
		}

		return $row;
	}

	/**
	 *   	uasort callback function to Sort colums fields
	 *
	 *   	@param	array			$a    			PDF lines array fields configs
	 *   	@param	array			$b    			PDF lines array fields configs
	 *      @return	int								Return compare result
	 */
	function columnSort($a, $b) {

		if(empty($a['rank'])){ $a['rank'] = 0; }
		if(empty($b['rank'])){ $b['rank'] = 0; }
		if ($a['rank'] == $b['rank']) {
			return 0;
		}
		return ($a['rank'] > $b['rank']) ? -1 : 1;

	}


	/**
	 *   	Define Array Column Field
	 *
	 *      @return	null
	 */
	function defineColumnField(){

		global $conf, $hookmanager;

		$this->currentRank = 0; // do not use negative rank


		// usage for excel export (not implemented yet)
		$this->colsGroupsConf = array(
			'gProductInfos' => array(
				'color' => '#000000',
			),
			'gTheoretical' => array(
				'color' => '#000000',
				'bg-color' => '#e8e8ff',
			),
			'gInventored' => array(
				'color' => '#000000',
				'bg-color' => '#fffae8',
			),
			'gRegulated' => array(
				'bg-color' => '#e8fff1',
				'font-weight' => 'bold'
			),
		);

		$this->cols = array(
			'ref' => array(
				'label' => $this->outputlangs->trans('Ref'),
				'rank' => self::increaseRank($rank),
				'status' => true
			),
			'label' => array(
				'label' => $this->outputlangs->trans('ProductName'),
				'rank' => self::increaseRank($rank),
				'status' => true
			),
			'warehouse' => array(
				'label' => $this->outputlangs->trans('Warehouse'),
				'rank' => self::increaseRank($rank),
				'status' => true
			),
			'lot' => array(
				'label' => $this->outputlangs->trans('Batch'),
				'rank' => self::increaseRank($rank),
				'status' => $this->inventory->per_batch
			),
			'barcode' => array(
				'label' => $this->outputlangs->trans('Barcode'),
				'rank' => self::increaseRank($rank),
				'status' => true
			),
			// Bloc en base de donnée
			'qty_stock' => array(
				'label' => $this->outputlangs->trans('Qty'),
				'rank' => self::increaseRank($rank),
				'status' => true
			),
			'pmp_stock' => array(
				'label' => $this->outputlangs->trans('PMP'),
				'rank' => self::increaseRank($rank),
				'status' => true
			),
			'pa_stock' => array( // dernier PA
				'label' => $this->outputlangs->trans('LastWholesalePrice'),
				'rank' => self::increaseRank($rank),
				'status' => true
			),
			'current_pa_stock' => array( // PA courant;
				'label' => $this->outputlangs->trans('CurrentWholesalePrice'),
				'rank' => self::increaseRank($rank),
				'status' => !empty($conf->global->INVENTORY_USE_MIN_PA_IF_NO_LAST_PA)
			),
			// Bloc inventaire
			'qty_view' => array(
				'label' => $this->outputlangs->trans('InventoryQty'),
				'rank' => self::increaseRank($rank),
				'status' => true
			),
			'pmp_actual' => array(
				'label' => $this->outputlangs->trans('InventoryPMP'),
				'rank' => self::increaseRank($rank),
				'status' => true
			),
			'pa_actual' => array( // dernier PA
				'label' => $this->outputlangs->trans('InventoryLastWholesalePrice'),
				'rank' => self::increaseRank($rank),
				'status' => true
			),
			'current_pa_actual' => array( // PA courant;
				'label' => $this->outputlangs->trans('InventoryCurrentWholesalePrice'),
				'rank' => self::increaseRank($rank),
				'status' => !empty($conf->global->INVENTORY_USE_MIN_PA_IF_NO_LAST_PA)
			),
			'qty_regulated' => array( // PA courant;
				'label' => $this->outputlangs->trans('InventoryQtyRegulated'),
				'rank' => self::increaseRank($rank),
				'status' => true
			),
		);

		$parameters = array();
		$reshook=$hookmanager->executeHooks('inventoryDefineColumnField',$parameters,$this);    // Note that $object may have been modified by hook
		if ($reshook < 0)
		{
			setEventMessages($hookmanager->error, $hookmanager->errors, 'errors');
		}
		elseif (empty($reshook))
		{
			$this->cols = array_replace($this->cols, $hookmanager->resArray); // array_replace is used to preserve keys
		}
		else
		{
			$this->cols = $hookmanager->resArray;
		}


		// Sorting
		uasort ( $this->cols, array( $this, 'columnSort' ) );
	}


	/**
	 *   	get column position rank from column key
	 *
	 *   	@param	string		$colKey    		the column key
	 *      @return	int         rank on success and -1 on error
	 */
	function getColumnRank($colKey)
	{
		if(!isset($this->cols[$colKey]['rank'])) return -1;
		return  $this->cols[$colKey]['rank'];
	}

	/**
	 *   	get column position rank from column key
	 *
	 *   	@param	string		$newColKey    	the new column key
	 *   	@param	array		$defArray    	a single column definition array
	 *   	@param	string		$targetCol    	target column used to place the new column beside
	 *   	@param	bool		$insertAfterTarget    	insert before or after target column ?
	 *      @return	int         new rank on success and -1 on error
	 */
	function insertNewColumnDef($newColKey, $defArray, $targetCol = false, $insertAfterTarget = false)
	{
		// prepare wanted rank
		$rank = -1;

		// try to get rank from target column
		if(!empty($targetCol)){
			$rank = $this->getColumnRank($targetCol);
			if($rank>=0 && $insertAfterTarget){ $rank++; }
		}

		// get rank from new column definition
		if($rank<0 && !empty($defArray['rank'])){
			$rank = $defArray['rank'];
		}

		// error: no rank
		if($rank<0){ return -1; }

		foreach ($this->cols as $colKey =>& $colDef)
		{
			if( $rank <= $colDef['rank'])
			{
				$colDef['rank'] = $colDef['rank'] + 1;
			}
		}

		$defArray['rank'] = $rank;
		$this->cols[$newColKey] = $defArray; // array_replace is used to preserve keys

		return $rank;
	}

}
