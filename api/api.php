<?PHP 

class Api
{	
	// DB settings
	//private $db_servername = "localhost";
	private $db_username = "postgres";
	private $db_password = "";
	private $db_dbname = "ktru";
	private $conn;

	// Parse stats
	public $ins = 0;
	public $upd = 0;
	public $del = 0;

	// Files folder
	public $files_folder = './import_files';
	public $src_files_folder = './src_files';

	// Files
	public $filesXML = array();
	public $filesXLSX = array();

	// Import data
	public $import_dataXML = array();
	public $import_dataXLSX = array();

	public $import_batches = array();

	// Errors
	public $errors = array();



	const ZAKUPKI_NAMESPACE = "http://zakupki.gov.ru/oos/types/1";
	const PG_COLLECTION = 'public';
	const NO_OKEI = 283;

	const SINGLE_VALUE = 'SINGLE_VALUE';
	const MULTI_VALUE = 'MULTI_VALUE';
	const FINAL_VALUE = 'FINAL';

	public function __construct() {
		// Set up DB connection
		$this->conn = pg_Connect("dbname=".$this->db_dbname." user=".$this->db_username." password=".$this->db_password);
	}

	public function importData(){
		$this->findLocalFiles();	

		// prepare xml files
		$this->loadLocalFilesXML();	
		$this->createImportBatchesXML();

		//prepare xls files
		/*
		$this->loadLocalFilesXLSX();
		$this->createImportBatchesXLSX();
		*/

		// make import
		$this->proceedImport();
	}

	public function unzipSrcFiles(){
		$this->findLocalFiles(true);

		$zip = new ZipArchive;
		$dir = new DirectoryIterator($this->src_files_folder);
		foreach ($dir as $k=>$fileinfo) {
		    if (!$fileinfo->isDot()) {
				$fileName = $fileinfo->getFilename();

				$fileNameArr = explode(".", $fileName);
				if($fileNameArr[count($fileNameArr) - 1] == 'zip'){
					$res = $zip->open($this->src_files_folder."/".$fileName);
					if ($res === TRUE) {
						$zip->extractTo($this->files_folder);
						$zip->close();
					} else {
					}
				}
			}
		}
	}

	private function findLocalFiles($delete = false){
		$dir = new DirectoryIterator($this->files_folder);
		foreach ($dir as $k=>$fileinfo) {
		    if (!$fileinfo->isDot()) {
		    	$fileName = $fileinfo->getFilename();	
				
				if(!$delete){
					$fileNameArr = explode(".", $fileName);
					if($fileNameArr[count($fileNameArr) - 1] == 'xml'){
						$this->filesXML[] = $fileName;
					} else if($fileNameArr[count($fileNameArr) - 1] == 'xlsx'){
						$timestamp = time();
						$newName = $timestamp.'_'.$k.'.xlsx';
						rename($this->files_folder."/".$fileName, $this->files_folder."/".$newName);
						$this->filesXLSX[] = $newName;
					}
				} else {
					unlink($this->files_folder."/".$fileName);
				}
		    }
		}
	}

	private function getColumnValueById($table, $id, $column){
		$query = "SELECT ".$column." FROM ".self::PG_COLLECTION.".".$table." WHERE id=".$id;
		$result = pg_exec($this->conn, $query);
		$numrows = pg_numrows($result);
		if($numrows > 0){
			$row = pg_fetch_assoc($result);
			$value = $row[$column];
		}else{
			$value = false;
		}
		return $value;
	}
	 
	private function findTableValueId($table, $data, $create = false, $return_id = true){	
		if(is_array($data) && count($data) > 0){
			$helper_array = array();
			foreach($data as $column=>$value){
				$helper_array[] = $column."='".$value."'";
			}
			$condition = implode(" AND ", $helper_array);
			$query = "SELECT * FROM ".self::PG_COLLECTION.".".$table." WHERE ".$condition;
			$result = pg_exec($this->conn, $query);
			$numrows = pg_numrows($result);
			if($numrows > 0){
				if($return_id){
					$row = pg_fetch_assoc($result);
					$id = $row['id'];
				} else {
					$id = true;
				}
			}else{
				$id = false;
			}
		}else{
			$id = false;
		}

		if(!$id && $create){
			$id = $this->createTableValue($table, $data, $return_id);
		}
		
		return $id;
	}

