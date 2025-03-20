<?php

namespace Craymend\Dexcom;

use GuzzleHttp\Client;
use GuzzleHttp\RequestOptions;

/**
 * Perform API calls
 */
final class Request
{
    const MODE_SANDBOX = 'sandbox';
    const MODE_PRODUCTION = 'production';

    const API_VERSION_SANDBOX = 'v2';
    const API_VERSION_PRODUCTION = 'v2';
    const DEFAULT_API_VERSION = 'v2';

    const BASE_URL_SANDBOX = 'https://sandbox-api.dexcom.com';
    const BASE_URL_SANDBOX_OUS = 'https://sandbox-api.dexcom.eu';
    const BASE_URL_PRODUCTION = 'https://api.dexcom.com';
    const BASE_URL_PRODUCTION_OUS = 'https://api.dexcom.eu';
    
    /**
     * @var string - "SANDBOX" | "PRODUCTION"
     */
    private $mode;

    /**
     * @var string
     */
    private $domainUrl;

    /**
     * @var string
     */
    private $baseUrl;

    /**
     * @var string
     */
    private $accessToken;

    /**
     * @var string
     */
    private $apiVersion;

    /**
     * @var bool
     */
    private $isOus;

    /**
     * @var GuzzleHttp\Client
     */
    private $client;

    /**
     * Defaults to "PRODUCTION" mode
     * 
     * @return null
     */
    public function __construct($accessToken='', $mode = '', $apiVersion='', $isOus=false){
        $this->accessToken = $accessToken;
        
        $this->mode = $mode ? $mode : self::MODE_PRODUCTION;
        $this->apiVersion = $apiVersion ? $apiVersion : self::DEFAULT_API_VERSION;
        $this->isOus = $isOus;

        $this->setBaseUrl($this->mode, $this->apiVersion, $this->isOus); // set baseUrl

        $this->client = new Client();

        return null;
    }

    /**
     * @return null
     */
    public function setBaseUrl($mode, $apiVersion='', $isOus=false){
        $apiVersion = $apiVersion ? $apiVersion : self::DEFAULT_API_VERSION;

        if($mode == self::MODE_SANDBOX){
            if($isOus){
                $this->domainUrl = self::BASE_URL_SANDBOX_OUS;
                $this->baseUrl = self::BASE_URL_SANDBOX_OUS . '/' . $apiVersion;
            }else{
                $this->domainUrl = self::BASE_URL_SANDBOX;
                $this->baseUrl = self::BASE_URL_SANDBOX . '/' . $apiVersion;
            }
        }else{
            if($isOus){
                $this->domainUrl = self::BASE_URL_PRODUCTION_OUS;
                $this->baseUrl = self::BASE_URL_PRODUCTION_OUS . '/' . $apiVersion;
            }else{
                $this->domainUrl = self::BASE_URL_PRODUCTION;
                $this->baseUrl = self::BASE_URL_PRODUCTION . '/' . $apiVersion;
            }
        }

        return null;
    }

    /**
     * @return string
     */
    public function getBaseUrl(){
        return $this->baseUrl;
    }

    /**
     * NOTE: As of 2023.06.16 Dexcom's /oauth2 endpoints require /v2
     * 
	 * @return string
	 */
    public function getAuthUrl($redirectUri, $clientId){
        return "$this->domainUrl/v2/oauth2/login?client_id=$clientId&redirect_uri=$redirectUri&response_type=code&scope=offline_access";
    }

    /**
     * NOTE: As of 2023.06.16 Dexcom's /oauth2 endpoints require /v2
     * 
	 * @return Response
	 */
    public function exchangeCode($code, $redirectUri, $clientId, $clientSecret){
        $path = '/oauth2/token';
        $url = $this->domainUrl . '/v2' . $path;

        $data = [
            'code' => $code,
            'grant_type' => 'authorization_code',
            'redirect_uri' => $redirectUri,
            'client_secret' => $clientSecret,
            'client_id' => $clientId
        ];

        return $this->sendRequest('POST', $url, $data);
    }

