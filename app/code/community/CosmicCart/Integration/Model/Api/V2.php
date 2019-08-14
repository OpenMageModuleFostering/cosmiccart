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
 * The class that actually get's called for V2 api calls, 
 * usually the business logic is the same for each api so
 * we just extend from the v1 api class and be done with it. 
 * 
*/
class CosmicCart_Integration_Model_Api_V2 extends CosmicCart_Integration_Model_Api
{
	// constructor
    public function __construct()
    {
    }   
}