	private function updateTableValueById($table, $data, $id){
		if(is_array($data) && count($data) > 0){
			$key_value_array = array();
			foreach($data as $c=>$v){
				$key_value_array[] = "\"".$c."\" = '".$v."'";
			}
			$key_value_string = implode(" , ", $key_value_array);

			$query = "UPDATE \"".self::PG_COLLECTION."\".\"".$table."\" SET ".$key_value_string." WHERE id=".$id;
			$result = pg_exec($this->conn, $query);
			$this->upd += pg_affected_rows($result);

		}
	}

	private function createTableValue($table, $data, $return_id = true){
		if(is_array($data) && count($data) > 0){
			$helper_columns = array();
			$helper_values = array();
			foreach($data as $column=>$value){
				$helper_columns[] = '"'.$column.'"';
				$helper_value[] = "'".$value."'";
			}
			$columns = implode(" , ", $helper_columns);
			$values = implode(" , ", $helper_value);

			$query = "INSERT INTO \"".self::PG_COLLECTION."\".\"".$table."\" (".$columns.") VALUES (".$values.")";

			if($return_id){
				$query .= " RETURNING id";

				$result = pg_exec($this->conn, $query);
				$this->ins += pg_affected_rows($result);
				$row = pg_fetch_assoc($result);
				$id = $row['id'];
				return $id;
			} else {
				$result = pg_exec($this->conn, $query);
				$this->ins += pg_affected_rows($result);
				$ins = pg_affected_rows($result);
				if($ins > 0){
					return true;
				} else {
					return false;
				}
			}
		}else{
			return false;
		}
	}

	public function getTableData($table){
		$query = "SELECT * FROM ".self::PG_COLLECTION.".".$table;
		$result = pg_exec($this->conn, $query);
		$numrows = pg_numrows($result);

		if($numrows > 0){
			$data = pg_fetch_all($result);
		} else {
			$data = false;
		}

		return $data;
	}

	private function findOkpdByClassifier($classifiers){
		if(count($classifiers) > 0){
			foreach($classifiers as $class){
				$kkn_id = $this->findTableValueId('nsi_classifier_category_item', array(
					'name' => $class['name'],
					'code' => $class['code']
				));
				if($kkn_id){
					$rec_id = $this->findTableValueId('nsi_ktru_okpd_kkn', array(
						'kkn_item_id' => $kkn_id,
						'actual' => true
					));
					if($rec_id){
						$okpd_id = $this->getColumnValueById('nsi_ktru_okpd_kkn', $rec_id, 'okpd_id');
						return $okpd_id;
					}
				}
			}
		}
		return false;
	}

	private function loadLocalFilesXML(){
		if(count($this->filesXML) > 0){
			foreach($this->filesXML as $file){

				$xmlfile = $this->files_folder."/".$file;
				$reader = new XMLReader();
				$reader->open($xmlfile);
				$data = array();

				while($reader->read()) {
					if ($reader->nodeType == \XMLReader::ELEMENT && $reader->name == 'oos:position') {
						$node = new SimpleXMLElement($reader->readOuterXML());
						$namespace = $node->children(self::ZAKUPKI_NAMESPACE);
						$data[] = $namespace;
					}
				}

				$reader->close();
				
				if(count($this->import_dataXML) > 0){
					$this->import_dataXML = array_merge($this->import_dataXML, $data);
				} else {
					$this->import_dataXML = $data;
				}
			}
		} else {
			$this->errors[] = "No xml files to read";
		}
	}

	private function loadLocalFilesXLSX(){
		if(count($this->filesXLSX) > 0){
			include_once './PHPExcel.php';
			include_once './PHPExcel/IOFactory.php';
			foreach($this->filesXLSX as $file){
				$fileName = $this->files_folder."/".$file;
				$objPHPExcel = PHPExcel_IOFactory::load($fileName);

				foreach ($objPHPExcel->getAllSheets() as $sheet) {
					$title = $sheet->getTitle();
					if (strpos($title, 'абло') !== false) {
					    continue;
					}else{
						$sheetData = $sheet->toArray(null,true,true,true);;
						$removeRows = array(0,1,2,3,4,5,6);

						$sheetData = array_diff_key($sheetData, array_flip($removeRows));
						$sheetData = array_values($sheetData);
						if(count($this->import_dataXLSX) > 0){
							$this->import_dataXLSX = array_merge($this->import_dataXLSX, $sheetData);
						} else {
							$this->import_dataXLSX = $sheetData;
						}
					}
		        }
				
			}
		} else {
			$this->errors[] = "No xlsx files to read";
		}
	}

