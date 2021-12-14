<?php

namespace Evgeek\Scraperapi;

use GuzzleHttp\BodySummarizer;
use GuzzleHttp\Client as Guzzle;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Promise\Utils;
use GuzzleHttp\TransferStats;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * ScraperAPI documentation - https://www.scraperapi.com/documentation/
 */
class Client
{
    /**
     * ScraperAPI endpoint url
     */
    private const API = 'https://api.scraperapi.com';
    /**
     * ScraperAPI API key
     * @var string
     */
    private $apiKey;
    /**
     * ScraperAPI default request params
     * @var array
     */
    private $defaultApiParams;
    /**
     * Default headers
     * @var array
     */
    private $defaultHeaders;
    /**
     * If true, debug statement sent to the output. Useful for debugging async requests
     * @var bool
     */
    private $printDebugInfo;
    /**
     * If false, API key in debug statement will be replaced by 'API_KEY' string
     * @var bool
     */
    private $showApiKey;
    /**
     * Guzzle client
     * @var Guzzle
     */
    private $guzzle;
    /**
     * Optional PSR-3 logger for debug
     * @var LoggerInterface|null
     */
    private $logger;
    /**
     * Log level for PSR-3 logger (default DEBUG)
     * @var mixed
     */
    private $logLevel;
    /**
     * Total promises in a bunch (for debug statements)
     * @var int
     */
    private $totalPromises = 0;
    /**
     * Fulfilled promises (for debug statements)
     * @var int
     */
    private $fulfilledPromises = 0;

    /**
     * ScraperAPI documentation - https://www.scraperapi.com/documentation/
     *
     * @param string $apiKey ScraperAPI API key
     * @param array|null $defaultApiParams Default api parameters for requests
     * @param array|null $defaultHeaders Default headers for requests
     * @param int $timeout Request timeout
     * @param int $tries Number of request attempts
     * @param int $delayMultiplier Delay multiplier before new request attempt in seconds
     * @param bool $printDebugInfo True for print debug statement. Useful for debugging async requests
     * @param bool $showApiKey If false, API key in debug statement will be replaced by 'API_KEY' string
     * @param ?LoggerInterface $logger PSR-3 logger for debugging. If null, debug statement will be sent to the output
     * @param mixed|null $logLevel Log level for PSR-3 logger. If null, debug statement will be sent to the debug level
     * @param int $maxExceptionsLength Maximum length of Guzzle exceptions messages
     */
    public function __construct(
        string          $apiKey,
        ?array          $defaultApiParams = [],
        ?array          $defaultHeaders = [],
        int             $timeout = 60,
        int             $tries = 3,
        int             $delayMultiplier = 1,
        bool            $printDebugInfo = false,
        bool            $showApiKey = false,
        LoggerInterface $logger = null,
                        $logLevel = null,
        int             $maxExceptionsLength = 120
    )
    {
        $this->apiKey = $apiKey;
        $this->defaultApiParams = $defaultApiParams ?? [];
        $this->defaultHeaders = $defaultHeaders ?? [];
        $this->printDebugInfo = $printDebugInfo;
        $this->showApiKey = $showApiKey;
        $this->logger = $logger;
        $this->logLevel = $logLevel;

        $this->guzzle = $this->createGuzzleClient($timeout, $tries, $delayMultiplier, $maxExceptionsLength);
    }

    /**
     * @param int $timeout
     * @param int $tries
     * @param int $delayMultiplier
     * @param int $maxExceptionsLength
     * @return Guzzle
     */
    private function createGuzzleClient(int $timeout, int $tries, int $delayMultiplier, int $maxExceptionsLength): Guzzle
    {
        $handlerStack = HandlerStack::create();
        $handlerStack->push(Middleware::retry(
            function (
                $try,
                RequestInterface $request,
                ResponseInterface $response = null,
                Throwable $e = null
            ) use ($tries) {
                $try++;
                if ($response !== null && $response->getStatusCode() === 200) {
                    $promisesProgress = $this->totalPromises === 0 ?
                        '' :
                        ' (' . ++$this->fulfilledPromises . '/' . $this->totalPromises . ')';
                    $this->sendDebugMessage("Attempt $try completed successfully$promisesProgress");
                    return false;
                }
                if ($try === $tries) {
                    $this->sendDebugMessage("Maximum number of attempts ($tries) reached, request failed");
                    return false;
                }

                $responseCode = $response !== null ? $response->getStatusCode() : null;
                $exceptionCode = $e !== null ? $e->getCode() : null;
                $code = $responseCode ?? $exceptionCode;
                $errMessage = $code === null ? '' : " (code $code)";
                $this->sendDebugMessage("Attempt $try failed$errMessage, repeating");

                return true;
            },
            function ($try) use ($delayMultiplier) {
                return $delayMultiplier * $try * 1000;
            }
        ));
        $handlerStack->push(Middleware::httpErrors(new BodySummarizer($maxExceptionsLength)), 'http_errors');

        return new Guzzle(['base_uri' => static::API, 'timeout' => $timeout, 'handler' => $handlerStack]);
    }

