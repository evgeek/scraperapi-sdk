# PHP SDK for ScraperAPI
Simplify your web scrapping tasks with [ScraperAPI](https://www.scraperapi.com/). This SDK works with 
[Guzzle client](https://github.com/guzzle/guzzle), providing asynchronous multithreading requests and convenient 
[PSR-7](https://www.php-fig.org/psr/psr-7/) response. 
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
$sdk = new Client('YOU_TOKEN');

//Setup ScraperAPI parameters according to the documentation
$sdk
    ->setCountryCode('us')
    ->setDeviceType('desktop');

//Send request
$response = $sdk->get('https://example.com');

//Work with \Psr\Http\Message\ResponseInterface
echo $response->getBody()->getContents();
```
## Setup client
The client is configured through the constructor parameters:
* ```$apiKey``` (required) - your API key from [ScraperAPI dashboard](https://dashboard.scraperapi.com/dashboard).
* ```$timeout``` (default ```60```) - request timeout.
* ```$tries```  (default ```3```) - number of request attempts.
* ```$delayMultiplier```  (default ```1```) - delay multiplier before new request attempt in seconds. Multiplier 3 means 
2nd attempt will be in 3 sec, 3rd attempt in 6 sec, etc.
* ```$printDebugInfo```  (default ```false```) - if true, debug messages will be printed. Useful for debugging async 
requests.
* ```$showApiKey```  (default ```false```) - if false, API key in debug messages will be replaced by 'API_KEY' string.
* ```$logger``` (default ```null```) - [PSR-3](https://www.php-fig.org/psr/psr-3/) compatible logger for debug messages. 
If null, they will be sent to the STDOUT.
* ```$logLevel``` (default ```null```) - log level for [PSR-3](https://www.php-fig.org/psr/psr-3/) logger. 
If null, debug messages will be sent to the ```DEBUG``` level.
* ```$maxExceptionsLength``` (default ```120```) - maximum length of Guzzle exceptions messages.
## Configure default API parameters
Configuring default API functionality according to [ScraperAPI documentation](https://www.scraperapi.com/documentation/).
The default settings apply to all requests, unless they are overridden at the request level. 
All parameters are set using fluent setters:
```php
$sdk
    ->setCountryCode('us') //activate country geotargetting
    ->setRender(true) //activate javascript rendering
    ->setPremium(false) //activate premium residential and mobile IPs
    ->setSessionNumber(123) //reuse the same proxy
    ->setKeepHeaders(true) //use your own custom headers
    ->setDeviceType('mobile') //set mobile or desktop user agents
    ->setAutoparse(false); //activate auto parsing for select websites
```
Or you can set all parameters in single ```setParams()``` method using an array. This method erases all previously 
set parameters.
```php
$sdk->setParams([
    'country_code' => 'us',
    'render' => true,
    'premium' => false,
    'session_number' => 123,
    'keep_headers' => true,
    'device_type' => 'mobile',
    'autoparse' => 'false',
]);
```
## Requests
SDK supports ```GET```, ```POST``` and ```PUT``` HTTP methods. Standard parameters of each request methods:
1. ```$url``` (required) - url of scrapped resource.
2. ```$apiParams``` (default ```null```) - to set the API settings for the request. They will override the defaults set 
in the SDK Client object (only those that overlap).
### Synchronous
#### GET
Pretty simple:
```php
$response = $sdk->get('https://example.com', ['country_code' => 'us']);
$content = $response->getBody()->getContents();
```
#### POST/PUT
A bit more complicated:
```php
$response = $sdk->post('https://example.com', $apiParams, $body, $formParams, $json);
$content = $response->getBody()->getContents();
```
You can use three types of payload:
* ```$body``` for raw ```string```, ```fopen()``` resource or ```Psr\Http\Message\StreamInterface```.
* ```$formParams``` - for form data . Associative ```array``` of form field names to values where each value is a 
string or array of strings.
* ```$json``` - for json.  The passed associative ```array``` will be automatically converted to json data.

By the way, it is convenient to pass the GraphQL payload through ```$formParams```:
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
$variables = ['episode' => 'JEDI'];
$formParams = ['query' => $query, 'variables' => $variables];

$response = $sdk->post('https://example.com', null, null, $formParams);
```
### Asynchronous
Everything is similar to synchronous, but the work is going not through requests, but through promises:
```php
//Create array with promises
$promises = [
    'first' => $sdk->getPromise('https://example.com', ['country_code' => 'us']),
    'second' => $sdk->postPromise('https://example.com', null, 'payload'),
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