	private function createImportBatchesXML(){
		if(count($this->import_dataXML) > 0){
			foreach($this->import_dataXML as $item){
				$batch = array();

				// ktru_catalog
				$ktru_catalog = array(
					'code' => $item->data->code->__toString()
				);

				// list_okpd2
				if($item->data->OKPD2){
					$list_okpd2 = array(
						'code' => $item->data->OKPD2->code->__toString(),
						'name' => $item->data->OKPD2->name->__toString()
					);
				}

				// ktru_position
				$ktru_position = array();
				if($item->data->name)
					$ktru_position['title'] = $item->data->name->__toString();
				if($item->data->version)
					$ktru_position['version'] = $item->data->version->__toString();
				if($item->data->inclusionDate)
					$ktru_position['inclusion_date'] = $item->data->inclusionDate->__toString();
				if($item->data->publishDate)
					$ktru_position['publish_date'] = $item->data->publishDate->__toString();
				if($item->data->actual)
					$ktru_position['actual'] = $item->data->actual->__toString();
				if($item->data->applicationDateStart)
					$ktru_position['application_date_start'] = $item->data->applicationDateStart->__toString();
				if($item->data->OKEIs && $item->data->OKEIs->OKEI && $item->data->OKEIs->OKEI->code)
					$ktru_position['okei_id'] = $item->data->OKEIs->OKEI->code->__toString();
				if($item->data->nsiDescription)
					$ktru_position['description'] = $item->data->nsiDescription->__toString();
				if($item->data->isTemplate)
					$ktru_position['is_template'] = $item->data->isTemplate->__toString();

				// nsi_classifier_category_item
				if(
					count($item->data->NSI->classifiers->classifier) > 0
				){
					$nsi_classifier_category_item = array();
					foreach($item->data->NSI->classifiers->classifier as $class){
						$class_item = array(
							'name' => $class->name->__toString(),
							'code' => $class->values->value->name->__toString()
						);
						$nsi_classifier_category_item[] = $class_item;
					}
				}

				// ktru_characterisctics
				$ktru_characterisctic = array();
				$ktru_value_characterisctic = array();
				$ktru_value_range = array();

				if(
					count($item->data->characteristics->characteristic) > 0
				){
					foreach($item->data->characteristics->characteristic as $char){
						$ktru_characterisctic[] = array(
							'title' => $char->name->__toString(),
							'code' => $char->code->__toString(),
							'kind_id' => $char->kind->__toString(),
							'type_id' => $char->type->__toString()
						);

						// ktru_value_characterisctics
						$arr = array();
						if(
							$char->values->value->qualityDescription
						){
							// Just characteristic value, no range
							$arr['value'] = $char->values->value->qualityDescription->__toString();
							// empty value_range so array indexes matches
							$ktru_value_range[] = array();
						}else if(
							$char->values->value->OKEI &&
							$char->values->value->rangeSet
						){
							// Unit id and value_range
							$arr['okei_id'] = $char->values->value->OKEI->code->__toString();

							$ktru_value_range[] = array(
								'min_value' => $char->values->value->rangeSet->valueRange->min->__toString(),
								'max_value' => $char->values->value->rangeSet->valueRange->max->__toString(),
								'min_notation' => $char->values->value->rangeSet->valueRange->minMathNotation->__toString(),
								'max_notation' => $char->values->value->rangeSet->valueRange->maxMathNotation->__toString()
							);
						}

						$ktru_value_characterisctic[] = $arr;
					}
				}

				$batch = array(
					'ktru_catalog' => $ktru_catalog,
					'list_okpd2' => $list_okpd2,
					'ktru_position' => $ktru_position,
					'ktru_characterisctic' => $ktru_characterisctic,
					'ktru_value_characterisctic' => $ktru_value_characterisctic,
					'ktru_value_range' => $ktru_value_range,
					'nsi_classifier_category_item' => $nsi_classifier_category_item
				);
				$this->import_batches[] = $batch;
			}
		} else {
			$this->errors[] = "No data to import from xml";
		}
	}

