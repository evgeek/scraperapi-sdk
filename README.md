# PHP SDK for ScraperAPI

Simplify your web scrapping tasks with [ScraperAPI](https://www.scraperapi.com/). This SDK works
with [Guzzle client](https://github.com/guzzle/guzzle), providing asynchronous multithreading requests and
convenient [PSR-7](https://www.php-fig.org/psr/psr-7/) response.

## Installation

Requires PHP version 7.2 or higher

```bash
$ composer require evgeek/scraperapi-sdk
```

## Basic usage

```php
<?php

use Evgeek\Scraperapi\Client;

require '/path/to/you/vendor/autoload.php';

//Create and configure a new SDK client
$sdk = new Client('YOUR_TOKEN');

//Send request
$response = $sdk->get('https://example.com');

//Work with \Psr\Http\Message\ResponseInterface
echo $response->getBody()->getContents();
```

## Setup client

The client is configured through the constructor parameters:

* ```$apiKey``` (required) - your API key from [ScraperAPI dashboard](https://dashboard.scraperapi.com/dashboard).
* ```$defaultApiParams``` - default API parameters for requests
* ```$defaultHeaders``` - default HTTP headers
* ```$timeout``` (default ```60```) - request timeout.
* ```$tries```  (default ```3```) - number of request attempts.
* ```$delayMultiplier```  (default ```1```) - delay multiplier before new request attempt in seconds. Multiplier 3 means
  2nd attempt will be in 3 sec, 3rd attempt in 6 sec, etc.
* ```$printDebugInfo```  (default ```false```) - if true, debug messages will be printed. Useful for debugging async
  requests.
* ```$showApiKey```  (default ```false```) - if false, API key in debug messages will be replaced by 'API_KEY' string.
* ```$logger``` (default ```null```) - [PSR-3](https://www.php-fig.org/psr/psr-3/) compatible logger for debug messages.
  If null, they will be sent to the STDOUT.
* ```$logLevel``` (default ```null```) - log level for [PSR-3](https://www.php-fig.org/psr/psr-3/) logger. If null,
  debug messages will be sent to the ```DEBUG``` level.
* ```$maxExceptionsLength``` (default ```120```) - maximum length of Guzzle exceptions messages.

## Configure default SDK Client parameters

### API parameters

Configuring default API functionality according to [ScraperAPI documentation](https://www.scraperapi.com/documentation/)
. The default settings apply to all requests, unless they are overridden at the request level. You can set the default
options only from constructor (SDK client is immutable), using the second parameter:

```php
$defaultApiParams = [
    'country_code' => 'us', //activate country geotargetting
    'render' => true, //activate javascript rendering
    'premium' => false, //activate premium residential and mobile IPs
    'session_number' => 123, //reuse the same proxy
    'keep_headers' => true, //use your own custom headers
    'device_type' => 'mobile', //set mobile or desktop user agents
    'autoparse' => 'false', //activate auto parsing for select websites
];
$sdk = new Client('YOU_TOKEN', $defaultApiParams);
```

### Headers

You can add default headers with the third parameter of the constructor. Don't forget to enable ```keep_headers``` to
allow your headers to be used!

```php
$defaultHeaders = [
    'Referer' => 'https://example.com/',
    'Accept' => 'application/json',
];
$sdk = new Client('YOU_TOKEN', ['keep_headers' => true], $defaultHeaders);
```

## Requests

SDK supports ```GET```, ```POST``` and ```PUT``` HTTP methods. Standard parameters of each request methods:

1. ```$url``` (required) - url of scrapped resource.
2. ```$apiParams``` (default ```null```) - to set the API settings for the request. They will override the defaults from
   the SDK Client (only those that overlap).
3. ```$headers``` (default ```null```) - to set headers for the request. Just like ```$apiParams```, they will override
   the defaults from the SDK Client (only those that overlap).

### Synchronous

#### GET

Pretty simple:

```php
$response = $sdk->get(
    'https://example.com', 
    ['keep_headers' => true], 
    [
        'Referer' => 'https://example.com/',
        'Accept' => 'application/json',
    ]
);
$content = $response->getBody()->getContents();
```

#### POST/PUT

A bit more complicated:

```php
$response = $sdk->post('https://example.com', $apiParams, $headers, $body, $formParams, $json);
$content = $response->getBody()->getContents();
```

You can use three types of payload:

* ```$body``` for raw ```string```, ```fopen()``` resource or ```Psr\Http\Message\StreamInterface```.
* ```$formParams``` - for form data . Associative ```array``` of form field names to values where each value is a string
  or array of strings.
* ```$json``` - for json. The passed associative ```array``` will be automatically converted to json data.

There are also short forms of methods for different types of payloads:
```php
$response = $sdk->postBody($url, $body, $apiParams, $headers);
$response = $sdk->postForm($url, $formParams, $apiParams, $headers);
$response = $sdk->postJson($url, $json, $apiParams, $headers);
```

By the way, it is convenient to pass the GraphQL payload through ```$json```:

```php
$query = '
    query HeroNameAndFriends($episode: Episode) {
      hero(episode: $episode) {
        name
        friends {
          name
        }
      }
    }
';
$json = ['query' => $query, 'variables' => ['episode' => 'JEDI']];

$response = $sdk->postJson('https://example.com', $json);
```

### Asynchronous

Everything is similar to synchronous, but the work is going not through requests, but through promises:

```php
//Create array with promises
$promises = [
    'first' => $sdk->getPromise('https://example.com', ['country_code' => 'us']),
    'second' => $sdk->postPromiseBody('https://example.com', 'payload'),
];
//Asynchronous fulfillment of promises
$responses = $sdk->resolvePromises($promises);

//Work with array of responses
foreach ($responses as $response) {
    echo $response->getBody()->getContents() . PHP_EOL;
}
```

## Your ScraperAPI Account Information

You can get your ScraperAPI account information using the ``accountInfo()`` method:

```php
$info = $sdk->accountInfo();
var_dump(json_decode($info, true));
```

```
array(5) {
  ["concurrencyLimit"]=>
  int(5)
  ["concurrentRequests"]=>
  int(0)
  ["failedRequestCount"]=>
  int(258)
  ["requestCount"]=>
  int(588)
  ["requestLimit"]=>
  string(4) "1000"
}
```