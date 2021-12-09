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
use Exception;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * ScraperAPI documentation - https://www.scraperapi.com/documentation/
 *
 * @method Client setCountryCode($value) Activate country geotargetting ('us' for example)
 * @method Client setRender($value) Activate javascript rendering (true/false)
 * @method Client setPremium($value) Activate premium residential and mobile IPs (true/false)
 * @method Client setSessionNumber($value) Reuse the same proxy (123 for example)
 * @method Client setKeepHeaders($value) Use your own custom headers (true/false)
 * @method Client setDeviceType($value) Set your requests to use mobile or desktop user agents (desktop/mobile)
 * @method Client setAutoparse($value) Activate auto parsing for select websites (true/false)
 */
class Client
{
    /**
     * ScraperAPI endpoint url
     */
    private const API = 'https://api.scraperapi.com';

    /**
     * ScraperAPI available query params
     */
    private const PARAMS_LIST = [
        'country_code',
        'render',
        'premium',
        'session_number',
        'keep_headers',
        'device_type',
        'autoparse'
    ];

    /**
     * ScraperAPI API key
     * @var string
     */
    private $apiKey;

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
     * ScraperAPI request params
     * @var array
     */
    private $params = [];

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
        $this->printDebugInfo = $printDebugInfo;
        $this->showApiKey = $showApiKey;
        $this->logger = $logger;
        $this->logLevel = $logLevel;

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

        $this->guzzle = new Guzzle(['base_uri' => static::API, 'timeout' => $timeout, 'handler' => $handlerStack]);
    }


    /**
     * Caller for ScraperAPI query params setters
     *
     * @param string $name
     * @param array $arguments
     * @return $this
     * @throws Exception
     */
    public function __call(string $name, array $arguments)
    {

        if (strpos($name, 'set') === 0) {
            $this->setter($name, $arguments);
            return $this;
        }

        throw new Exception("Unknown method '$name'.");
    }

    /**
     * @param string $name
     * @param array $arguments
     */
    private function setter(string $name, array $arguments): void
    {
        $paramName = $this->parseParamName($name);
        if (array_key_exists($paramName, array_flip(static::PARAMS_LIST))) {
            $this->setParam($paramName, $arguments);
        }
    }

    /**
     * @param string $name
     * @param array $arguments
     */
    private function setParam(string $name, array $arguments): void
    {
        $paramValue = $arguments[0] ?? null;
        if ((!is_string($paramValue) && !is_bool($paramValue))) {
            $paramValue = (string)$paramValue;
        }
        if (is_bool($paramValue)) {
            $paramValue = $paramValue ? 'true' : 'false';
        }
        $this->params[$name] = $paramValue;
    }

    /**
     * @param string $name
     * @return string
     */
    private function parseParamName(string $name): string
    {
        $words = preg_split('/(?=[A-Z])/', substr($name, 3));
        if (!$words) {
            return '';
        }

        return (string)array_reduce($words, static function ($carry, $item) {
            if ($item === '') {
                return $carry;
            }
            return ($carry === '' || $carry === null) ? strtolower($item) : "{$carry}_" . strtolower($item);
        });

    }

    /**
     * Set ScraperAPI query params in one step from array
     *
     * @param array $params
     * @return Client
     */
    public function setParams(array $params): Client
    {
        $this->params = $params;
        return $this;
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
     * @return ResponseInterface
     * @throws GuzzleException
     */
    public function get(string $url, ?array $apiParams = null): ResponseInterface
    {
        return $this->sendRequest('GET', $url, $apiParams);
    }

    /**
     * POST request
     *
     * @param string $url
     * @param array|null $apiParams
     * @param mixed|null $body
     * @param array|null $formParams
     * @param array|null $json
     * @return ResponseInterface
     * @throws GuzzleException
     */
    public function post(
        string $url,
        ?array $apiParams = null,
               $body = null,
        ?array $formParams = null,
        ?array $json = null
    ): ResponseInterface
    {
        return $this->sendRequest('POST', $url, $apiParams, $body, $formParams, $json);
    }

    /**
     * PUT request
     *
     * @param string $url
     * @param mixed|null $body
     * @param array|null $apiParams
     * @param array|null $formParams
     * @param array|null $json
     * @return ResponseInterface
     * @throws GuzzleException
     */
    public function put(
        string $url,
        ?array $apiParams = null,
               $body = null,
        ?array $formParams = null,
        ?array $json = null
    ): ResponseInterface
    {
        return $this->sendRequest('PUT', $url, $apiParams, $body, $formParams, $json);
    }


    /**
     * @param string $method
     * @param string $url
     * @param array|null $apiParams
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
            $this->prepareQueryParams($url, $apiParams, $body, $formParams, $json)
        );
        $this->sendRuntime($startTime);

        return $response;
    }

    /**
     * @param string $url
     * @param $body
     * @param array|null $apiParams
     * @param array|null $formParams
     * @param array|null $json
     * @return array[]
     */
    private function prepareQueryParams(string $url, ?array $apiParams, $body, ?array $formParams, ?array $json): array
    {
        $params = $this->params;
        if (is_array($apiParams)) {
            foreach ($apiParams as $param => $value) {
                $params[$param] = $value;
            }
        }
        $params['api_key'] = $this->apiKey;
        $params['url'] = $url;

        $queryParams = ['query' => $params];
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
     * @return PromiseInterface
     */
    public function getPromise(string $url, ?array $apiParams = null): PromiseInterface
    {
        return $this->createPromise('GET', $url, $apiParams);
    }

    /**
     * Create POST promise
     *
     * @param string $url
     * @param array|null $apiParams
     * @param mixed|null $body
     * @param array|null $formParams
     * @param array|null $json
     * @return PromiseInterface
     */
    public function postPromise(
        string $url,
        ?array $apiParams = null,
               $body = null,
        ?array $formParams = null,
        ?array $json = null
    ): PromiseInterface
    {
        return $this->createPromise('POST', $url, $apiParams, $body, $formParams, $json);
    }

    /**
     * Create PUT promise
     *
     * @param string $url
     * @param array|null $apiParams
     * @param mixed|null $body
     * @param array|null $formParams
     * @param array|null $json
     * @return PromiseInterface
     */
    public function putPromise(
        string $url,
        ?array $apiParams = null,
               $body = null,
        ?array $formParams = null,
        ?array $json = null
    ): PromiseInterface
    {
        return $this->createPromise('PUT', $url, $apiParams, $body, $formParams, $json);
    }

    /**
     * @param string $method
     * @param string $url
     * @param array|null $apiParams
     * @param null $body
     * @param array|null $formParams
     * @param array|null $json
     * @return PromiseInterface
     */
    private function createPromise(
        string $method,
        string $url,
        ?array $apiParams,
               $body = null,
        ?array $formParams = null,
        ?array $json = null
    ): PromiseInterface
    {
        return $this->guzzle->requestAsync(
            $method,
            '',
            $this->prepareQueryParams($url, $apiParams, $body, $formParams, $json)
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