	private function createImportBatchesXLSX(){
		if(count($this->import_dataXLSX) > 0){
			foreach($this->import_dataXLSX as $row=>$item){
				if(
					$item['A'] && $item['B'] && $item['M']
				){
					$batch = array();

					// ktru_catalog
					$ktru_catalog = array();

					
					// list_okpd2
					if($item['M'] == "ОКПД2"){
						$list_okpd2 = array(
							'code' => $item['N']
						);
					}

					// ktru_position
					$id = $this->findTableValueId('list_okei', array('symbol' => $item['C']));
					$okei_id = $this->getColumnValueById('list_okei', $id, 'code');

					$ktru_position = array(
						'title' => $item['B'],
						'okei_id' => $okei_id,
						'description' => $item['O'],
						'is_template' => false
					);

					// nsi_classifier_category_item
					$nsi_classifier_category_item = array();

					// Find current item options and find next item row
					$rows = count($this->import_dataXLSX);
					$cursor = $row + 1;
					$optionsRows = array();
					if($item['D']){
						$optionsRows[] = $row;
					}
					$skip = 0;
					while( $cursor < $rows ){
						if($this->import_dataXLSX[$cursor]['B']){
							break;
						} else if ($this->import_dataXLSX[$cursor]['D']){
							$optionsRows[] = $cursor;
						}
						$cursor++;
						$skip++;
					}
					
					// ktru_characterisctics
					
					$ktru_characterisctic = array();
					$ktru_value_characterisctic = array();
					$ktru_value_range = array();

					if(count($optionsRows) > 0){
						foreach($optionsRows as $okey=>$index){

							$ktru_characterisctic_item = array();
							$ktru_value_characterisctic_item = array();
							$ktru_value_range_item = array();

							if($this->import_dataXLSX[$index]['D']){
								$ktru_characterisctic_item['title'] = $this->import_dataXLSX[$index]['D'];
								$ktru_characterisctic_item['kind_id'] = $this->formatKindId($this->import_dataXLSX[$index]['I']);
								//echo $this->import_dataXLSX[$index]['K'];
								if($this->import_dataXLSX[$index]['K']){
									$ktru_characterisctic_item['tech_reglament'] = $this->import_dataXLSX[$index]['K'];
								}

								if(
									$ktru_characterisctic_item['kind_id'] == self::MULTI_VALUE || 
									$ktru_characterisctic_item['kind_id'] == self::SINGLE_VALUE
								){
									// here we fill in multiple or single value from a list
									$options_array = array();
									$total_options = count($optionsRows);
									if($okey == $total_options - 1){
										// last option in item
										$stepper = 0;
										while(
											(
												(
													$this->import_dataXLSX[$index + $stepper]['D'] && 
													$this->import_dataXLSX[$index + $stepper]['D'] == $this->import_dataXLSX[$index]['D']
												) ||
												!$this->import_dataXLSX[$index + $stepper]['D']
											) &&
											($index + $stepper) < $rows
										){
											$options_array[] = $this->import_dataXLSX[$index + $stepper]['F'];
											$stepper++;
										}
									} else {
										// not last option
										for($r = $index; $r < $optionsRows[$okey + 1]; $r ++){
											$options_array[] = $this->import_dataXLSX[$r]['F'];
										}
									}

									$ktru_value_characterisctic_item['value'] = serialize($options_array);
									
								} else {
									if($this->hasRangeSymbols($this->import_dataXLSX[$index]['F'])){
										// it has range
										$left_range = $this->convertXLSXRange($this->import_dataXLSX[$index]['F']);
										$ktru_value_range_item['min_value'] = $left_range[1];
										$ktru_value_range_item['min_notation'] = $left_range[0];
									}
									if($this->hasRangeSymbols($this->import_dataXLSX[$index]['G'])){
										// it has range
										$right_range = $this->convertXLSXRange($this->import_dataXLSX[$index]['G']);
										$ktru_value_range_item['max_value'] = $right_range[1];
										$ktru_value_range_item['max_notation'] = $right_range[0];
									} 

									if(count($ktru_value_range_item) == 0){
										// only value
										$ktru_value_characterisctic_item['value'] = $this->import_dataXLSX[$index]['F'];
									}
								}

								
									

								

								if($this->import_dataXLSX[$index]['E']){
									if($this->import_dataXLSX[$index]['E'] == 'x' || $this->import_dataXLSX[$index]['E'] == 'х'){
										$okei_id = self::NO_OKEI;
									} else {
										$id = $this->findTableValueId('list_okei', array('symbol' => $this->import_dataXLSX[$index]['E']));
										$okei_id = $this->getColumnValueById('list_okei', $id, 'code');
									}
									
									$ktru_value_characterisctic_item['okei_id'] = $okei_id;
								}								

								$ktru_characterisctic[] = $ktru_characterisctic_item;
								$ktru_value_characterisctic[] = $ktru_value_characterisctic_item;
								$ktru_value_range[] = $ktru_value_range_item;
							} else {
								$this->errors[] = 'No name for current option row in XLSX';
							}
						}
					}

					
					$batch = array(
						'ktru_catalog' => $ktru_catalog,
						'list_okpd2' => $list_okpd2,
						'ktru_position' => $ktru_position,
						'ktru_characterisctic' => $ktru_characterisctic,
						'ktru_value_characterisctic' => $ktru_value_characterisctic,
						'ktru_value_range' => $ktru_value_range,
						'nsi_classifier_category_item' => $nsi_classifier_category_item
					);
					$this->import_batches[] = $batch;
					
				} else {
					$this->errors[] = 'Not enough data for batch in XLSX import data';
				}
				
			}
		}
	}
	private function hasRangeSymbols($string){
		if (
			strpos($string, '≥') !== false ||
			strpos($string, '>') !== false ||
			strpos($string, '<') !== false ||
			strpos($string, '≤') !== false
		) {
			return true;
		} else {
			return false;
		}
	}

