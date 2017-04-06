<?php

// Uncomment to enable testing & debugging
// ini_set('display_errors', '1');
// ini_set('error_reporting', E_ALL);

// Increase memory limit
// ini_set('memory_limit', '1024M');


// Determine magento root folder
$MagentoRootFolder = realpath(dirname(__FILE__));
if(file_exists($MagentoRootFolder.'/app') == false
	|| is_dir($MagentoRootFolder.'/app') == false)
{
	// Check parent folder if 'app' folder is not in the script's directory
	$MagentoRootFolder = realpath(dirname(__FILE__).'/..');
	if(file_exists($MagentoRootFolder.'/app') == false
		|| is_dir($MagentoRootFolder.'/app') == false)
	{
		MageExporterForMagento::DisplayErrorPage(
			"Neither ./app nor ../app folders were found. ".
			"Be sure to execute this script from root folder of magento");
	}	
}

// Determine Magento version and bootstrap file name
if(file_exists($MagentoRootFolder.'/app/Mage.php') == true)
{
	$BootstrapFileName = $MagentoRootFolder.'/app/Mage.php';
	define("IS_MAGENTO_2", false);
}
elseif(file_exists($MagentoRootFolder.'/app/bootstrap.php') == true)
{
	$BootstrapFileName = $MagentoRootFolder.'/app/bootstrap.php';
	define("IS_MAGENTO_2", true);
}
else
{
	MageExporterForMagento::DisplayErrorPage("boostrap.php/Mage.php file not found in ./app or ../app folder");
}

// Set working directory to magento root folder
chdir($MagentoRootFolder);


// Try to include the bootstrap file
try
{
	require $BootstrapFileName;
}
catch (\Exception $e)
{
	MageExporterForMagento::DisplayErrorPage($e->getMessage());
}

// Constructing the class executes the main function
$exporter = new MageExporterForMagento();

// Class to hold all functionality for the exporter
class MageExporterForMagento
{



	// Set the password to export data here
	const PASSWORD = '3925a75afe1d82cf4f25965024807154';
	// extracted salt of system default key - itahmid


	// Version of this script
	const VERSION = '2016-12-05';

	// Helper variables
	private $_tablePrefix;
	private $_storeId;
	private $_websiteId;
	private $_mediaBaseUrl;
	private $_webBaseUrl;
	private $_dbi;
	private $_objectManager;
	private $_STATUS_DISABLED_CONST;
	private $IncludeDisabled;
	private $ExcludeOutOfStock;
	private $DownloadAsAttachment;

	// Initialize the Mage application
	function __construct()
	{
		// Increase maximum execution time to 4 hours
		ini_set('max_execution_time', 14400);

		// Make files written by the profile world-writable/readable
		umask(0);

		if(IS_MAGENTO_2)
		{
			// Create bootstrap object
			$bootstrap = \Magento\Framework\App\Bootstrap::create(BP, $_SERVER);	

			$this->_objectManager = $bootstrap->getObjectManager();

			$deploymentConfig = $this->_objectManager->get('Magento\Framework\App\DeploymentConfig');
			$this->_tablePrefix = $deploymentConfig->get('db/table_prefix');

			$this->_dbi = $this->_objectManager->create('\Magento\Framework\App\ResourceConnection')->getConnection();
		}
		else
		{
			// Initialize the admin application
			Mage::app('admin');

			// Get the table prefix
			$tableName = Mage::getSingleton('core/resource')->getTableName('core_website');
			$this->_tablePrefix = substr($tableName, 0, strpos($tableName, 'core_website'));

			// Get database connection to Magento (PDO MySQL object)
			$this->_dbi = Mage::getSingleton('core/resource') ->getConnection('core_read');			
		}
		
		// Set default fetch mode to NUM to save memory
		$this->_dbi->setFetchMode(ZEND_DB::FETCH_NUM);

		// Run the main application
		$this->_runMain();
	}
	
	// Run the main application and call the appropriate function
	//   depending on the command.
	private function _runMain()
	{		
		// Get the command line parameters if running in CLI-mode
		if($this->_isCLI() == true)
		{
			if($_SERVER['argc'] == 2)
			{
				// Get parameters from the command line
				//  and add them to the REQUEST array
				parse_str($_SERVER['argv'][1], $_REQUEST);
				
			}
		}
		// Get parameters from the REQUEST array
		$Command = isset($_REQUEST['Command']) ? $_REQUEST['Command'] : '';
		$this->_storeId = isset($_REQUEST['Store']) ? $_REQUEST['Store'] : '';
		$Password = isset($_REQUEST['Password']) ? $_REQUEST['Password'] : '';
		$this->ExcludeOutOfStock = (isset($_REQUEST['ExcludeOutOfStock']) && $_REQUEST['ExcludeOutOfStock'] == 'on') ? true : false;
		$this->IncludeDisabled = (isset($_REQUEST['IncludeDisabled']) && $_REQUEST['IncludeDisabled'] == 'on') ? true : false;
		$this->DownloadAsAttachment = (isset($_REQUEST['DownloadAsAttachment']) && $_REQUEST['DownloadAsAttachment'] == 'on') ? true : false;		

		// If the command is export, then run the product export
		if($Command == 'Export')
		{
			// Check password
			$this->_checkPassword($Password);
			
			// Validate store and get information
			$this->_getStoreInformation();

			// Run extraction
			$this->_runProductExport();
			
			// End script
			return;
		}
		
		// If the command is export table, then run the table export
		if($Command == 'ExportTable')
		{
			// Check password
			$this->_checkPassword($Password);
			
			// Validate store and get information
			$this->_getStoreInformation();

			// Run extraction
			$this->_runTableExport();
			
			// End script
			return;
		}	

		// If the command is export table, then run the table export
		if($Command == 'DisplayForm')
		{
			// Check password
			$this->_checkPassword($Password);
			
			// Display user interface
			$this->DisplayForm();
			
			// End script
			return;
		}	

		// If the command is not specified display the password prompt
		if($Command == '')
		{
			$this->DisplayPasswordPrompt();

			// End script
			return;
		}
		
		// Display an invalid command message
		MageExporterForMagento::DisplayErrorPage("Invalid command specified.");
	}
	
