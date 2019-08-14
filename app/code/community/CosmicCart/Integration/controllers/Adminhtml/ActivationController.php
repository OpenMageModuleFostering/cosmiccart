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

class CosmicCart_Integration_Adminhtml_ActivationController extends Mage_Adminhtml_Controller_Action
{
    // default action
    public function indexAction()
    {
        $this->loadLayout();
        $this->_setActiveMenu('cosmiccart');
        $block = $this->getLayout()->getBlock('activation');
        $block->setData('activated', Mage::getModel('cosmiccart_integration/accessToken')->exists());
        $this->renderLayout();
    }

    public function postAction()
    {
        $post = $this->getRequest()->getPost();
        try {
            if (empty($post)) {
                Mage::throwException($this->__('Invalid form data.'));
            }

            /* Configure our client. */
            $client = new OAuth2Client($post['clientId'], $post['clientSecret']);

            /* Request an access token */
            $accessToken = $client->getAccessToken($post['username'], $post['password']);

            /* Register our stores with Cosmic Cart. */
            $stores = array();
            foreach (Mage::getModel('core/store')->getCollection() as $store) {
                $stores[] = array(
                    'remoteId' => $store->getId(),
                    'locale' => $store->getConfig('general/locale/code'),
                    'active' => ($store->getIsActive() == 1)
                );
            }
            $registerStoresResponse = $client->registerStores($stores, $accessToken);
            if (empty($registerStoresResponse)) {
                throw new Exception('Could not connect to Cosmic Cart to register store(s).');
            }
            $apiUsername = $registerStoresResponse->apiUsername;
            $apiKey = $registerStoresResponse->apiKey;

            /* Find or create a CosmicCartIntegration API Role and User */
            $role = Mage::getModel('api/roles')->getCollection()
                ->addFieldToFilter('role_name', 'CosmicCartIntegration')
                ->addFieldToFilter('role_type', 'G')
                ->getFirstItem();
            if (!$role->getId()) {
                /* Create our API Role */
                error_log("Creating role...");
                $role = Mage::getModel('api/roles')
                    ->setName('CosmicCartIntegration')
                    ->setPid(false)
                    ->setRoleType('G')
                    ->save();
                /* Add permission to our API Role. */
                error_log("Adding permissions to role: " . $role->getId());
                Mage::getModel('api/rules')
                    ->setRoleId($role->getId())
                    ->setResources(array('all'))
                    ->saveRel();
            }
            $user = Mage::getModel('api/user')->getCollection()
                ->addFieldToFilter('email', 'integration@cosmiccart.com')
                ->getFirstItem();
            if ($user->getId()) {
                /* Remove the old user. */
                error_log("Deleting previous user");
                $user->delete();
            }
            /* Create our API User. */
            error_log("Creating user...");
            $user = Mage::getModel('api/user')
                ->setData(array(
                    'username' => $apiUsername,
                    'firstname' => 'Cosmic',
                    'lastname' => 'Cart',
                    'email' => 'integration@cosmiccart.com',
                    'api_key' => $apiKey,
                    'api_key_confirmation' => $apiKey,
                    'is_active' => 1,
                    'user_roles' => '',
                    'assigned_user_role' => '',
                    'role_name' => '',
                    'roles' => array($role->getId())
                ));
            $user->save()->load($user->getId());
            /* Assign our API Role to our API User. */
            error_log("Adding our role to our user " . $user->getId());
            $user->setRoleIds(array($role->getId()))
                ->setRoleUserId($user->getId())
                ->saveRelations();

            $client->saveClient();

            $message = $this->__('activation.success');
            Mage::getSingleton('adminhtml/session')->addSuccess($message);
        } catch (Exception $e) {
            Mage::getSingleton('adminhtml/session')->addError($e->getMessage());
        }
        $this->_redirect('*/*');
    }

}