	private function formatKindId($string){

		if( strpos($string, 'ножеств' ) !== false ){
			$kind = self::MULTI_VALUE;
		} else if( strpos($string, 'динств')  !== false ){
			$kind = self::SINGLE_VALUE;
		} else{
			$kind = self::FINAL_VALUE;
		}

		return $kind;

	}
	private function convertXLSXRange($range){
		if (strpos($range, '≥') !== false) {
			$type = "greaterOrEqual";
			$value = trim(str_replace('≥', '', $range));
		} else if (strpos($range, '>') !== false) {
			$type = "greater";
			$value = trim(str_replace('>', '', $range));
		} else if (strpos($range, '<') !== false) {
			$type = "less";
			$value = trim(str_replace('<', '', $range));
		} else if (strpos($range, '≤') !== false) {
			$type = "lessOrEqual";
			$value = trim(str_replace('≤', '', $range));
		} else {
			$type = "";
			$value = "";
		}
		return array($type, $value);
	}

	private function proceedImport(){
		if(count($this->import_batches) > 0){
			foreach($this->import_batches as $i=>$batch){

				// Getting okpd_id for nsi_ktru_position
				if($batch['list_okpd2']){
					$okpd_id = $this->findTableValueId('list_okpd2', array('code' => $batch['list_okpd2']['code']));
				}else{
					// Try to get okpd from another classifier
					if(count($batch['nsi_classifier_category_item']) > 0){
						$okpd_id = $this->findOkpdByClassifier($batch['nsi_classifier_category_item']);
						if(!$okpd_id)
							$this->errors[] = "Cant find okpd_id by alternative classifier in nso_classifier_category_item";
					} else {
						$this->errors[] = "No list_okpd2 array in batch and no alternative classifiers";
					}
					
				}

				// Get okei_id from okei code for nsi_ktru_position
				if($batch['ktru_position']['okei_id']){
					$okei_id = $this->findTableValueId('list_okei', array('code' => $batch['ktru_position']['okei_id']));
				}else{
					$this->errors[] = "No okei_id in ktru_position array in batch";
				}

				// Processing characteristics
				$characteristics_ids = array();
				if(count($batch['ktru_characterisctic']) > 0){
					foreach($batch['ktru_characterisctic'] as $k=>$char){
						// Find or create nsi_ktru_characteristic
						$characteristic_id = $this->findTableValueId('nsi_ktru_characteristic', array(
							'title' => $char['title'],
							'code' => $char['code'],
							'kind_id' => $char['kind_id'],
							'type_id' => $char['type_id']
						), true);
						if($characteristic_id)
							$characteristics_ids[] = $characteristic_id;
						
						// Also make dictionary records
						// --nsi_ktru_value_dictionary
						$nsi_ktru_value_dictionary_id = $this->findTableValueId('nsi_ktru_value_dictionary', array('title' => $char['title']), true);
						// --nsi_ktru_value_dictionary_value
						$this->findTableValueId('nsi_ktru_value_dictionary_value', array(
							'code' => $char['code'],
							'value_dictionary_id' => $nsi_ktru_value_dictionary_id
						), true);

						// If we have okei_id in ktru_value_characterisctics - we also have range and need to create it
						if(
							$batch['ktru_value_characterisctic'][$k]['okei_id'] && 
							count($batch['ktru_value_range'][$k] > 0)
						){
							// Get/create nsi_ktru_value_range
							$value_range_id = $this->findTableValueId('nsi_ktru_value_range', array(
								'min_value' => $batch['ktru_value_range'][$k]['min_value'],
								'max_value' => $batch['ktru_value_range'][$k]['max_value'],
								'min_notation' => $batch['ktru_value_range'][$k]['min_notation'],
								'max_notation' => $batch['ktru_value_range'][$k]['max_notation']
							), true);

							// Link value_range to characteristic
							// -- get okei_id from okei_code
							$c_okei_id = $this->findTableValueId('list_okei', array('code' => $batch['ktru_value_characterisctic'][$k]['okei_id']));

							// -- get/create nsi_ktru_value_characteristic table record
							$new_record = $this->findTableValueId('nsi_ktru_value_characteristic', array(
								'characteristic_id' => $characteristic_id,
								'okei_id' => $c_okei_id,
								'value_range_id' => $value_range_id
							), true);

							if(!$new_record){
								$this->errors[] = "Failed to get/create nsi_ktru_value_characteristic record after value_range create for batch [".$k."]";
							}

						} else if($batch['ktru_value_characterisctic'][$k]['value']){
							// Get/create nsi_ktru_value_characteristic only without value_range
							
							$new_record = $this->findTableValueId('nsi_ktru_value_characteristic', array(
								'characteristic_id' => $characteristic_id,
								'value' => $batch['ktru_value_characterisctic'][$k]['value'],
								'okei_id' => self::NO_OKEI
							), true);

							if(!$new_record){
								$this->errors[] = "Failed to get/create nsi_ktru_value_characteristic record for only value";
							}
						}
					}
				}else{
					//$this->errors[] = "0 characteristics on item batch in[".$i."] ()";
				}

				
				// Get/create nsi_ktru_position record

				$prep_data = array();
				if($batch['ktru_position']['title'])
					$prep_data['title'] = $batch['ktru_position']['title'];
				if($batch['ktru_position']['version'])
					$prep_data['version'] = $batch['ktru_position']['version'];
				if($batch['ktru_position']['description'])
					$prep_data['description'] = $batch['ktru_position']['description'];
				if($batch['ktru_position']['publish_date'])
					$prep_data['publish_date'] = $batch['ktru_position']['publish_date'];
				if($batch['ktru_position']['application_date_start'])
					$prep_data['application_date_start'] = $batch['ktru_position']['application_date_start'];
				if($batch['ktru_position']['actual'])
					$prep_data['actual'] = $batch['ktru_position']['actual'];
				if($batch['ktru_position']['inclusion_date'])
					$prep_data['inclusion_date'] = $batch['ktru_position']['inclusion_date'];
				if($batch['ktru_position']['is_template'])
					$prep_data['is_template'] = $batch['ktru_position']['is_template'];

				$prep_data['okei_id'] = $okei_id;
				$prep_data['okpd_id'] = $okpd_id;


				// search in ktru_catalog to decide whether update or create position
				$exist_id = $this->findTableValueId('nsi_ktru_catalog', array('code' => $batch['ktru_catalog']['code']));
				if(!$exist_id){
					// no position in ktru_catalog table -> check or create new
					$position_id = $this->findTableValueId('nsi_ktru_position', $prep_data, true);
				}else{
					// ther is position in ktru_catalog table
					$position_id = $this->getColumnValueById('nsi_ktru_catalog', $exist_id, 'position_id');
					$prep_data['id'] = $position_id;

					// check if it needs to be updated
					$same = $this->findTableValueId('nsi_ktru_position', $prep_data);
					if(!$same){
						$this->updateTableValueById('nsi_ktru_position', $prep_data, $position_id);
					}
				}
				

				// Link position to its characteristics
				if(count($characteristics_ids) > 0){
					foreach($characteristics_ids as $char_id){
						$link_id = $this->findTableValueId('nsi_ktru_position_characteristic', array(
							'position_id' => $position_id,
							'characteristic_id' => $char_id
						), true, false);

						if(!$link_id)
							$this->errors[] = "Can not link characteristic with position in nsi_ktru_position_characteristic table";
					}
				}

				// Fill category / catalog tables
				// nsi_ktru_category

				if($batch['list_okpd2']['name']){
					$nsi_ktru_category_id = $this->findTableValueId('nsi_ktru_category', array(
						'title' => $batch['list_okpd2']['name']
					), true);
				} else if($batch['list_okpd2']['code']){
					// get okpd2 name id by code
					$okpd2id = $this->findTableValueId('list_okpd2', array(
						'code' => $batch['list_okpd2']['code']
					));
					
					if($okpd2id){
						$okpd2name = $this->getColumnValueById('list_okpd2', $okpd2id, 'name');

						if($okpd2name){
							$nsi_ktru_category_id = $this->findTableValueId('nsi_ktru_category', array(
								'title' => $okpd2name
							), true);
						} else {
							$this->errors[] = "Cant get okpd2name by id";
						}
						
					} else {
						$this->errors[] = "Cant get okpd2id by code";
					}
				} else {
					$this->errors[] = "Cant fill catalog tables because there are no okpd2 ID";
				}

				// nsi_ktru_catalog
				if($nsi_ktru_category_id){
					$lft_val = explode(".", $batch['list_okpd2']['code'])[0];
					$lft_id = $this->findTableValueId('list_okpd2', array(
						'code' => $lft_val
					));
					if(!$lft_id){
						$lft_id = 0;
						$this->errors[] = 'Couldnt find okpd2 id by code: '.$lft_val;
					}

					$rgt_id = $this->findTableValueId('list_okpd2', array(
						'code' => $batch['list_okpd2']['code']
					));
					if(!$lft_id){
						$rgt_id = 0;
						$this->errors[] = 'Couldnt find okpd2 id by code: '.$batch['list_okpd2']['code'];
					}

					$ins_id = $this->findTableValueId('nsi_ktru_catalog', array(
						'code' => $batch['ktru_catalog']['code'],
						'position_id' => $position_id,
						'sort_ind' => 0,
						'lft' => $lft_id,
						'rgt' => $rgt_id,
						'category_id' => $nsi_ktru_category_id
					), true);
					
					if(!$ins_id){
						$this->errors[] = "Failed to insert into nsi_ktru_catalog";
					}
				} else {
					$this->errors[] = "Cant add record to nsi_ktru_catalog. No category id found";
				}
			}
		}
	}

