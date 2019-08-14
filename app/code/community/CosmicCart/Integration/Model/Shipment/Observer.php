<?php
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

require_once('CosmicCart/Integration/OAuth2Client.php');
/**
 * Created by IntelliJ IDEA.
 * User: mcsenter
 * Date: 9/30/13
 * Time: 10:50 AM
 * To change this template use File | Settings | File Templates.
 */
class CosmicCart_Integration_Model_Shipment_Observer
{
    public function onSalesOrderShipmentSaveAfter(Varien_Event_Observer $observer)
    {
        error_log("Shipment was saved!");
        /* We are observing all shipments, but we are really interested in only those resulting from a Cosmic Cart purchase. */
        $shipment = $observer->getEvent()->getShipment();
        $order = $shipment->getOrder();
        error_log("Getting orderId from order...");
        $cosmicOrderId = $order->getCosmicCartOrderId();
        error_log("Cosmic cart Order Id: $cosmicOrderId");
        if (!empty($cosmicOrderId)) {
            $payment = $order->getPayment();
            if (!empty($payment)) {
                /* We must have a Payment and that Payment must have been with our custom "cosmiccart" method */
                $method = $payment->getMethod();
                error_log("payment method: $method");
                if ('cosmiccart' == $method) {
                    $package = $this->shipmentToPackage($shipment);
                    $client = new OAuth2Client();
                    error_log("shipandsettle()");
                    $package = $client->shipAndSettle($package);
                }
            }
        }

        return $this;
    }

    private function shipmentToPackage(Mage_Sales_Model_Order_Shipment $shipment)
    {
        $dateTime = DateTime::createFromFormat('Y-m-d H:i:s', $shipment->getCreatedAt());
        ob_start();
        var_dump(DateTime::getLastErrors());
        $contents = ob_get_contents();
        ob_end_clean();
        error_log($contents);
        $package = array(
            'subOrder' => array('id' => (int) $shipment->getOrder()->getCosmicCartOrderId()),
            'packageItems' => array(),
            'shipDate' => $dateTime->format('c'),
            'trackings' => array()
        );
        foreach ($shipment->getTracksCollection() as $track) {
            $package['trackings'][] = $track->getNumber();
        }
        foreach ($shipment->getItemsCollection() as $item) {
            $orderItem = Mage::getModel('sales/order_item')->load($item->getOrderItemId());
            for ($i = 0; $i < $item->getQty(); ++$i) {
                $packageItem = array(
                    'orderItem' => array('id' => (int) $orderItem->getCosmicCartOrderItemId()),
                    'number' => $i
                );
                $package['packageItems'][] = $packageItem;
            }
        }
        return $package;
    }
}