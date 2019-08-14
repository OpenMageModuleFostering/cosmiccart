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
 * Integration Test API
 *
 * @category   Local
 * @package    CosmicCart_Integration
 * @author     Erik Dannenberg <erik.dannenberg@bbe-consulting.de>
 */
class CosmicCart_Integration_Model_Api extends CosmicCart_Integration_Model_Api_Resource {

	// constructor
    public function __construct()
    {
    }

    /**
     * Says hello
     * 
     * @param string name to greet
     * @return string the greeting
     */
    public function helloWorld($name) {
    	if ( $name != '' ) {
			return sprintf('Hello %s! Nice day isn\'t it?', $name);
    	} else {
    		$this->_fault('myerror_code');
		}
    }
}