	public function printErrors(){
		if(count($this->errors) > 0){
			echo "<br><hr><p>Errors / Warnings:</p><pre>";
			print_r($this->errors);
			echo "<pre><br><hr>";
		}
	}

	public function printStats(){
		echo "<br><hr>";
		echo "INSERTED: ".$this->ins." rows<br>";
		echo "UPDATED: ".$this->upd." rows<br>";
		echo "DELETED: ".$this->del." rows<br><hr>";
	}

	public function searchProduct($string){
		$output = array();
		$template = "SELECT p.id, p.title, p.description, c.code
			FROM ".self::PG_COLLECTION.".".nsi_ktru_position." p
			INNER JOIN ".self::PG_COLLECTION.".".nsi_ktru_catalog." c ON p.id = c.position_id";
		

		// First - try by code
		$query = $template." WHERE c.code LIKE '%".$string."%'";
		$results = pg_exec($this->conn, $query);
		$numrows = pg_numrows($results);
		if($numrows > 0){
			$result = pg_fetch_all($results);
			$output = array_merge($output, $result);
		}

		// Then try by name
		$query = $template." WHERE lower(p.title) LIKE '%".$string."%'";
		$results = pg_exec($this->conn, $query);
		$numrows = pg_numrows($results);
		if($numrows > 0){
			$result = pg_fetch_all($results);
			$output = array_merge($output, $result);
		}
		/*
		if($by == 'name'){
			$query .= " WHERE lower(p.title) LIKE '%".$string."%'";
		} else if($by == 'code'){
			$query .= " WHERE c.code='".$string."'";
		}
		*/
			
		
		
		return $output;
	}

