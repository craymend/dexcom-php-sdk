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
    const BASE_URL_PRODUCTION = 'https://api.dexcom.com';
    
    /**
     * @var string - "SANDBOX" | "PRODUCTION"
     */
    private $mode;

    /**
     * @var string
     */
    private $baseUrl;

    /**
     * @var string
     */
    private $accessToken;

    /**
     * Defualts to "PRODUCTION" mode
     */
    public function __construct($accessToken='', $mode = '', $apiVersion='')
    {
        $this->accessToken = $accessToken;
        
        $this->mode = $mode ? $mode : self::MODE_PRODUCTION;
        $this->apiVersion = $apiVersion ? $apiVersion : self::DEFAULT_API_VERSION;

        $this->setMode($this->mode, $this->apiVersion); // set baseUrl
    }

    /**
     * @return null
     */
    public function setMode($mode, $apiVersion){
        if($mode == self::MODE_SANDBOX){
            $this->baseUrl = self::BASE_URL_SANDBOX . '/' . $apiVersion;
        }else{
            $this->baseUrl = self::BASE_URL_PRODUCTION . '/' . $apiVersion;
        }
    }

    /**
     * @return string
     */
    public function getBaseUrl(){
        return $this->baseUrl;
    }

    /**
	 * @return string
	 */
    public function getAuthUrl($redirectUri, $clientId){
        return "$this->baseUrl/oauth2/login?client_id=$clientId&redirect_uri=$redirectUri&response_type=code&scope=offline_access";
    }

    /**
	 * @return Response
	 */
    public function exchangeCode($code, $redirectUri, $clientId, $clientSecret){
        $url = "$this->baseUrl/oauth2/token";

        $data = [
            'code' => $code,
            'grant_type' => 'authorization_code',
            'redirect_uri' => $redirectUri,
            'client_secret' => $clientSecret,
            'client_id' => $clientId
        ];

        $response = $this->post('/oauth2/token', $data);

        return $response;
    }

    /**
	 * @return Response
	 */
    public function exchangeRefreshToken($token, $redirectUri, $clientId, $clientSecret){
        $url = "$this->baseUrl/oauth2/token";

        $data = [
            'refresh_token' => $token,
            'grant_type' => 'refresh_token',
            'redirect_uri' => $redirectUri,
            'client_id' => $clientId,
            'client_secret' => $clientSecret
        ];

        $response = $this->post('/oauth2/token', $data);

        return $response;
    }

    /**
	 * @return Response
	 */
    public function get($path, array $params)
    {
        return $this->sendRequest('GET', $path, $params);
    }

    /**
	 * @return Response
	 */
    public function post($path, array $body)
    {
        return $this->sendRequest('POST', $path, $body);
    }

    /**
	 * @return Response
	 */
    public function put($path, array $body)
    {
        return $this->sendRequest('PUT', $path, $body);
    }

    /**
	 * @return Response
	 */
    public function delete($path)
    {
        return $this->sendRequest('DELETE', $path);
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
    private function sendRequest($method, $path, array $data = null)
    {
        $uri = $this->baseUrl . $path;

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
        }
        if(count($headers) > 0){
            $requestOptions[RequestOptions::HEADERS] = $headers;
        }

        // set data
        if ($method === 'POST' && null !== $data) {
            $requestOptions[RequestOptions::FORM_PARAMS] = $data;
        }else if($method === 'GET' && null !== $data){
            $requestOptions[RequestOptions::QUERY] = $data;
        }

        // echo 'Guzzle request options: <br><br>';
        // echo json_encode($requestOptions);
        // echo '<br><br>';

        // send request
        try {
            $client = new Client();

            $response = $client->request($method, $uri, $requestOptions);

            $data = (array) json_decode($response->getBody(), true);

            return new Response(true, $data);
        }catch (\Exception $e) {
            $errors['errors'] = [$e->getMessage()];

            return new Response(false, [], $errors);
        }
    }
}