	// Export data from a specific table
	private function _runTableExport()
	{
		// Get the table name
		$TableName = (isset($_REQUEST['TableName']) ? $_REQUEST['TableName'] : '');
		
		// Set allowed table names
		$AllowedTableNames = array(
			'shipping_premiumrate',
			'directory_currency_rate',
		);
		
		// Validate table name
		if(in_array($TableName, $AllowedTableNames) == false)
		{
			die('ERROR: Exporting the table \''.htmlentities($TableName).'\' is prohibited.');
		}
		
		// Check if the table exists
		if($this->_tableExists("PFX_".$TableName) == false)
		{
			die('ERROR: Can not export the table \''.htmlentities($TableName).'\' because it does not exist.');
		}
	
		// Get all the column names to print the header row
		// NOTE: Used constant TABLE_SCHEMA and TABLE_NAME to avoid directory scans
		$query = "
			SELECT COLUMN_NAME
			FROM INFORMATION_SCHEMA.COLUMNS
			WHERE TABLE_SCHEMA=DATABASE()
				AND TABLE_NAME=".$this->_dbi->quote("PFX_".$TableName)."
			ORDER BY ORDINAL_POSITION
		";
		$query = $this->_applyTablePrefix($query);
		$ColumnNames = $this->_dbi->fetchCol($query);
		if(empty($ColumnNames) == true)
		{
			die('ERROR: Could not get columns from table \''.htmlentities($TableName).'\'.');
		}
		
		// Start sending file 
		if($this->_isCLI() == false)
		{
			// Set up a file name
			$FileName = sprintf('%s.csv', $TableName);
	
			$this->_startFileSend($FileName);
		}
		
		// Write header line
		$this->_writeCSVLine($ColumnNames);
		
		// Select all the data in the table
		$query = "SELECT * FROM PFX_".$TableName;
		$query = $this->_applyTablePrefix($query);
		$result = $this->_dbi->query($query);
		while(true)
		{
			// Get next row
			$row = $result->fetch(Zend_Db::FETCH_NUM);
			// Break if no more rows
			if(empty($row))
			{
				break;
			}
			// Write the row
			$this->_writeCSVLine($row);
		}
	}

