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


/**
 * Client model
 *
 * @method string getClientId()
 * @method CosmicCart_Integration_Model_Client setClientId(string $value)
 * @method string getClientSecret()
 * @method CosmicCart_Integration_Model_Client setClientSecret(string $value)
 */
class CosmicCart_Integration_Model_Client extends Mage_Core_Model_Abstract
{
    protected $_resourceCollectionName = 'cosmiccart_integration/client_collection';

    protected function _construct()
    {
        $this->_init('cosmiccart_integration/client');
    }

    public function exists() {
        $existing = $this->findExisting();
        return !empty($existing);
    }

    public function findExisting() {
        $client = null;
        foreach($this->getCollection() as $c) {
            $client = $c;
            break;
        }
        return $client;
    }

    public function deleteExisting() {
        foreach($this->getCollection() as $client) {
            $client->delete();
        }
    }

}