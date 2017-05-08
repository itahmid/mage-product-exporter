<?php
    define('MAGENTO', realpath(dirname(__FILE__)));
    require_once MAGENTO . '/app/Mage.php';
    umask(0);
    Mage::app()->setCurrentStore(Mage_Core_Model_App::ADMIN_STORE_ID);
    $storeId = 0;
/*
 * Note: 
 * The csv of your categories should be in this format.
 * Category,
 * Category/Sub1,
 * Category/Sub1/Sub1a,
 * Category/Sub1/Sub1b,
 * Category/Sub2,
 *
 * After defining $string as your csv,
 * Turn your csv into an array
 * Remove your duplicates, as there are sure to be many
 * Sort the remaining array to ensure continuity
 * Trim your whitespace, just in case
 */

$a_array = explode(",", $string);
$a_array = array_unique($a_array);
asort($a_array);
foreach ($a_array as $key => $data) {
    $a_array[$key] = trim($a_array[$key]);
}

/*
 * initialize our associative array
 */
$a_pathIds = array();

foreach ($a_array as $key => $data) {

    /*
     * Most of our categories will have the following
     * in common, so we'll set them now
     */
    $cat = array();
    $cat['general']['meta_description'] = "";
    $cat['general']['is_active'] = "1";
    $cat['general']['url_key'] = "";
    $cat['general']['display_mode'] = "PRODUCTS";
    $cat['general']['is_anchor'] = 0;
    $cat['general']['meta_title'] = "";
	
    /*
     * Break up the entry into it's individual category names
     * Use the last entry as the category name
     */
    $path_elements = explode("/", $data);
    $cat['general']['name'] = end($path_elements);

    if ($data != "") {
        /*
         * If we only have one element, this Category is a top
         * level Category, so we know it's path and parent.  At
         * which point we have all the information we need to
         * build our category.  Log the resulting category id
         * in our associative array.
         */
        if (count($path_elements) == 1) {
            $cat['general']['path'] = 1;
            $cat['category']['parent'] = 1;
            $a_pathIds[$data] = createCategory($cat, $storeId);
        } else if (count($path_elements) > 1) {
            /*
             * If we have multiple elements, this Category is a
             * sub-Category.  We will now take what we know to
             * find it's parent in our associative array.
             */
            $n_parent_element = count($path_elements) - 1;
            $parent_path = "1/";
            $lookup_key = "";
            $i = 0;
            while ($i < $n_parent_element) {
                if ($i > 0) {
                    $lookup_key .= "/";
                }
                $lookup_key .= $path_elements[$i];
                $parent_path .= $a_pathIds[$lookup_key] . "/";
                $i++;
            }

            /*
             * Finish building out the parent path and applying it
             * to our category data.  Build the category and log
             * the resulting category id in our associative array.
             */
            $parent_path = rtrim($parent_path, "/");
            $parent_id = $a_pathIds[$parent_path];
            $cat['general']['path'] = $parent_path;
            $cat['category']['parent'] = $a_pathIds[$lookup_key];
            $a_pathIds[$data] = createCategory($cat, $storeId);
        }
    }
}

function createCategory($data, $storeId){
    echo "Starting {$data['general']['name']} [{$data['category']['parent']}] ...";
    $category = Mage::getModel('catalog/category');
    $category->setStoreId($storeId);

    if (is_array($data)) {
        $category->addData($data['general']);

        if (!$category->getId()) {

            $parentId = $data['category']['parent'];
            if (!$parentId) {
                if ($storeId) {
                    $parentId = Mage::app()->getStore($storeId)->getRootCategoryId();
                } else {
                    $parentId = Mage_Catalog_Model_Category::TREE_ROOT_ID;
                }
            }
            $parentCategory = Mage::getModel('catalog/category')->load($parentId);
            $category->setPath($parentCategory->getPath());
        }

        if ($useDefaults = $data['use_default']) {
            foreach ($useDefaults as $attributeCode) {
                $category->setData($attributeCode, null);
            }
        }

        $category->setAttributeSetId($category->getDefaultAttributeSetId());

        if (isset($data['category_products']) &&
            !$category->getProductsReadonly()
        ) {
            $products = array();
            parse_str($data['category_products'], $products);
            $category->setPostedProducts($products);
        }

        try {
            $category->save();
            echo "Succeeded <br /> ";
            return $category->getEntityId();
        } catch (Exception $e) {
            echo "Failed <br />";
        }
    }
}