	public function getProductDetails($code){
		$query = "SELECT cat.position_id, pos.title, pos.okpd_id, okpd.code as okpd_code, okpd.name as okpd_name	
			FROM ".self::PG_COLLECTION.".".nsi_ktru_catalog." cat 
			INNER JOIN ".self::PG_COLLECTION.".".nsi_ktru_position." pos ON cat.position_id = pos.id
			INNER JOIN ".self::PG_COLLECTION.".".list_okpd2." okpd ON pos.okpd_id = okpd.id
			WHERE lower(cat.code)='".$code."'";
		$results = pg_exec($this->conn, $query);
		$numrows = pg_numrows($results);
		if($numrows > 0){
			$data = pg_fetch_all($results);

			// get characteristics
			foreach($data as $k=>$item){
				$data[$k]['characteristics'] = array();
				$query = "SELECT characteristic_id as id FROM ".self::PG_COLLECTION.".".nsi_ktru_position_characteristic." WHERE position_id=".$item['position_id'];
				$characteristics = pg_exec($this->conn, $query);
				$numchars = pg_numrows($characteristics);
				if($numchars > 0){
					$chars = pg_fetch_all($characteristics);

					foreach($chars as $char){
						$query = "SELECT c.title, c.kind_id, cv.value, cr.min_value, cr.min_notation, cr.max_value, cr.max_notation, u.symbol as units
							FROM ".self::PG_COLLECTION.".".nsi_ktru_characteristic." c
							INNER JOIN ".self::PG_COLLECTION.".".nsi_ktru_value_characteristic." cv ON c.id = cv.characteristic_id
							LEFT OUTER JOIN ".self::PG_COLLECTION.".".nsi_ktru_value_range." cr ON cv.value_range_id = cr.id
							INNER JOIN ".self::PG_COLLECTION.".".list_okei." u ON cv.okei_id = u.id
							WHERE c.id=".$char['id'];
						$res = pg_exec($this->conn, $query);
						if(pg_numrows($res) > 0){
							$char = pg_fetch_assoc($res);
							$data[$k]['characteristics'][] = $char;
						}
					}
				}
			}
		}else{
			$data = array();
		}
		return $data;
	}