    /**
     * Get info about ScraperAPI account
     *
     * @return string
     * @throws GuzzleException
     */
    public function accountInfo(): string
    {
        $startTime = microtime(true);
        $this->sendDebugMessage('Get ScraperAPI account info');
        $response = $this->guzzle->request('GET', 'account', ['query' => ['api_key' => $this->apiKey]]);
        $this->sendRuntime($startTime);

        return $response->getBody()->getContents();
    }

    /**
     * GET request
     *
     * @param string $url
     * @param array|null $apiParams
     * @param array|null $headers
     * @return ResponseInterface
     * @throws GuzzleException
     */
    public function get(string $url, ?array $apiParams = null, ?array $headers = null): ResponseInterface
    {
        return $this->sendRequest('GET', $url, $apiParams, $headers);
    }

    /**
     * POST request
     *
     * @param string $url
     * @param array|null $apiParams
     * @param array|null $headers
     * @param mixed|null $body
     * @param array|null $formParams
     * @param array|null $json
     * @return ResponseInterface
     * @throws GuzzleException
     */
    public function post(
        string $url,
        ?array $apiParams = null,
        ?array $headers = null,
               $body = null,
        ?array $formParams = null,
        ?array $json = null
    ): ResponseInterface
    {
        return $this->sendRequest('POST', $url, $apiParams, $headers, $body, $formParams, $json);
    }

    /**
     * POST request with a BODY of one of three types: raw string, fopen() resource or Psr\Http\Message\StreamInterface.
     *
     * @param string $url
     * @param array|null $apiParams
     * @param array|null $headers
     * @param null $body
     * @return ResponseInterface
     * @throws GuzzleException
     */
    public function postBody(string $url, $body = null, ?array $apiParams = null, ?array $headers = null): ResponseInterface
    {
        return $this->post($url, $apiParams, $headers, $body);
    }

    /**
     * POST request with FORM payload (Content-Type: application/x-www-form-urlencoded)
     *
     * @param string $url
     * @param array|null $apiParams
     * @param array|null $headers
     * @param array|null $formParams
     * @return ResponseInterface
     * @throws GuzzleException
     */
    public function postForm(string $url, ?array $formParams = null, ?array $apiParams = null, ?array $headers = null): ResponseInterface
    {
        return $this->post($url, $apiParams, $headers, null, $formParams);
    }

    /**
     * POST request with JSON payload (Content-Type: application/json)
     *
     * @param string $url
     * @param array|null $apiParams
     * @param array|null $headers
     * @param array|null $json
     * @return ResponseInterface
     * @throws GuzzleException
     */
    public function postJson(string $url, ?array $json = null, ?array $apiParams = null, ?array $headers = null): ResponseInterface
    {
        return $this->post($url, $apiParams, $headers, null, null, $json);
    }

    /**
     * PUT request
     *
     * @param string $url
     * @param array|null $apiParams
     * @param array|null $headers
     * @param mixed|null $body
     * @param array|null $formParams
     * @param array|null $json
     * @return ResponseInterface
     * @throws GuzzleException
     */
    public function put(
        string $url,
        ?array $apiParams = null,
        ?array $headers = null,
               $body = null,
        ?array $formParams = null,
        ?array $json = null
    ): ResponseInterface
    {
        return $this->sendRequest('PUT', $url, $apiParams, $headers, $body, $formParams, $json);
    }


    /**
     * PUT request with a BODY of one of three types: raw string, fopen() resource or Psr\Http\Message\StreamInterface.
     *
     * @param string $url
     * @param array|null $apiParams
     * @param array|null $headers
     * @param null $body
     * @return ResponseInterface
     * @throws GuzzleException
     */
    public function putBody(string $url, $body = null, ?array $apiParams = null, ?array $headers = null): ResponseInterface
    {
        return $this->put($url, $apiParams, $headers, $body);
    }