	// Extract product data natively directly from the database
	private function _runProductExport()
	{
		// Start sending file 
		if($this->_isCLI() == false)
		{
			// Set up a file name
			$FileName = sprintf('%d_%d.csv', $this->_websiteId, $this->_storeId);
	
			$this->_startFileSend($FileName);
		}

		// Check if staging module is enabled
		$StagingModuleEnabled = $this->_tableExists("PFX_catalog_product_entity", array('row_id'));

		// Check if Amasty Product Labels table exists
		$AmastyProductLabelsTableExists = $this->_tableExists("PFX_am_label");

		// Create a lookup table for the SKU to label_id
		$AmastyProductLabelsLookupTable = array();
		if($AmastyProductLabelsTableExists == true)
		{
			// NOTE: Only fetch simple labels and ignore all matching rules.
			//   include_type=0 means "all matching SKUs and listed SKUs"
			//   include_type=1 means "all matching SKUs EXCEPT listed SKUs"
			//   include_type=2 means "listed SKUs only"
			$query = "SELECT label_id, name, include_sku
				FROM PFX_am_label
				WHERE include_type IN (0,2)
				ORDER BY pos DESC";
			$query = $this->_applyTablePrefix($query);
			$labelsTable = $this->_dbi->fetchAll($query);
			// Load each label into the lookup table
			foreach($labelsTable as $row)
			{
				// Get the comma-separated SKUs
				$skus = explode(",", $row[2]);
				// Add each SKU to the lookup table
				foreach($skus as $sku)
				{
					$AmastyProductLabelsLookupTable[$sku] = array($row[0], $row[1]);
				}
			}
		}

		// Get product catalog flat table name
		$CatalogProductFlatTableName = $this->_applyTablePrefix("PFX_catalog_product_flat_".$this->_storeId);

		// Check if product catalog flat table exists
		$CatalogProductFlatTableExists = $this->_tableExists(
			$CatalogProductFlatTableName, array('url_key', 'url_path'));
		
		// Check if product category flat table exists
		$CatalogCategoryFlatTableExists = $this->_tableExists(
			"PFX_catalog_category_flat_store_".$this->_storeId);
		
		// Increase maximium length for group_concat (for additional image URLs field)
		$query = "SET SESSION group_concat_max_len = 1000000;";
		$this->_dbi->query($query);

		// By default, set media gallery attribute id to 703
		//  Look it up later
		$MEDIA_GALLERY_ATTRIBUTE_ID = 703;


		// Get the entity type for products
		$query = "SELECT entity_type_id FROM PFX_eav_entity_type
			WHERE entity_type_code = 'catalog_product'";
		$query = $this->_applyTablePrefix($query);
		$PRODUCT_ENTITY_TYPE_ID = $this->_dbi->fetchOne($query);

		// Prepare list entity table names
		$CatalogProductEntityTableNamesWithPrefix = array(
			'PFX_catalog_product_entity_datetime',
			'PFX_catalog_product_entity_decimal',
			'PFX_catalog_product_entity_int',
			'PFX_catalog_product_entity_text',
			'PFX_catalog_product_entity_varchar',
		);

		// Get attribute codes and types
		$query = "SELECT attribute_id, attribute_code, backend_type, backend_table, frontend_input
			FROM PFX_eav_attribute
			WHERE entity_type_id = $PRODUCT_ENTITY_TYPE_ID
			";
		$query = $this->_applyTablePrefix($query);
		$attributes = $this->_dbi->fetchAssoc($query);
		$attributeCodes = array();
		$blankProduct = array();
		$blankProduct['sku'] = '';
		foreach($attributes as $row)
		{
			// Save attribute ID for media gallery
			if($row['attribute_code'] == 'media_gallery')
			{
				$MEDIA_GALLERY_ATTRIBUTE_ID = $row['attribute_id'];
			}
		
			switch($row['backend_type'])
			{
				case 'datetime':
				case 'decimal':
				case 'int':
				case 'text':
				case 'varchar':
					$attributeCodes[$row['attribute_id']] = $row['attribute_code'];
					$blankProduct[$row['attribute_code']] = '';
				break;
			case 'static':
				// ignore columns in entity table
				// print("Skipping static attribute: ".$row['attribute_code']."\n");
				break;
			default:
				// print("Unsupported backend_type: ".$row['backend_type']."\n");
				break;
			}
			
			// Add table name to list of value tables, if the table exists
			if(isset($row['backend_table']) && $row['backend_table'] != '')
			{
				// Check if table exists without prefix
				if($this->_tableExists($row['backend_table']) === true)
				{
					$CatalogProductEntityTableNamesWithPrefix[] = $row['backend_table'];
				}
				else
				{
					// If not found, check if table exists with prefix
					$BackendTableWithPrefix = $this->_applyTablePrefix("PFX_".$row['backend_table']);
					if($this->_tableExists($BackendTableWithPrefix) === true)
					{
						$CatalogProductEntityTableNamesWithPrefix[] = $BackendTableWithPrefix;
					}				
				}
			}
			
			// If the type is multiple choice, cache the option values
			//   in a lookup array for performance (avoids several joins/aggregations)
			if($row['frontend_input'] == 'select' || $row['frontend_input'] == 'multiselect')
			{
				// Get the option_id => value from the attribute options
				$query = "
					SELECT
						 CASE WHEN SUM(aov.store_id) = 0 THEN MAX(aov.option_id) ELSE 
							MAX(CASE WHEN aov.store_id = ".$this->_storeId." THEN aov.option_id ELSE NULL END)
						 END AS 'option_id'
						,CASE WHEN SUM(aov.store_id) = 0 THEN MAX(aov.value) ELSE 
							MAX(CASE WHEN aov.store_id = ".$this->_storeId." THEN aov.value ELSE NULL END)
						 END AS 'value'
					FROM PFX_eav_attribute_option AS ao
					INNER JOIN PFX_eav_attribute_option_value AS aov
						ON ao.option_id = aov.option_id
					WHERE aov.store_id IN (".$this->_storeId.", 0)
						AND ao.attribute_id = ".$row['attribute_id']."
					GROUP BY aov.option_id
				";
				$query = $this->_applyTablePrefix($query);
				$result = $this->_dbi->fetchPairs($query);
				
				// If found, then save the lookup table in the attributeOptions array
				if(is_array($result))
				{
					$attributeOptions[$row['attribute_id']] = $result;
				}
				else
				{
					// Otherwise, leave a blank array
					$attributeOptions[$row['attribute_id']] = array();
				}
				$result = null;
			}
			
		}
		$blankProduct['mage_product_url'] = '';
		$blankProduct['mage_image_url'] = '';
		$blankProduct['mage_additional_image_url'] = '';
		$blankProduct['mage_additional_image_value_id'] = '';
		$blankProduct['json_categories'] = '';
		$blankProduct['json_tier_pricing'] = '';
		$blankProduct['qty'] = 0;
		$blankProduct['stock_status'] = '';
		$blankProduct['mage_color_attribute_id'] = '';
		$blankProduct['mage_regular_price'] = '';
		$blankProduct['parent_id'] = '';
		$blankProduct['entity_id'] = '';
		$blankProduct['created_at'] = '';
		$blankProduct['updated_at'] = '';
		if($AmastyProductLabelsTableExists === true)
		{
			$blankProduct['amasty_label_id'] = '';
			$blankProduct['amasty_label_name'] = '';
		}
		if($CatalogProductFlatTableExists === true)
		{
			$blankProduct['flat_url_key'] = '';
			$blankProduct['flat_url_path'] = '';
		}
		
		// Build queries for each attribute type
		$queries = array();
		foreach($CatalogProductEntityTableNamesWithPrefix as $CatalogProductEntityTableNameWithPrefix)
		{
			// Get store value if there is one, otherwise, global value
			$AttributeTypeQuery = "
				SELECT
					 CASE
						WHEN SUM(ev.store_id) = 0
						THEN MAX(ev.value)
						ELSE MAX(CASE WHEN ev.store_id = ".$this->_storeId." THEN ev.value ELSE NULL END)
					 END AS 'value'
					,ev.attribute_id
				FROM $CatalogProductEntityTableNameWithPrefix AS ev
				WHERE ev.store_id IN (".$this->_storeId.", 0)";
			// Magento 1.x has an entity_type_id column
			if(!IS_MAGENTO_2)
			{
				$AttributeTypeQuery .= " AND ev.entity_type_id = $PRODUCT_ENTITY_TYPE_ID ";
			}
			
			if($StagingModuleEnabled)
			{
				// If staging enabled, always get latest version
				$AttributeTypeQuery .= " AND ev.row_id = 
					(SELECT MAX(e.row_id) FROM PFX_catalog_product_entity AS e WHERE e.entity_id = @ENTITY_ID) ";
				$AttributeTypeQuery .= " GROUP BY ev.attribute_id, ev.row_id ";
			}
			else
			{
				$AttributeTypeQuery .= " AND ev.entity_id = @ENTITY_ID ";
				$AttributeTypeQuery .= " GROUP BY ev.attribute_id, ev.entity_id ";
			}		
			$queries[] = $AttributeTypeQuery;
		}
		$MasterProductQuery = implode(" UNION ALL ", $queries);
		// Apply table prefix to the query
		$MasterProductQuery = $this->_applyTablePrefix($MasterProductQuery);
		// Clean up white-space in the query
		$MasterProductQuery = trim(preg_replace("/\s+/", " ", $MasterProductQuery));

		// Get all entity_ids for all products in the selected store
		//  into an array - require SKU to be defined
		if($StagingModuleEnabled)
		{
			$query = "
				SELECT cpe.entity_id, MAX(cpe.row_id) AS row_id
				FROM PFX_catalog_product_entity AS cpe
				INNER JOIN PFX_catalog_product_website as cpw
					ON cpw.product_id = cpe.entity_id
				WHERE cpw.website_id = ".$this->_websiteId."
					AND IFNULL(cpe.sku, '') != ''
				GROUP BY cpe.entity_id, cpe.sku
			";
			$query = $this->_applyTablePrefix($query);
			$EntityRows = $this->_dbi->fetchAll($query);
		}
		else
		{
			$query = "
				SELECT cpe.entity_id
				FROM PFX_catalog_product_entity AS cpe
				INNER JOIN PFX_catalog_product_website as cpw
					ON cpw.product_id = cpe.entity_id
				WHERE cpw.website_id = ".$this->_websiteId."
					AND IFNULL(cpe.sku, '') != ''
			";
			$query = $this->_applyTablePrefix($query);
			// Just fetch the entity_id column to save memory
			$EntityRows = $this->_dbi->fetchCol($query);
		}
		
		// Print header row
		$headerFields = array();
		$headerFields[] = 'sku';
		foreach($attributeCodes as $fieldName)
		{
			$headerFields[] = $fieldName;
		}
		$headerFields[] = 'mage_product_url';
		$headerFields[] = 'mage_image_url';
		$headerFields[] = 'mage_additional_image_url';
		$headerFields[] = 'mage_additional_image_value_id';
		$headerFields[] = 'json_categories';
		$headerFields[] = 'json_tier_pricing';
		$headerFields[] = 'qty';
		$headerFields[] = 'stock_status';
		$headerFields[] = 'mage_color_attribute_id';
		$headerFields[] = 'mage_regular_price';
		$headerFields[] = 'parent_id';
		$headerFields[] = 'entity_id';
		$headerFields[] = 'created_at';
		$headerFields[] = 'updated_at';
		if($AmastyProductLabelsTableExists === true)
		{
			$headerFields[] = 'amasty_label_id';
			$headerFields[] = 'amasty_label_name';
		}
		if($CatalogProductFlatTableExists === true)
		{
			$headerFields[] = 'flat_url_key';
			$headerFields[] = 'flat_url_path';
		}
		$this->_writeCSVLine($headerFields);

		// Loop through each product and output the data
		foreach($EntityRows as $EntityRow)
		{
			// Get the entity_id/row_id from the row
			if($StagingModuleEnabled)
			{
				$entity_id = $EntityRow[0];
				$row_id = $EntityRow[1];
			}
			else
			{
				$entity_id = $EntityRow;
			}
		
			// Check if the item is out of stock and skip if needed
			if($this->ExcludeOutOfStock == true)
			{
				$query = "
					SELECT stock_status
					FROM PFX_cataloginventory_stock_status AS ciss
					WHERE ciss.website_id = ".$this->_websiteId."
						AND ciss.product_id = ".$entity_id."
				";
				$query = $this->_applyTablePrefix($query);
				$stock_status = $this->_dbi->fetchOne($query);
				// If stock status not found or equal to zero, skip the item
				if(empty($stock_status))
				{
					continue;
				}
			}

			// Create a new product record
			$product = $blankProduct;
			$product['entity_id'] = $entity_id;

			// Get the basic product information
			$query = "
				SELECT cpe.sku, cpe.created_at, cpe.updated_at, cpe.attribute_set_id, 
					cpe.type_id, cpe.has_options, cpe.required_options, eas.attribute_set_name
				FROM PFX_catalog_product_entity AS cpe
				LEFT OUTER JOIN PFX_eav_attribute_set AS eas ON cpe.attribute_set_id = eas.attribute_set_id
				WHERE cpe.entity_id = ".$entity_id."
			";
			$query = $this->_applyTablePrefix($query);
			$entity = $this->_dbi->fetchRow($query);
			if(empty($entity) == true)
			{
				continue;
			}
			
			// Initialize basic product data
			$product['sku'] = $entity[0];
			$product['created_at'] = $entity[1];
			$product['updated_at'] = $entity[2];
			
			// Set label information
			if($AmastyProductLabelsTableExists == true)
			{
				// Check if the SKU has a label
				if(array_key_exists($product['sku'], $AmastyProductLabelsLookupTable) == true)
				{
					// Set the label ID and name
					$product['amasty_label_id'] = $AmastyProductLabelsLookupTable[$product['sku']][0];
					$product['amasty_label_name'] = $AmastyProductLabelsLookupTable[$product['sku']][1];
				}
			}
			
			// Get flat table information
			if($CatalogProductFlatTableExists === true)
			{
				$query = "SELECT url_key,url_path FROM $CatalogProductFlatTableName WHERE entity_id=".$entity_id;
				$flatTableRow = $this->_dbi->fetchRow($query);
				if(empty($flatTableRow) === false)
				{
					$product['flat_url_key'] = $flatTableRow[0];
					$product['flat_url_path'] = $flatTableRow[1];
				}
			}
			
			// Fill the master query with the entity ID
			$query = str_replace('@ENTITY_ID', $entity_id, $MasterProductQuery);
			$result = $this->_dbi->query($query);
			
			// Escape the SKU (it may contain double-quotes)
			$product['sku'] = $product['sku'];

			// Loop through each field in the row and get the value
			while(true)
			{
				// Get next column
				// $column[0] = value
				// $column[1] = attribute_id
				$column = $result->fetch(Zend_Db::FETCH_NUM);
				// Break if no more rows
				if(empty($column))
				{
					break;
				}
				// Skip attributes that don't exist in eav_attribute
				if(!isset($attributeCodes[$column[1]]))
				{
					continue;
				}

				// Save color attribute ID (for CJM automatic color swatches extension)
				//  NOTE: do this prior to translating option_id to option_value below
				if($attributeCodes[$column[1]] == 'color')
				{
					$product['mage_color_attribute_id'] = $column[0];
				}

				// Translate the option option_id to a value.
				if(isset($attributeOptions[$column[1]]) == true)
				{
					// Convert all option values
					$optionValues = explode(',', $column[0]);
					$convertedOptionValues = array();
					foreach($optionValues as $optionValue)
					{
						if(isset($attributeOptions[$column[1]][$optionValue]) == true)
						{
							// If a option_id is found, translate it
							$convertedOptionValues[] = $attributeOptions[$column[1]][$optionValue];
						}
					}
					// Erase values that are set to zero
					if($column[0] == '0')
					{
						$column[0] = '';
					}
					elseif(empty($convertedOptionValues) == false)
					{
						// Use convert values if any conversions exist
						$column[0] = implode(',', $convertedOptionValues);
					}
					// Otherwise, leave value as-is					
				}

				// Escape double-quotes and add to product array
				$product[$attributeCodes[$column[1]]] = $column[0];
			}
			$result = null;

			// Skip product that are disabled or have no status
			//  if the checkbox is not checked (this is the default setting)
			if($this->IncludeDisabled == false)
			{			
				if(empty($product['status']) || $product['status'] == $this->_STATUS_DISABLED_CONST)
				{
					continue;
				}
			}
			
			// Get category information, if table exists
			if($CatalogCategoryFlatTableExists == true)
			{
				$query = "
					SELECT fs.entity_id, fs.path, fs.name
					FROM PFX_catalog_category_product_index AS pi
						INNER JOIN PFX_catalog_category_flat_store_".$this->_storeId." AS fs
							ON pi.category_id = fs.entity_id
					WHERE pi.product_id = ".$entity_id."
				";
				$query = $this->_applyTablePrefix($query);
				$categoriesTable = $this->_dbi->fetchAll($query);
				// Save entire table in JSON format
				$product['json_categories'] = json_encode($categoriesTable);
				// Escape double-quotes
				$product['json_categories'] = $product['json_categories'];
			}
			else
			{
				$product['json_categories'] = 'flat category table not found';
			}
			
			// Get stock quantity
			// NOTE: stock_id = 1 is the 'Default' stock
			$query = "
				SELECT qty, stock_status
				FROM PFX_cataloginventory_stock_status
				WHERE product_id=".$entity_id."
					AND website_id=".$this->_websiteId."
					AND stock_id = 1";
			$query = $this->_applyTablePrefix($query);
			$stockInfoResult = $this->_dbi->query($query);
			$stockInfo = $stockInfoResult->fetch();
			if(empty($stockInfo) == true)
			{
				$product['qty'] = '0';
				$product['stock_status'] = '';
			}
			else
			{
				$product['qty'] = $stockInfo[0];
				$product['stock_status'] = $stockInfo[1];
			}
			$stockInfoResult = null;

			// Get additional image URLs
			$galleryImagePrefix = $this->_dbi->quote($this->_mediaBaseUrl.'catalog/product');

			if(IS_MAGENTO_2)
			{
				$query = "
					SELECT
						 GROUP_CONCAT(mg.value_id SEPARATOR ',') AS value_id
						,GROUP_CONCAT(CONCAT(".$galleryImagePrefix.", mg.value) SEPARATOR ',') AS value
					FROM PFX_catalog_product_entity_media_gallery_value_to_entity AS mgvte
						INNER JOIN PFX_catalog_product_entity_media_gallery AS mg
							ON mgvte.value_id = mg.value_id
						INNER JOIN PFX_catalog_product_entity_media_gallery_value AS mgv
							ON mg.value_id = mgv.value_id
					WHERE   mgv.store_id IN (".$this->_storeId.", 0)
						AND mgv.disabled = 0
						AND ".($StagingModuleEnabled ? "mgvte.row_id=".$row_id : "mgvte.entity_id=".$entity_id)."
						AND mg.attribute_id = ".$MEDIA_GALLERY_ATTRIBUTE_ID."
					ORDER BY mgv.position ASC";
			}
			else
			{
				$query = "
					SELECT
						 GROUP_CONCAT(gallery.value_id SEPARATOR ',') AS value_id
						,GROUP_CONCAT(CONCAT(".$galleryImagePrefix.", gallery.value) SEPARATOR ',') AS value
					FROM PFX_catalog_product_entity_media_gallery AS gallery
						INNER JOIN PFX_catalog_product_entity_media_gallery_value AS gallery_value
							ON gallery.value_id = gallery_value.value_id
					WHERE   gallery_value.store_id IN (".$this->_storeId.", 0)
						AND gallery_value.disabled = 0
						AND gallery.entity_id=".$entity_id."
						AND gallery.attribute_id = ".$MEDIA_GALLERY_ATTRIBUTE_ID."
					ORDER BY gallery_value.position ASC";
			}
			$query = $this->_applyTablePrefix($query);
			$galleryValues = $this->_dbi->fetchAll($query);
			if(empty($galleryValues) != true)
			{
				// Save value IDs for CJM automatic color swatches extension support
				$product['mage_additional_image_value_id'] = $galleryValues[0][0];
				$product['mage_additional_image_url'] = $galleryValues[0][1];
			}

			// Get parent ID
			$query = "
				SELECT GROUP_CONCAT(parent_id SEPARATOR ',') AS parent_id
				FROM PFX_catalog_product_super_link AS super_link
				WHERE super_link.product_id=".$entity_id."";
			$query = $this->_applyTablePrefix($query);
			$parentId = $this->_dbi->fetchAll($query);
			if(empty($parentId) != true)
			{
				// Save value IDs for CJM automatic color swatches extension support
				$product['parent_id'] = $parentId[0][0];
			}

			// Get the regular price (before any catalog price rule is applied)
			$product['mage_regular_price'] = $product['price'];
			
			// Override price with catalog price rule, if found
			$query = "
				SELECT crpp.rule_price
				FROM PFX_catalogrule_product_price AS crpp
				WHERE crpp.rule_date = CURDATE()
					AND crpp.product_id = ".$entity_id."
					AND crpp.customer_group_id = 1
					AND crpp.website_id = ".$this->_websiteId;
			$query = $this->_applyTablePrefix($query);
			$rule_price = $this->_dbi->fetchAll($query);
			if(empty($rule_price) != true)
			{
				// Override price with catalog rule price
				$product['price'] = $rule_price[0][0];
			}

			// Calculate product URL
			if(empty($product['url_path']) == false)
			{
				$product['mage_product_url'] = $this->_urlPathJoin($this->_webBaseUrl, $product['url_path']);
			}
			else if (empty($product['flat_url_path']) == false)
			{
				$product['mage_product_url'] = $this->_urlPathJoin($this->_webBaseUrl, $product['flat_url_path']);
			}
			else if (empty($product['flat_url_key']) == false)
			{
				$product['mage_product_url'] = $this->_urlPathJoin($this->_webBaseUrl, $product['flat_url_key'].'.html');
			}
			
			// Calculate image URL
			if(empty($product['image']) == false)
			{
				$product['mage_image_url'] = $this->_urlPathJoin($this->_mediaBaseUrl, 'catalog/product');
				$product['mage_image_url'] = $this->_urlPathJoin($product['mage_image_url'], $product['image']);
			}

			// Get tier pricing information
			$query = "
				SELECT tp.qty, tp.value
				FROM PFX_catalog_product_entity_tier_price AS tp
				WHERE ".($StagingModuleEnabled ? "tp.row_id=".$row_id : "tp.entity_id=".$entity_id)."
					AND tp.website_id IN (0, ".$this->_websiteId.")
					AND tp.all_groups = 1
					AND tp.customer_group_id = 0
			";
			$query = $this->_applyTablePrefix($query);
			$tierPricingTable = $this->_dbi->fetchAll($query);
			// Save entire table in JSON format
			$product['json_tier_pricing'] = json_encode($tierPricingTable);
			// Escape double-quotes
			$product['json_tier_pricing'] = $product['json_tier_pricing'];

			// Print out the line in CSV format
			$this->_writeCSVLine($product);
		}

	}
	