	public function parseOKPD2CSV(){
		$filename = "list_okpd2.csv";
		$handle = fopen($filename, 'r');

		function cleanInput($str){
			return trim(str_replace("'", "", $str));
		}

		$cnt = 0;
		while (($buffer = fgets($handle, 4096)) !== false) {
			$cnt++;
			if ($cnt == 1)
				continue;
			
			$row = $buffer;
			$row = iconv("Windows-1251", "UTF-8", $buffer);
			$arrl = explode(";", $row);
			
			if (count($arrl)>4) {
				$data = array(
					'id' => cleanInput($arrl[0]),
					'code' => cleanInput($arrl[1]),
					'name' => cleanInput($arrl[2]),
					'oos_id' => cleanInput($arrl[3]),
					'parent_id' => cleanInput($arrl[4]),
					'parent_code' => cleanInput($arrl[5]),
					'actual' => cleanInput($arrl[6]),
				);

				$this->findTableValueId('list_okpd2', $data, true);
			}
		}

		
	}

	public function getCatalogTree($code){
		$root_id = $this->findTableValueId('list_okpd2', array('code' => $code));

		if($root_id){
			$tree = array(
				'key' => $code,
				'title' => $this->getColumnValueById('list_okpd2', $root_id, 'name'),
				'items' => $this->getCategorySubItems($code)
			);
			
			return $tree;
		} else {
			return 'No such item in okpd2 catalog';
		}
	}

	private function getCategorySubItems($code){

		$table = 'list_okpd2';
		$query = "SELECT code, name FROM ".self::PG_COLLECTION.".".$table." WHERE parent_code='".$code."'";
		$result = pg_exec($this->conn, $query);
		$numrows = pg_numrows($result);
		$kid_items = array();

		if($numrows > 0){
			$data = pg_fetch_all($result);
			foreach($data as $item){
				$kid_items[] = array(
					'key' => $item['code'],
					'title' => $item['name'],
					'items' => $this->getCategorySubItems($item['code'])
				);
			}
		} else {
			$data = false;
		}

		return $kid_items;
	}
}