    /**
     * PUT request with FORM payload (Content-Type: application/x-www-form-urlencoded)
     *
     * @param string $url
     * @param array|null $apiParams
     * @param array|null $headers
     * @param array|null $formParams
     * @return ResponseInterface
     * @throws GuzzleException
     */
    public function putForm(string $url, ?array $formParams = null, ?array $apiParams = null, ?array $headers = null): ResponseInterface
    {
        return $this->put($url, $apiParams, $headers, null, $formParams);
    }

    /**
     * PUT request with JSON payload (Content-Type: application/json)
     *
     * @param string $url
     * @param array|null $apiParams
     * @param array|null $headers
     * @param array|null $json
     * @return ResponseInterface
     * @throws GuzzleException
     */
    public function putJson(string $url, ?array $json = null, ?array $apiParams = null, ?array $headers = null): ResponseInterface
    {
        return $this->put($url, $apiParams, $headers, null, null, $json);
    }


    /**
     * @param string $method
     * @param string $url
     * @param array|null $apiParams
     * @param array|null $headers
     * @param null $body
     * @param array|null $formParams
     * @param array|null $json
     * @return ResponseInterface
     * @throws GuzzleException
     */
    private function sendRequest(
        string $method,
        string $url,
        ?array $apiParams,
        ?array $headers,
               $body = null,
        ?array $formParams = null,
        ?array $json = null
    ): ResponseInterface
    {
        $startTime = microtime(true);
        $this->totalPromises = 0;
        $this->fulfilledPromises = 0;
        $this->sendDebugMessage("$method request started");
        $response = $this->guzzle->request(
            $method,
            $url,
            $this->prepareQueryParams($url, $apiParams, $headers, $body, $formParams, $json)
        );
        $this->sendRuntime($startTime);

        return $response;
    }

    /**
     * @param string $url
     * @param array|null $apiParams
     * @param array|null $headers
     * @param $body
     * @param array|null $formParams
     * @param array|null $json
     * @return array[]
     */
    private function prepareQueryParams(
        string $url,
        ?array $apiParams,
        ?array $headers,
               $body,
        ?array $formParams,
        ?array $json
    ): array
    {
        $params = $this->defaultApiParams;
        if (is_array($apiParams)) {
            foreach ($apiParams as $param => $value) {
                $params[$param] = $value;
            }
        }

        $resultHeaders = $this->defaultHeaders;
        if (is_array($headers)) {
            foreach ($headers as $param => $value) {
                $resultHeaders[$param] = $value;
            }
        }

        $params['api_key'] = $this->apiKey;
        $params['url'] = $url;

        $queryParams = [
            'query' => $params,
            'headers' => $resultHeaders,
        ];
        $queryParams['on_stats'] = function (TransferStats $stats) {
            $time = $stats->getTransferTime() . 's';
            $method = $stats->getRequest()->getMethod();
            $uri = $this->showApiKey ?
                $stats->getEffectiveUri() :
                str_replace($this->apiKey, 'API_KEY', $stats->getEffectiveUri());
            $this->sendDebugMessage("$time ($method $uri)");
        };
        $body !== null && $queryParams['body'] = $body;
        $formParams !== null && $queryParams['form_params'] = $formParams;
        $json !== null && $queryParams['json'] = $json;

        return $queryParams;
    }


    /**
     * Create GET promise
     *
     * @param string $url
     * @param array|null $apiParams
     * @param array|null $headers
     * @return PromiseInterface
     */
    public function getPromise(string $url, ?array $apiParams = null, ?array $headers = null): PromiseInterface
    {
        return $this->createPromise('GET', $url, $apiParams, $headers);
    }

    /**
     * Create POST promise
     *
     * @param string $url
     * @param array|null $apiParams
     * @param array|null $headers
     * @param mixed|null $body
     * @param array|null $formParams
     * @param array|null $json
     * @return PromiseInterface
     */
    public function postPromise(
        string $url,
        ?array $apiParams = null,
        ?array $headers = null,
               $body = null,
        ?array $formParams = null,
        ?array $json = null
    ): PromiseInterface
    {
        return $this->createPromise('POST', $url, $apiParams, $headers, $body, $formParams, $json);
    }

    /**
     * Create POST promise with a BODY of one of three types: raw string, fopen() resource or Psr\Http\Message\StreamInterface.
     *
     * @param string $url
     * @param null $body
     * @param array|null $apiParams
     * @param array|null $headers
     * @return PromiseInterface
     */
    public function postPromiseBody(string $url, $body = null, ?array $apiParams = null, ?array $headers = null): PromiseInterface
    {
        return $this->postPromise($url, $apiParams, $headers, $body);
    }