	// Write line as CSV, quoting fields if needed
	private function _writeCSVLine(&$row)
	{
		$rowSize = count($row);
		$valueCount = 0;
		foreach($row as $value)
		{
			// Quote every value
			print '"';
			print str_replace('"', '""', $value);
			print '"';
			
			// Print comma or new line
			$valueCount++;
			if($valueCount < $rowSize)
			{
				print ',';
			}
			else
			{
				print "\n";
			}
		}
	}

	// Join two URL paths and handle forward slashes
	private function _urlPathJoin($part1, $part2)
	{
		return rtrim($part1, '/').'/'.ltrim($part2, '/');
	}

	// Send a output to the client browser as an inline attachment
	// Features: low-memory footprint, gzip compressed if supported
	private function _startFileSend($FileName)
	{
		// Supply last-modified date
		$gmdate_mod = gmdate('D, d M Y H:i:s', time()).' GMT';
		header("Last-Modified: $gmdate_mod");

		// Supply content headers
		header("Content-Type: text/plain; charset=UTF-8");		
		$ContentDisposition = ($this->DownloadAsAttachment ? 'attachment' : 'inline');
		header('Content-Disposition: '.$ContentDisposition.'; filename="'.$FileName.'"');
		// NOTE: Do not supply content-length header, because the file
		// may be sent gzip-compressed in which case the length would be wrong.
		
		// Add custom headers
		header("X-MageSoftware-ShoppingCart: Magento ".$this->GetMagentoVersion());
		header("X-MageSoftware-Version: ".self::VERSION);

		// Turn on zlib output compression with buffer size of 8kb
		ini_set('zlib.output_compression', 8192);
	}
	
