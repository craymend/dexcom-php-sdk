# Dexcom API SDK

This package is a PHP wrapper for the [Dexcom API v2](https://developer.dexcom.com/overview).

### Requirements

This project works with PHP 7.2+.

You also need a redirect_uri to use the Dexcom OAuth.

## Installation

Install with composer:

```
composer require craymend/dexcom-php-sdk
```

## Examples

Create an instance of Request. Be sure to set sandbox mode when testing.

```php
<?php

require __DIR__ . '/vendor/autoload.php';

use Craymend\Dexcom\Request;

$sandboxMode = Request::MODE_SANDBOX;
// $mode = Request::MODE_PRODUCTION;
$redirectUri = 'https://<YOUR DOMAIN>/path/to/receive/oauth/code';
$clientId = 'your-client-id';
$clientSecret = 'your-client-secret';

// "mode" controls the baseUrl
echo 'baseUrl: ' . $request->getBaseUrl();
$request->setMode($sandboxMode);
echo 'baseUrl: ' . $request->getBaseUrl();

```

Next complete the OAuth process to get your access token:

```php
<?php
// Complete OAuth process
$request = new Request();
$request->setMode($sandboxMode);

// get auth url for user to use OAuth
echo 'OAuth url: ';
echo $request->getAuthUrl($redirectUri, $clientId);

// Get "code" from above auth url.
// Dexcom will return the code to your redirectUri
//   after the user logs in and agrees to give access.
$code = 'code-returned-to-redirect-uri';

// exchange code for refresh and access tokens
$response = $request->exchangeCode($code, $redirectUri, $clientId, $clientSecret);

if (!$response->getStatus()) {
    $errors = $response->getErrors();
    echo json_encode($errors);
} else{
    $data = $response->getData();
    $accessToken = $data['access_token'];
    $refreshToken = $data['refresh_token'];
}

echo "access_token: $accessToken<br>";
echo "refresh_token: $refreshToken<br>";

```

You can also echange the refresh token for a new refresh token and access token:


```php
<?php
// Exchange refresh token
$request = new Request();
$request->setMode($sandboxMode);

$response = $request->exchangeRefreshToken($refreshToken, $redirectUri, $clientId, $clientSecret);

if (!$response->getStatus()) {
    $errors = $response->getErrors();
    echo json_encode($errors);
} else{
    $data = $response->getData();
    $accessToken = $data['access_token'];
    $refreshToken = $data['refresh_token'];
}

echo "access_token: $accessToken<br>";
echo "refresh_token: $refreshToken<br>";

```

You can now use the Dexcom API. For example, [retrieve device calibration](https://developer.dexcom.com/get-calibrations):

```php
<?php
$request = new Request($accessToken, $sandboxMode);

$uri = '/users/self/calibrations';
$data = [
    'startDate' => date('Y-m-d\TH:i:s', strtotime('-29 day')),
    'endDate' => date('Y-m-d\TH:i:s')
];
$response = $request->get($uri, $data);
$data = $response->getData();

echo 'data: ' . json_encode($data);
```

## License

MIT
