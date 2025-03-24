<?php

namespace Craymend\Dexcom;

use GuzzleHttp\Client;
use GuzzleHttp\RequestOptions;

/**
 * 2025.03.24
 * 
 * Due to Guzzle's DNS resolution intermittently failing when querying Dexcom's domain, this class includes a 
 *   custom DNS resolver.
 *   The custom resolver uses dns_get_record() and substitutes a random value
 *   from the list of returned IP addresses if the resolution is successful.
 * 
 * The root cause of the DNS failure is uncertain. After much testing we found that netstat, cURL, and
 *   dns_get_record() have a 100% DNS resolution success rate, but for some reason the Guzzle 
 *   DNS resolution still fails between 5% and 30% of the time.
 *   Based on these symptoms an LLM had this explanation:
 * 
 * "Key Findings
 *   1. DNS Resolution Success: PHP's dns_get_record() is successfully resolving the hostname to Cloudflare IPs 100% of the time
 *   2. Guzzle Disconnect: Despite successful DNS resolution, Guzzle/cURL still fails to resolve the host in 1 out of 20 cases
 *   3. CNAME Chain: The hostname resolves to "api.dexcom.com.cdn.cloudflare.net" - indicating there's a CNAME record in the DNS chain
 *   4. DNS Alternating IPs: The domain resolves to two IP addresses that alternate in position (104.18.39.70 and 172.64.148.186)
 * 
 * Root Cause Identified
 *   The issue appears to be a timing or handoff problem between PHP's DNS 
 *   resolution and cURL's DNS handling. Even though PHP resolves the DNS correctly, 
 *   there's occasionally a failure when *that information is passed to cURL."
 */

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
     * @var array
     */
    private $resolvedIps = [];

    /**
     * @var int
     */
    private $lastDnsResolveTime = 0;

    /**
     * @var int
     */
    private $dnsCacheTtl = 300; // 5 minutes cache TTL

    /**
     * Defaults to "PRODUCTION" mode
     * 
     * @return null
     */
    public function __construct($accessToken='', $mode = '', $apiVersion='', $isOus=false)
    {
        $this->accessToken = $accessToken;
        
        $this->mode = $mode ? $mode : self::MODE_PRODUCTION;
        $this->apiVersion = $apiVersion ? $apiVersion : self::DEFAULT_API_VERSION;
        $this->isOus = $isOus;

        $this->setBaseUrl($this->mode, $this->apiVersion, $this->isOus); // set baseUrl

        // Update DNS cache and initialize client
        $this->updateDnsCache();
        $this->initializeClient();

        return null;
    }
    
    /**
     * Initialize Guzzle client with custom DNS resolver
     */
    private function initializeClient()
    {
        // Extract domain from baseUrl
        $urlParts = parse_url($this->baseUrl);
        $defaultUrlParts = parse_url(self::BASE_URL_PRODUCTION);
        $domain = $urlParts['host'] ?? $defaultUrlParts['host'];
        
        // Get a resolved IP from cache
        $ip = $this->getResolvedIp();
        
        // Only add CURLOPT_RESOLVE if we have a valid IP
        if ($ip) {
            $clientOptions = [
                'curl' => [
                    CURLOPT_RESOLVE => ["$domain:443:$ip"]
                ]
            ];

            $this->client = new Client($clientOptions);
        }else{
            $this->client = new Client();
        }
    }
    
    /**
     * Update the DNS cache if it's expired
     */
    private function updateDnsCache()
    {
        $currentTime = time();
        
        // If cache is expired or empty
        if ($currentTime - $this->lastDnsResolveTime > $this->dnsCacheTtl || empty($this->resolvedIps)) {
            // Extract domain from baseUrl
            $urlParts = parse_url($this->baseUrl);
            $defaultUrlParts = parse_url(self::BASE_URL_PRODUCTION);
            $domain = $urlParts['host'] ?? $defaultUrlParts['host'];
            
            // Get IPs from DNS
            $dnsRecords = dns_get_record($domain, DNS_A);
            $ips = [];
            
            foreach ($dnsRecords as $record) {
                if (isset($record['ip'])) {
                    $ips[] = $record['ip'];
                }
            }
            
            if (!empty($ips)) {
                $this->resolvedIps = $ips;
                $this->lastDnsResolveTime = $currentTime;
            }
        }
    }
    
    /**
     * Get a random IP from resolved IPs
     */
    private function getResolvedIp()
    {
        $this->updateDnsCache();
        
        if (!empty($this->resolvedIps)) {
            return $this->resolvedIps[array_rand($this->resolvedIps)];
        }
        
        return null;
    }

    /**
     * @return null
     */
    public function setBaseUrl($mode, $apiVersion='', $isOus=false)
    {
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
    public function getBaseUrl()
    {
        return $this->baseUrl;
    }

    /**
     * NOTE: As of 2023.06.16 Dexcom's /oauth2 endpoints require /v2
     * 
	 * @return string
	 */
    public function getAuthUrl($redirectUri, $clientId)
    {
        return "$this->domainUrl/v2/oauth2/login?client_id=$clientId&redirect_uri=$redirectUri&response_type=code&scope=offline_access";
    }

    /**
     * NOTE: As of 2023.06.16 Dexcom's /oauth2 endpoints require /v2
     * 
	 * @return Response
	 */
    public function exchangeCode($code, $redirectUri, $clientId, $clientSecret)
    {
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
    public function exchangeRefreshToken($token, $redirectUri, $clientId, $clientSecret)
    {
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
    public function setAccessToken($accessToken)
    {
        $this->accessToken = $accessToken;

        return null;
    }

    /**
     * @return null
     */
    public function setIsOus($isOus)
    {
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
    private function sendRequest($method, $url, array |null $data = null)
    {
        // Check if we need to update our client with fresh DNS
        if (time() - $this->lastDnsResolveTime > $this->dnsCacheTtl) {
            $this->updateDnsCache();
            $this->initializeClient(); // Reinitialize with fresh DNS
        }
        
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
        } catch (\Exception $e) {
            // On connection errors, try once more with a fresh client and DNS resolution
            if ($e instanceof \GuzzleHttp\Exception\ConnectException) {
                $this->updateDnsCache();
                $this->initializeClient();
                
                try {
                    $response = $this->client->request($method, $url, $requestOptions);
                    $data = (array) json_decode($response->getBody(), true);
                    
                    return new Response(true, $data);
                } catch (\Exception $retryException) {
                    $errors['errors'] = [$retryException->getMessage()];
                    return new Response(false, [], $errors);
                }
            }
            
            $errors['errors'] = [$e->getMessage()];
            return new Response(false, [], $errors);
        }
    }
}