	// Return Magento product version
	private function GetMagentoVersion()
	{
		if(IS_MAGENTO_2)
		{
			$productMetadata = $this->_objectManager->get('Magento\Framework\App\ProductMetadataInterface');
			return $productMetadata->getVersion();
		}
		else
		{
			return Mage::getVersion();
		}
	}
	
	// Display an error as an HTML page
	public static function DisplayErrorPage($ErrorMessage)
	{
		MageExporterForMagento::WritePageHeader();
		print '<div class="alert alert-danger">';
		print htmlentities($ErrorMessage);
		print '</div>';
		MageExporterForMagento::WritePageFooter();
		exit(1);
	}
	
	// Write common page header
	private static function WritePageHeader()
	{
		// Set character set to UTF-8
		header("Content-Type: text/html; charset=UTF-8");
	?><!DOCTYPE html><html lang="en"><head>
	    <meta charset="utf-8">
	    <meta http-equiv="X-UA-Compatible" content="IE=edge">
		<meta name="viewport" content="width=device-width, initial-scale=1">
	    <title>ProductDataExporter Magento</title>
	    <link href="https://maxcdn.bootstrapcdn.com/bootstrap/3.3.7/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-BVYiiSIFeK1dGmJRAkycuHAHRg32OmUcww7on3RYdg4Va+PmSTsz/K68vbdEjh4u" crossorigin="anonymous">
    </head><body><div class="container">
    	<h2><a href="#">DataExporter Magento</a></h2><?php	
	}
	
