/*
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Cosmic Cart license, a commercial license.
 *
 * @category   CosmicCart
 * @package    Integration
 * @copyright  Copyright (c) 2014 Cosmic Cart, Inc.
 * @license    Proprietary
 */

<?
class CosmicCart_Integration_Model_Product_Api_V2 extends Mage_Catalog_Model_Product_Api_V2
{

    public function listPageable($filters = null, $store = null, $page = 0, $pageSize = 50)
    {
        $collection = Mage::getModel('catalog/product')->getCollection()
            ->addStoreFilter($this->_getStoreId($store))
            ->addFilter('type_id', 'simple') // We don't want groups or configurables
//            ->addAttributeToSelect('name')
            ->setPage($page, $pageSize);

        /** @var $apiHelper Mage_Api_Helper_Data */
        $apiHelper = Mage::helper('api');
        $filters = $apiHelper->parseFilters($filters, $this->_filtersMap);
        try {
            foreach ($filters as $field => $value) {
                $collection->addFieldToFilter($field, $value);
            }
        } catch (Mage_Core_Exception $e) {
            $this->_fault('filters_invalid', $e->getMessage());
        }
        $result = array();
        $catalogInventoryApi = Mage::getSingleton("Mage_CatalogInventory_Model_Stock_Item_Api_V2");
        foreach ($collection as $product) {
            $productId = $product->getId();
            $data = $this->info($productId);
            $data['manufacturer'] = $product->getAttributeText('manufacturer');
            $inventoryData = $catalogInventoryApi->items($productId);
            foreach ($inventoryData[0] as $key => $value) {
                $data[$key] = $value;
            }

            /* Find parents, if any. */
            $parentIds = Mage::getModel('catalog/product_type_configurable')->getParentIdsByChild($productId);
            $parentCollection = Mage::getResourceModel('catalog/product_collection')
                ->addFieldToFilter('entity_id', array('in' => $parentIds))
                ->addAttributeToSelect('sku');
            $parentSkus = $parentCollection->getColumnValues('sku');
            foreach ($parentSkus as $parentSku) {
                $data['parentSku'] = $parentSku;
                break;
            }

            /* Find attributes */
            foreach ($parentCollection as $parent) {
                $data['attributes'] = array();
                $eavProduct = Mage::getModel('catalog/product')->load($productId);
                $configurableAttributes = $parent->getTypeInstance(true)->getConfigurableAttributesAsArray($parent);
                foreach ($configurableAttributes as $attribute) {
                    $label = $attribute['frontend_label'];
                    $attributeCode = $attribute['attribute_code'];
                    $value = $eavProduct->getAttributeText($attributeCode);
                    $data['attributes'][] = array(
                        'attribute' => $label,
                        'value' => $value
                    );
                }
                break;
            }

            /* Images */
            $mediaApi = Mage::getSingleton('Mage_Catalog_Model_Product_Attribute_Media_Api_V2');
            $data['images'] = $mediaApi->items($productId, $store);

            /* Add to result. */
            $result[] = $data;
        }
        return $result;
    }

}