    /**
     * NOTE: As of 2023.06.16 Dexcom's /oauth2 endpoints require /v2
     * 
	 * @return Response
	 */
    public function exchangeRefreshToken($token, $redirectUri, $clientId, $clientSecret){
        $path = '/oauth2/token';
        $url = $this->domainUrl . '/v2' . $path;

        $data = [
            'refresh_token' => $token,
            'grant_type' => 'refresh_token',
            'redirect_uri' => $redirectUri,
            'client_id' => $clientId,
            'client_secret' => $clientSecret
        ];

        return $this->sendRequest('POST', $url, $data);
    }

    /**
     * @return null
     */
    public function setAccessToken($accessToken){
        $this->accessToken = $accessToken;

        return null;
    }

    /**
     * @return null
     */
    public function setIsOus($isOus){
        $this->isOus = $isOus;

        return null;
    }

    /**
	 * @return Response
	 */
    public function get($path, array $params)
    {
        $url = $this->baseUrl . $path;
        return $this->sendRequest('GET', $url, $params);
    }

    /**
	 * @return Response
	 */
    public function post($path, array $body)
    {
        $url = $this->baseUrl . $path;
        return $this->sendRequest('POST', $url, $body);
    }

    /**
	 * @return Response
	 */
    public function put($path, array $body)
    {
        $url = $this->baseUrl . $path;
        return $this->sendRequest('PUT', $url, $body);
    }

    /**
	 * @return Response
	 */
    public function delete($path)
    {
        $url = $this->baseUrl . $path;
        return $this->sendRequest('DELETE', $url);
    }

    /**
     * Reliable test/example of aruguments and endpoint.
     * Jumpstart the use of the API from here.
     * 
	 * @return Response
	 */
    public function testEndpoint()
    {
        $uri = '/users/self/calibrations';

        $data = [
            'startDate' => date('Y-m-d\TH:i:s', strtotime('-29 day')),
            'endDate' => date('Y-m-d\TH:i:s')
        ];

        $response = $this->get($uri, $data);
        
        return $response->getStatus();
    }

    /**
     * @return Response
     */
    private function sendRequest($method, $url, array $data = null)
    {
        $requestOptions = [];
        $headers = [];

        // As of 2022.09.22, Dexcom returns an HTTP response that
        //   throws the error "Unrecognized content encoding type".
        // Setting [decode_content => false] fixes this. 
        // Solution found here: https://github.com/guzzle/guzzle/issues/2146 
        $requestOptions[RequestOptions::DECODE_CONTENT] = false;

        // set headers
        if($this->accessToken){
            $headers['Authorization'] = 'Bearer ' . $this->accessToken;
        }

        if ($method === 'POST' && null !== $data) {
            $headers['content-type'] = 'application/x-www-form-urlencoded';
        }else if ($method === 'PUT' && null !== $data) {
            $headers['content-type'] = 'application/json';
        }

        if(count($headers) > 0){
            $requestOptions[RequestOptions::HEADERS] = $headers;
        }

        // set data
        if ($method === 'POST' && null !== $data) {
            $requestOptions[RequestOptions::FORM_PARAMS] = $data;
        }else if($method === 'GET' && null !== $data){
            $requestOptions[RequestOptions::QUERY] = $data;
        } else if($method === 'PUT' && null !== $data) {
            $requestOptions[RequestOptions::JSON] = $data;
        }

        // echo 'Guzzle request options: <br><br>';
        // echo json_encode($requestOptions);
        // echo '<br><br>';

        // send request
        try {
            $response = $this->client->request($method, $url, $requestOptions);

            $data = (array) json_decode($response->getBody(), true);

            return new Response(true, $data);
        }catch (\Exception $e) {
            $errors['errors'] = [$e->getMessage()];

            return new Response(false, [], $errors);
        }
    }
}
