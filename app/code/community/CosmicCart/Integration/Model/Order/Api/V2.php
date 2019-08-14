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

class CosmicCart_Integration_Model_Order_Api_V2 extends Mage_Sales_Model_Order_Api_V2
{

    public function create($orderData)
    {
        $response = null;
        try {
            /* Determine stock status for each requested item and make adjustments if necessary. */
            $itemsToAdd = array();
            $itemsToFail = array();
            foreach ($orderData->items as $item) {
                /* Check the stock level of each item and sort appropriately. */
                $item->originalQty = $item->qty;
                $product = Mage::getModel('catalog/product')->loadByAttribute('sku', $item->sku);
                if (!empty($product) && $product->isSaleable()) {
                    $stockItem = Mage::getModel('cataloginventory/stock_item')->loadByProduct($product);
                    if (!empty($stockItem)) {
                        $inStock = $stockItem->getIsInStock();
                        $qtyInStock = $stockItem->getQty();
                        $isBackorderable = $stockItem->getBackorders() != Mage_CatalogInventory_Model_Stock::BACKORDERS_NO;
                        if ($inStock && ($isBackorderable || $qtyInStock >= $item->qty)) {
                            /* If we have plenty of stock or the item is backorderable, proceed as normal. */
                            $itemsToAdd[] = $item;
                        } else if ($inStock && $qtyInStock > 0) {
                            /* If we only have a few we can allocate from our requested quantity, do that, and put the rest
                            in a failed item. */
                            $item->qty = $qtyInStock;
                            $itemsToAdd[] = $item;
                            $itemToFail = new stdClass;
                            $itemToFail->sku = $item->sku;
                            $itemToFail->qty = $item->originalQty - $qtyInStock;
                            $itemsToFail[] = $itemToFail;
                        } else {
                            $itemsToFail[] = $item;
                        }
                    } else {
                        $itemsToFail[] = $item;
                    }
                } else {
                    $itemsToFail[] = $item;
                }
            }

            /* Break our results into those added and those backordered. */
            $itemStatuses = array();
            $itemsFailed = array();

            /* Can't add any items if none are available. */
            if (sizeof($itemsToAdd) > 0) {
                /* Create the quote */
                $cartApi = Mage::getSingleton("Mage_Checkout_Model_Cart_Api_V2");
                $quoteId = $cartApi->create();

                /* Add the items to the quote */
                $cartProductApi = Mage::getSingleton("Mage_Checkout_Model_Cart_Product_Api_V2");
                $cartProductApi->add($quoteId, $itemsToAdd);

                /* The default api does not allow us to set custom pricing. So let's do that ourselves. */
                $quote = Mage::getModel('sales/quote')->load($quoteId);
                $quote->setCosmicCartOrderId($orderData->orderId);
                $quoteItems = $quote->getItemsCollection();
                foreach ($quoteItems as &$quoteItem) {
                    foreach ($itemsToAdd as $item) {
                        if ($item->sku == $quoteItem->getSku()) {
                            $quoteItem->setCosmicCartOrderItemId($item->orderItemId);
                            $quoteItem->setOriginalCustomPrice($item->price);
                            break;
                        }
                    }
                }
                $quote->setTotalsCollectedFlag(false)->collectTotals();
                $quote->save();

                /* Set the customer */
                $cartCustomerApi = Mage::getSingleton("Mage_Checkout_Model_Cart_Customer_Api_V2");
                $cartCustomerApi->set($quoteId, $orderData->customer);
                $cartCustomerApi->setAddresses($quoteId, $orderData->customer_addresses);

                /*
                    Set the shipping method.

                    What happens here is that Cosmic Cart has set a ShippingOption on each OrderItem in the Order.
                    However, we must currently use the same ShippingOption for all OrderItems. #businessrule
                */
                $cartShippingApi = Mage::getSingleton("Mage_Checkout_Model_Cart_Shipping_Api_V2");
                $cartShippingApi->setShippingMethod($quoteId, $itemsToAdd[0]->shippingOption);

                /* Set our custom payment method */
                $cartPaymentApi = Mage::getSingleton("Mage_Checkout_Model_Cart_Payment_Api");
                $cartPaymentApi->setPaymentMethod($quoteId, array("method" => "cosmiccart"));

                /* Convert our cart to an order */
                $orderId = $cartApi->createOrder($quoteId);
                $order = $this->_initOrder($orderId);
                $order->setCustomerEmail($orderData->customer->email);
                $order->setCosmicCartOrderId($orderData->orderId);
                $order->save();

                /* Invoice (pay for) our order. */
                $salesOrderInvoiceApi = Mage::getSingleton("Mage_Sales_Model_Order_Invoice_Api_V2");
                $invoiceId = $salesOrderInvoiceApi->create($orderId, array(), "Payment authorized. Awaiting settlement via Cosmic Cart when items ship.");
                $invoice = Mage::getModel('sales/order_invoice')->load($invoiceId);
                $invoice->pay();
                $transactionSave = Mage::getModel('core/resource_transaction')
                    ->addObject($invoice)
                    ->addObject($invoice->getOrder())
                    ->save();

                /* Gotta reload the order to get a version with all the updates performed by the invoicing. */
                $order = $this->_initOrder($orderId);

                foreach ($order->getItemsCollection() as $item) {
                    $itemStatus = new stdClass;
                    $itemStatus->qtyFailed = 0; // Default
                    $itemStatus->sku = $item->getSku();
                    foreach ($itemsToAdd as $itemRequested) {
                        if ($itemRequested->sku == $itemStatus->sku) {
                            $item->setCosmicCartOrderItemId($itemRequested->orderItemId);
                            $item->save();
                            $itemStatus->qtyRequested = $itemRequested->originalQty;
                            break;
                        }
                    }
                    $itemStatus->tax = $item->getTaxAmount();
                    $itemStatus->qtyBackordered = $item->getQtyBackordered();
                    foreach ($itemsToFail as $itemToFail) {
                        if ($itemToFail->sku == $itemStatus->sku) {
                            $itemStatus->qtyFailed = $itemToFail->qty;
                            $itemsFailed[] = $itemStatus;
                            break;
                        }
                    }
                    $itemStatus->qtyAllocated = $itemStatus->qtyRequested - $itemStatus->qtyBackordered - $itemStatus->qtyFailed;
                    $itemStatuses[] = $itemStatus;
                }
            }
            /* Completely failed items won't be returned by the other API calls, but we still need to return a status for them. */
            foreach ($itemsToFail as $itemToFail) {
                $yetToFail = true;
                foreach ($itemsFailed as $itemAlreadyFailed) {
                    if ($itemAlreadyFailed->sku == $itemToFail->sku) {
                        $yetToFail = false;
                        break;
                    }
                }
                if ($yetToFail) {
                    $itemStatus = new stdClass;
                    $itemStatus->sku = $itemToFail->sku;
                    $itemStatus->qtyRequested = $itemToFail->qty;
                    $itemStatus->qtyAllocated = 0;
                    $itemStatus->qtyBackordered = 0;
                    $itemStatus->qtyFailed = $itemToFail->qty;
                    $itemStatuses[] = $itemStatus;
                }
            }

            $response = array(
                'orderId' => $orderId,
                'items' => $itemStatuses
            );

        } catch (Mage_Core_Exception $e) {
            error_log($e->getMessage());
            throw $e;
        }

        return $response;
    }