    /**
     * Create POST promise with FORM payload (Content-Type: application/x-www-form-urlencoded)
     *
     * @param string $url
     * @param array|null $formParams
     * @param array|null $apiParams
     * @param array|null $headers
     * @return PromiseInterface
     */
    public function postPromiseForm(string $url, ?array $formParams = null, ?array $apiParams = null, ?array $headers = null): PromiseInterface
    {
        return $this->postPromise($url, $apiParams, $headers, null, $formParams);
    }

    /**
     * Create POST promise with JSON payload (Content-Type: application/json)
     *
     * @param string $url
     * @param array|null $json
     * @param array|null $apiParams
     * @param array|null $headers
     * @return PromiseInterface
     */
    public function postPromiseJson(string $url, ?array $json = null, ?array $apiParams = null, ?array $headers = null): PromiseInterface
    {
        return $this->postPromise($url, $apiParams, $headers, null, null, $json);
    }

    /**
     * Create PUT promise
     *
     * @param string $url
     * @param array|null $apiParams
     * @param array|null $headers
     * @param mixed|null $body
     * @param array|null $formParams
     * @param array|null $json
     * @return PromiseInterface
     */
    public function putPromise(
        string $url,
        ?array $apiParams = null,
        ?array $headers = null,
               $body = null,
        ?array $formParams = null,
        ?array $json = null
    ): PromiseInterface
    {
        return $this->createPromise('PUT', $url, $apiParams, $headers, $body, $formParams, $json);
    }

    /**
     * Create PUT promise with a BODY of one of three types: raw string, fopen() resource or Psr\Http\Message\StreamInterface.
     *
     * @param string $url
     * @param null $body
     * @param array|null $apiParams
     * @param array|null $headers
     * @return PromiseInterface
     */
    public function putPromiseBody(string $url, $body = null, ?array $apiParams = null, ?array $headers = null): PromiseInterface
    {
        return $this->putPromise($url, $apiParams, $headers, $body);
    }

    /**
     * Create PUT promise with FORM payload (Content-Type: application/x-www-form-urlencoded)
     *
     * @param string $url
     * @param array|null $formParams
     * @param array|null $apiParams
     * @param array|null $headers
     * @return PromiseInterface
     */
    public function putPromiseForm(string $url, ?array $formParams = null, ?array $apiParams = null, ?array $headers = null): PromiseInterface
    {
        return $this->putPromise($url, $apiParams, $headers, null, $formParams);
    }

    /**
     * Create PUT promise with JSON payload (Content-Type: application/json)
     *
     * @param string $url
     * @param array|null $json
     * @param array|null $apiParams
     * @param array|null $headers
     * @return PromiseInterface
     */
    public function putPromiseJson(string $url, ?array $json = null, ?array $apiParams = null, ?array $headers = null): PromiseInterface
    {
        return $this->putPromise($url, $apiParams, $headers, null, null, $json);
    }

    /**
     * @param string $method
     * @param string $url
     * @param array|null $apiParams
     * @param array|null $headers
     * @param null $body
     * @param array|null $formParams
     * @param array|null $json
     * @return PromiseInterface
     */
    private function createPromise(
        string $method,
        string $url,
        ?array $apiParams,
        ?array $headers,
               $body = null,
        ?array $formParams = null,
        ?array $json = null
    ): PromiseInterface
    {
        return $this->guzzle->requestAsync(
            $method,
            '',
            $this->prepareQueryParams($url, $apiParams, $headers, $body, $formParams, $json)
        );
    }

    /**
     * Execute array of promises. All promises will be fulfilled, or an exception will be thrown.
     *
     * @param PromiseInterface[] $promises
     * @return ResponseInterface[]
     * @throws Throwable
     */
    public function resolvePromises(array $promises): array
    {
        $startTime = microtime(true);
        $this->totalPromises = count($promises);
        $this->fulfilledPromises = 0;
        $this->sendDebugMessage('Promises resolving started (' . $this->totalPromises . 'pcs)');
        $responses = Utils::unwrap($promises);
        $this->sendRuntime($startTime);

        return $responses;
    }


    /**
     * @param string $message
     */
    private function sendDebugMessage(string $message): void
    {
        if ($this->printDebugInfo) {
            if ($this->logger === null) {
                echo $message . PHP_EOL;
            } elseif ($this->logLevel === null) {
                $this->logger->debug($message);
            } else {
                $this->logger->log($this->logLevel, $message);
            }

        }
    }

    /**
     * @param float $startTime
     */
    private function sendRuntime(float $startTime): void
    {
        $this->sendDebugMessage('Completed in ' . (microtime(true) - $startTime) . ' seconds');
    }

}