	// Write common page footer
	private static function WritePageFooter()
	{
	?>
		<!-- <div class="well 5well-sm" style="text-align:center;margin-top:1em;">Tested with <a href="#">Magento</a> 1 &amp; 2.x -->
		<!-- <a href="#">Magento</a> &middot;  -->
		<!-- Version  --><?php //echo self::VERSION; ?></div>
	</div>
	<script src="https://ajax.googleapis.com/ajax/libs/jquery/2.2.4/jquery.min.js"></script>
	<script src="https://maxcdn.bootstrapcdn.com/bootstrap/3.3.7/js/bootstrap.min.js" integrity="sha384-Tc5IQib027qvyjSMfHjOMaLkfuWVxZxUPnCJA7l2mCWNIpG9mGCD8wGNIcPD7Txa" crossorigin="anonymous"></script>
	</body></html>
	<?php
	}
	
	// Get category flat table enabled or disabled
	private function CategoryFlatIsEnabled()
	{
		if(IS_MAGENTO_2)
		{
			$config = $this->_objectManager->get('Magento\Framework\App\Config');
			$result = $config->getValue('catalog/frontend/flat_catalog_category');
			return ($result == '1');
		}
		else
		{
			return Mage::helper('catalog/category_flat')->isEnabled();
		}
	}
	