    public function getShippingMethodsList($addressData, $orderItemsData)
    {
        $quote = $this->createTemporaryQuote($addressData, $orderItemsData);

        $cartShippingApi = Mage::getSingleton("Mage_Checkout_Model_Cart_Shipping_Api_V2");
        $results = $cartShippingApi->getShippingMethodsList($quote->getId());

        // Clean up anything we saved to the db.
        $quote->delete();

        return $results;
    }

    protected function createTemporaryQuote($addressData, $orderItemsData, $doSave = true)
    {
        /* Create a quote to hold the items */
        $cartApi = Mage::getSingleton("Mage_Checkout_Model_Cart_Api_V2");
        $quoteId = $cartApi->create();

        /* Add the items to the quote */
        $cartProductApi = Mage::getSingleton("Mage_Checkout_Model_Cart_Product_Api_V2");
        $cartProductApi->add($quoteId, $orderItemsData);
        $quote = Mage::getModel('sales/quote')->load($quoteId);
        $quote->getBillingAddress();
        $quoteItems = $quote->getItemsCollection();
        foreach ($quoteItems as &$quoteItem) {
            foreach ($orderItemsData as $item) {
                if ($item->sku == $quoteItem->getSku()) {
                    $quoteItem->setOriginalCustomPrice($item->price);
                    break;
                }
            }
        }

        // Create shipping address model
        $shippingAddress = Mage::getModel('sales/quote_address');
        $shippingAddress->setCity($addressData->city);
        $shippingAddress->setCountryId($addressData->country_id);
        $shippingAddress->setRegion($addressData->region);
        $shippingAddress->setStreet($addressData->street);
        $shippingAddress->setPostcode($addressData->postcode);
        $shippingAddress->setAddressType('shipping');
        $shippingAddress->setCollectShippingRates(true);
        $quote->setShippingAddress($shippingAddress)->setCollectShippingRates(true);

        $quote->setTotalsCollectedFlag(false)->collectTotals();
        if ($doSave) {
            $quote->save();
        }
        return $quote;
    }

    public function getSalesTax($addressData, $orderItemsData)
    {
        $quote = $this->createTemporaryQuote($addressData, $orderItemsData, false);
        $salesTax = $quote->getTotals()['tax']->getValue();
        return $salesTax;
    }

    public function getShippingCost($addressData, $orderItemsData)
    {
        $quote = $this->createTemporaryQuote($addressData, $orderItemsData);
        $quoteId = $quote->getId();

        $shippingCost = 0.0;

        $cartShippingApi = Mage::getSingleton("Mage_Checkout_Model_Cart_Shipping_Api_V2");
        if ($cartShippingApi->setShippingMethod($quote->getId(), $orderItemsData[0]->shippingOption)) {
            $quote = Mage::getModel('sales/quote')->loadByIdWithoutStore($quoteId);
            $shippingCost = $quote->getShippingAddress()->getShippingAmount();
        }

        // Clean up anything we saved to the db.
        $quote->delete();

        return $shippingCost;
    }

}