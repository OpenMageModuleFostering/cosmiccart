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
 * AccessToken model
 *
 * @method CosmicCart_Integration_Model_Resource_AccessToken _getResource()
 * @method CosmicCart_Integration_Model_Resource_AccessToken getResource()
 * @method string getAccessToken()
 * @method CosmicCart_Integration_Model_AccessToken setAccessToken(string $value)
 * @method string getRefreshToken()
 * @method CosmicCart_Integration_Model_AccessToken setRefreshToken(string $value)
 * @method string getTokenType()
 * @method CosmicCart_Integration_Model_AccessToken setTokenType(string $value)
 * @method string getScope()
 * @method CosmicCart_Integration_Model_AccessToken setScope(string $value)
 * @method date getExpires()
 * @method CosmicCart_Integration_Model_AccessToken setExpires(date $value)
 */class CosmicCart_Integration_Model_AccessToken extends Mage_Core_Model_Abstract
{
    protected $_resourceCollectionName = 'cosmiccart_integration/accessToken_collection';

    protected function _construct()
    {
        $this->_init('cosmiccart_integration/accessToken');
    }

    public function exists() {
        $existing = $this->findExisting();
        return !empty($existing);
    }

    public function findExisting() {
        $accessToken = null;
        foreach($this->getCollection() as $token) {
            $accessToken = $token;
            break;
        }
        return $accessToken;
    }

    public function deleteExisting() {
        foreach($this->getCollection() as $accessToken) {
            $accessToken->delete();
        }
    }

}