	// Display the user interface for the exporter, as a web page
	private function DisplayPasswordPrompt()
	{
		MageExporterForMagento::WritePageHeader();
		?>
		<form method="get" action="" role="form" class="form-inline">

		<fieldset class="form-group"><legend>Log In</legend>
			<label for="Password">Password:</label>
			<input type="text" name="Password" id="Password" size="20" class="form-control" />
			<input type="submit" value="Submit" class="btn btn-primary" />
		</fieldset>

		<input type="hidden" name="Command" value="DisplayForm" />

		</form>

		<?php
	
		MageExporterForMagento::WritePageFooter();
	}

	// Display the user interface for the exporter, as a web page
	private function DisplayForm()
	{
		MageExporterForMagento::WritePageHeader();
		?>

	    <form method="get" action="" role="form">
	    
	    <?php if($this->CategoryFlatIsEnabled() == false) { ?>
	    <div class="alert alert-warning">
			<a href="#" class="close" data-dismiss="alert" aria-label="close">&times;</a>
			<strong>Warning</strong>
			Category information will not be exported because category flat tables are disabled.
			<!-- <a href="http://www.atensoftware.com/p90.php?q=315">How To Enable <i class="fa fa-external-link"></i></a> -->
			<p>
				<h4>Enable Flat Product and Category Tables in Magento:</h4>
				<ul>
					<li>To enable flat table support in Magento, go to System > Configuration > Catalog > Frontend in your administration dashboard.</li>
					<li>Change the Use Flat Catalog Category and Use Flat Catalog Product settings to Yes and click the Save button.</li>
					<li>In Magento 2+, edit vendor\magento\module-catalog\etc\config.xml to set flat_catalog_category system value.</li>
					<li>After enabling the settings, go to System > Index Management and update the flat data indexes.</li>
				</ul>
			</p>
		</div>
	    <?php } ?>


		<fieldset class="form-group"><legend>Select a store</legend>
	    <table class="table table-striped">
	    <thead>
	    <tr>
	    	<th>Select</th>
	    	<th>Website ID</th>
	    	<th>Website</th>
	    	<th>Store ID</th>
	    	<th>Store</th>
	    </tr>
	    </thead>
	    <tbody>
	    <?php
	    
		// List all active website-stores
		if(IS_MAGENTO_2)
		{
			$WebSiteTableName = "store_website";
			$StoreTableName = "store";
		}
		else
		{
			$WebSiteTableName = "core_website";
			$StoreTableName = "core_store";
		}
		
		$query = "SELECT
			 w.website_id
			,w.name as website_name
			,w.is_default
			,s.store_id
			,s.name as store_name
		FROM PFX_$WebSiteTableName AS w 
			INNER JOIN PFX_$StoreTableName AS s ON s.website_id = w.website_id
		WHERE s.is_active = 1 AND w.website_id > 0
		ORDER BY w.sort_order, w.name, s.sort_order, s.name";
		$query = $this->_applyTablePrefix($query);
		$result = $this->_dbi->query($query);
		$isChecked = false;
		while(true)
		{
			// Get next row
			$row = $result->fetch(Zend_Db::FETCH_ASSOC);
			// Break if no more rows
			if(empty($row))
			{
				break;
			}
			// Display the store-website details with a radio button
			print '<tr>';
			print '<td style="text-align:center;">';
			print '<input type="radio" name="Store" value="';
			print $row['store_id'].'"';
			// Check the first one
			if($isChecked == false)
			{
				print ' checked="checked" ';
				$isChecked = true;
			}
			print '/></td>';
			print '<td style="text-align:center;">'.htmlentities($row['website_id']).'</td>';
			print '<td>'.htmlentities($row['website_name']).'</td>';
			print '<td style="text-align:center;">'.htmlentities($row['store_id']).'</td>';
			print '<td>'.htmlentities($row['store_name']).'</td>';
			print '</tr>';
			print "\n";

		}
		$result = null;
	    ?>
	    </tbody>
	    </table>
		</fieldset>
		
		<fieldset class="form-group"><legend>Select product export options</legend>
			<div class="checkbox">
				<label for="ExcludeOutOfStock"><input type="checkbox" id="ExcludeOutOfStock" name="ExcludeOutOfStock" /> Exclude out-of-stock products (stock_status=0)</label>
			</div>
			<div class="checkbox">
				<label for="IncludeDisabled"><input type="checkbox" id="IncludeDisabled" name="IncludeDisabled" /> Include disabled products (status=0)</label>
			</div>
			<div class="checkbox">
				<label for="DownloadAsAttachment"><input type="checkbox" id="DownloadAsAttachment" name="DownloadAsAttachment" /> Check to download as a file (otherwise, the data will be displayed in your browser)</label>
			</div>
		</fieldset>
		    
	    <fieldset class="form-group"><legend>Run export</legend>
		<input type="submit" value="Export the Product Data in CSV format" class="btn btn-primary btn-block" />
		</fieldset>
		
		<input type="hidden" name="Command" value="Export" />
		<input type="hidden" name="Password"
			value="<?php echo (isset($_REQUEST['Password']) ? htmlentities($_REQUEST['Password']) : ''); ?>"  />
	    
	    </form>
		<?php
	
		MageExporterForMagento::WritePageFooter();
	}
	
