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
 * Created by IntelliJ IDEA.
 * User: mcsenter
 * Date: 9/11/13
 * Time: 10:39 AM
 * To change this template use File | Settings | File Templates.
 */
class OAuth2Client
{
    public $baseApiUrl = '';
    public $client_id = '';
    public $client_secret = '';
    public $grant_type = 'password';
    public $accessTokenUri = 'oauth/token';

    public function OAuth2Client($client_id = null, $client_secret = null)
    {
        $settings = parse_ini_file('app/code/community/CosmicCart/Integration/etc/cosmiccart.ini');
        $this->baseApiUrl = $settings['base_api_url'];
        if (empty($client_id)) {
            $client = $this->loadClient();
            $client_id = $client->getClientId();
            $client_secret = $client->getClientSecret();
        }
        $this->client_id = $client_id;
        $this->client_secret = $client_secret;
    }

    public function getAccessToken($username, $password)
    {
        $params = array(
            'username' => $username,
            'password' => $password,
            'grant_type' => $this->grant_type
        );
        $accessTokenResponse = $this->get($this->accessTokenUri, $params, $this->createAuthHeader());
        return $this->storeAccessTokenFromResponse($accessTokenResponse);
    }

    public function get($api, $params, $header = null)
    {
        $url = $this->getApiUrl($api, $params);
        error_log("GET URL: $url");
        $ch = curl_init($url);
        curl_setopt_array($ch, array(
            CURLOPT_RETURNTRANSFER => 1,
            CURLOPT_HTTPHEADER => array($header)
        ));
        $response = curl_exec($ch);
        curl_close($ch);
        return json_decode($response);
    }

    protected function getApiUrl($api, $params = null)
    {
        if (strncmp($api, '/', 1)) {
            $api = '/' . $api;
        }
        $api = $this->baseApiUrl . $api;
        if (!empty($params)) {
            $api .= '?' . http_build_query($params);
        }
        return $api;
    }

    protected function createAuthHeader()
    {
        return 'Authorization: Basic ' . base64_encode($this->client_id . ':' . $this->client_secret);
    }

    private function storeAccessTokenFromResponse($accessTokenResponse)
    {
        if (empty($accessTokenResponse)) {
            throw new Exception('Could not connect to Cosmic Cart.');
        }
        if (!empty($accessTokenResponse->error)) {
            throw new Exception($accessTokenResponse->error_description);
        }

        /* Let's remove our existing token. Should be only one at any given time. */
        Mage::getModel('cosmiccart_integration/accessToken')->deleteExisting();

        $accessToken = Mage::getModel('cosmiccart_integration/accessToken');
        $accessToken->setAccessToken($accessTokenResponse->access_token);
        $accessToken->setRefreshToken($accessTokenResponse->refresh_token);
        $accessToken->setTokenType($accessTokenResponse->token_type);
        $accessToken->setScope($accessTokenResponse->scope);
        $expires_in = $accessTokenResponse->expires_in;
        $now = time();
        $expires = $now + $expires_in;
        $accessToken->setExpires($expires);

        $accessToken->save();

        return $accessToken;
    }

    public function shipAndSettle($package)
    {
        return $this->post('subOrder/package', $package);
    }

    private function post($api, $params, $accessToken = null)
    {
        $response = null;
        if (empty($accessToken)) {
            $accessToken = $this->loadAccessToken();
        }
        if (!empty($accessToken)) {
            error_log('Posting to: ' . $this->getApiUrl($api));
            $ch = curl_init($this->getApiUrl($api));
            $params = json_encode($params);
            error_log($params);
            $headers = array(
                'Content-Type: application/json',
                'Content-Length: ' . strlen($params)
            );
            $headers[] = $this->createAccessTokenHeader($accessToken);
            curl_setopt_array($ch, array(
                CURLOPT_POST => 1,
                CURLOPT_SSL_VERIFYPEER => false, // TODO Do not use this option in production!
                CURLOPT_POSTFIELDS => $params,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER => $headers
            ));
            $response = curl_exec($ch);
            curl_close($ch);
            error_log($response);
            if (empty($response)) {
                throw new Exception('Could not communicate with Cosmic Cart.');
            } else {
                $response = json_decode($response);
                if (!empty($response->error)) {
                    throw new Exception($response->error_description);
                }
            }
        } else {
            throw new Exception("Unable to load Cosmic Cart API access token.");
        }
        return $response;
    }

    public function saveClient() {
        // First delete any old ones.
        Mage::getModel('cosmiccart_integration/client')->deleteExisting();
        $client = Mage::getModel('cosmiccart_integration/client');
        $client->setClientId($this->client_id);
        $client->setClientSecret($this->client_secret);
        $client->save();
    }

    private function loadClient() {
        return Mage::getModel('cosmiccart_integration/client')->findExisting();
    }

    private function loadAccessToken()
    {
        error_log("Need to load accessToken...");
        $accessToken = null;
        $accessTokens = Mage::getModel('cosmiccart_integration/accessToken')->getCollection();
        foreach ($accessTokens as $token) {
            $accessToken = $token;
            break;
        }
        if (!empty($accessToken)) {
            error_log("Found access token in database: $accessToken");
            $now = time();
            $expires = $accessToken->getExpires();
            if ($now >= $expires) {
                /* Our access token has expired. Refresh it! */
                $params = array(
                    'refresh_token' => $accessToken->getRefreshToken(),
                    'grant_type' => 'refresh_token'
                );
                $accessTokenResponse = $this->get($this->accessTokenUri, $params, $this->createAuthHeader());
                $accessToken = $this->storeAccessTokenFromResponse($accessTokenResponse);
            }
        }
        if (empty($accessToken)) {
            throw new Exception("No access token found. Has the module been activated?");
        }
        return $accessToken;
    }

    protected function createAccessTokenHeader($accessToken)
    {
        $header = 'Authorization: Bearer ' . $accessToken->getAccessToken();
        error_log("added auth header: $header");
        return $header;
    }

    public function registerStores($stores, $accessToken)
    {
        return $this->post('seller/store', $stores, $accessToken);
    }

    public function debugJson($data)
    {
        echo "<pre>";
        print_r($data);
        echo "</pre>";

        # Redirect where ever you need **************************************************************
        #$c_session = $this->provider."_profile";
        #$_SESSION[$this->provider] = "true";
        #$_SESSION[$c_session] = $data;

        #echo("<script> top.location.href='index.php#".$this->provider."'</script>");

    }
}