	// Die if the storeId is invalid
	private function _getStoreInformation()
	{
		// Check format of the ID
		if(0 == preg_match('|^\d+$|', $this->_storeId))
		{
			MageExporterForMagento::DisplayErrorPage(
				'ERROR: The specified Store is not formatted correctly: '.$this->_storeId);
		}
		
		try
		{
			if(IS_MAGENTO_2)
			{
				$storeManager = $this->_objectManager->get('\Magento\Store\Model\StoreManagerInterface');
				$store = $storeManager->getStore($this->_storeId);
				// Load the store information
				$this->_websiteId = $store->getWebsiteId();
				$this->_webBaseUrl = $store->getBaseUrl(Magento\Framework\UrlInterface::URL_TYPE_WEB);
				$this->_mediaBaseUrl = $store->getBaseUrl(Magento\Framework\UrlInterface::URL_TYPE_MEDIA);
				$this->_STATUS_DISABLED_CONST = Magento\Catalog\Model\Product\Attribute\Source\Status::STATUS_DISABLED;
			}
			else
			{
				// Get the store object
				$store = Mage::app()->getStore($this->_storeId);
				// Load the store information
				$this->_websiteId = $store->getWebsiteId();
				$this->_webBaseUrl = $store->getBaseUrl(Mage_Core_Model_Store::URL_TYPE_WEB);
				$this->_mediaBaseUrl = $store->getBaseUrl(Mage_Core_Model_Store::URL_TYPE_MEDIA);
				$this->_STATUS_DISABLED_CONST = Mage_Catalog_Model_Product_Status::STATUS_DISABLED;
			}
		}
		catch (Exception $e)
		{
			MageExporterForMagento::DisplayErrorPage(
				'ERROR: Error getting store information for Store='.$this->_storeId.
				". The store probably does not exist. ".get_class($e)." ".$e->getMessage());
		}

	}
	
	// Die if password is invalid
	private function _checkPassword($Password)
	{
		// Check if a password is defined
		if(self::PASSWORD == '')
		{
		/*	MageExporterForMagento::DisplayErrorPage('ERROR: A blank password is not allowed.'.
				' Edit this script and set a password.');*/
			MageExporterForMagento::DisplayErrorPage('ERROR: A blank password is not allowed.');
		}
		// Check the password
		if($Password != self::PASSWORD)
		{
			MageExporterForMagento::DisplayErrorPage('ERROR: The specified password is invalid.');
		}
	}
	
	// Returns true if running CLI mode
	private function _isCLI()
	{
		$sapi_type = php_sapi_name();
		return (substr($sapi_type, 0, 3) == 'cli');
	}

	// Return true if table exists in the current schema.
	// Optionally, specify column names to verify table exists with those columns.
	private function _tableExists($TableName, $ColumnNames = null)
	{
		// Convert table prefix
		$TableName = $this->_applyTablePrefix($TableName);
	
		// Check if table exists in the current schema
		// NOTE: Used constant TABLE_SCHEMA and TABLE_NAME to avoid directory scans
		$query = "SELECT COUNT(*)
			FROM INFORMATION_SCHEMA.COLUMNS
			WHERE TABLE_SCHEMA=DATABASE()
				AND TABLE_NAME='$TableName'";
				
		// Optionally check for columns
		$MinimumColumnCount = 1;
		if(isset($ColumnNames) && is_array($ColumnNames) && empty($ColumnNames) == false)
		{
			$query .= " AND COLUMN_NAME IN ('".implode("','", $ColumnNames)."')";
			$MinimumColumnCount = count($ColumnNames);
		}
		
		// Get the number of matching columns
		$CountColumns = $this->_dbi->fetchOne($query);
		
		// Return result
		return ($CountColumns >= $MinimumColumnCount);
	}
	
	// Apply prefix to table names in the query
	private function _applyTablePrefix($query)
	{
		return str_replace('PFX_', $this->_tablePrefix, $query);
	}
	
	// Print the results of a select query to output for debugging purposes and exit
	private function _debugPrintQuery($query)
	{
		print '<pre>';
		print_r($this->_dbi->fetchAll($query));
		print '</pre>';
		exit();
	}

}

?>