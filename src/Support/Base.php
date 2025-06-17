<?php

namespace Native\Api\Client\Support;

use Native\Api\Client\Support\Contracts\Client;
use Native\Api\Client\Support\Concerns\ConfiguresCurl;
use Exception;
use GuzzleHttp\Client as HttpClient;
use GuzzleHttp\Exception\BadResponseException;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Psr\Http\Message\ResponseInterface;

class Base implements Client
{
    use ConfiguresCurl;

    /**
     * @var HttpClient
     */
    protected $http_client;

    /**
     * @var array
     */
    protected $last_meta = [];

    /**
     * @var array
     */
    protected $last_links = [];

    /**
     * @var array
     */
    protected $last_validation_errors = [];

    /**
     * @var BadResponseException
     */
    protected $last_http_exception;

    /**
     * @var array
     */
    protected $blacklist = [];

    /**
     * Base constructor.
     *
     * @param array $config
     * @throws Exception
     */
    public function __construct(array $config = [])
    {
        $config['base_uri'] ??= config('api.server.uri');
        $config['headers']['Accept'] = 'application/json';
        $config['headers']['Content-Type'] = 'application/json';
        $config['headers']['X-Api-Version'] = config('api.server.version');

        if (! isset($config['headers']['Authorization']) && $token = $this->getTokenFromSession()) {
            $config['headers']['Authorization'] = "Bearer {$token}";
        }

        if (! isset($config['curl'][CURLOPT_RESOLVE]) && ($ip = config('api.server.ip'))) {
            $this->manuallyResolveHost($config['base_uri'], $ip, $config);
        }

        $this->http_client = app()->make(HttpClient::class, ['config' => $config]);
    }

    /**
     * @param \Psr\Http\Message\UriInterface|string $uri
     * @param array $options
     * @return mixed|ResponseInterface
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public function get($uri, $options = [])
    {
        if ($this->isBlacklistedUri($uri)) {
            return;
        }

        return $this->makeRequestAndDecodeResponse('get', $uri, $options);
    }

    /**
     * @param \Psr\Http\Message\UriInterface|string $uri
     * @param array $options
     * @return mixed|ResponseInterface
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public function post($uri, $options = [])
    {
        if ($this->isBlacklistedUri($uri)) {
            return;
        }

        return $this->makeRequestAndDecodeResponse('post', $uri, $options);
    }

    /**
     * @param \Psr\Http\Message\UriInterface|string $uri
     * @param array $options
     * @return mixed|ResponseInterface
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public function patch($uri, $options = [])
    {
        if ($this->isBlacklistedUri($uri)) {
            return;
        }

        return $this->makeRequestAndDecodeResponse('patch', $uri, $options);
    }

    /**
     * @param \Psr\Http\Message\UriInterface|string $uri
     * @param array $options
     * @return mixed|ResponseInterface
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public function delete($uri, $options = [])
    {
        if ($this->isBlacklistedUri($uri)) {
            return;
        }

        return $this->makeRequestAndDecodeResponse('delete', $uri, $options);
    }

    /**
     * Disable PUT.
     *
     * @return void
     * @throws \Exception
     */
    public function put()
    {
        throw new Exception('PUT is not supported by the API. Use PATCH.');
    }

    public function getMeta($key = null)
    {
        if (! is_null($key)) {
            return $this->last_meta[$key] ?? null;
        }

        return $this->last_meta;
    }

    public function getLinks($key = null)
    {
        if (! is_null($key) && isset($this->last_links[$key])) {
            return $this->last_links[$key];
        }
        return $this->last_links;
    }

    public function getValidationErrors()
    {
        return $this->last_validation_errors;
    }

    public function beginTransaction()
    {

    }

    /**
     * @inheritdoc
     * @return mixed json_decode'd response
     * @throws \GuzzleHttp\Exception\GuzzleException
     * @see \Native\Api\Client\Support\Base::makeRequest()
     */
    protected function makeRequestAndDecodeResponse($method, $uri, $options = [])
    {
        $response = $this->makeRequest($method, $uri, $options);

        return $this->decodeResponse($response);
    }

    /**
     * Make a request to the API.
     *
     * @param string $method get|post|patch|delete
     * @param string $uri
     * @param array $options
     * @return ResponseInterface
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    protected function makeRequest($method, $uri, $options = [])
    {
        try {
            $response = $this->http_client->request($method, $uri, $options);
        } catch (BadResponseException $e) {
            $this->last_http_exception = $e;
            $response = $e->getResponse();
        }

        return $response;
    }

    /**
     * Decode the response.
     *
     * @param ResponseInterface $response
     * @return mixed
     * @throws \Exception
     */
    protected function decodeResponse(ResponseInterface $response)
    {
        if ($response->getStatusCode() === 204) {
            return [];
        }

        $body = json_decode((string) $response->getBody(), true);

        if (! $this->responseDeclaredAsJson($response) || ! $this->contentIsValid($body)) {
            throw new Exception('Unexpected or malformed API response');
        }

        $this->detectAndHandleErrorResponse($body, $response);

        return $this->parseResponse($body);
    }

    protected function responseDeclaredAsJson(ResponseInterface $response)
    {
        return ! empty(
            array_filter(
                $response->getHeader('content-type'),
                function ($item) {
                    return Str::endsWith($item, ['/json', '+json']);
                }
            )
        );
    }

    protected function contentIsValid($content)
    {
        return json_last_error() === JSON_ERROR_NONE && (isset($content['data']) || isset($content['errors']));
    }

    /**
     * Handle a JSON response from the API.
     *
     * @param  array  $json
     * @return array
     */
    protected function parseResponse($json)
    {
        $this->captureMeta($json);

        $this->captureLinks($json);

        return $json['data'] ?? [];
    }

    protected function getTokenFromSession()
    {
        $key = config('api.session_keys.oauth_access_token');

        if (session()->has($key)) {
            return session($key);
        }
    }

    /**
     * Primarily a stop-gap solution until we figure out how to stop Laravel Nova from sending action_events.
     *
     * @param  string  $uri
     * @return bool
     */
    protected function isBlacklistedUri($uri)
    {
        return Str::contains($uri, $this->blacklist);
    }

    /**
     * Capture any meta from the response and store internally.
     *
     * @param array $body
     */
    private function captureMeta($body)
    {
        if (isset($body['meta']) && is_array($body['meta'])) {
            $this->last_meta = $body['meta'];
        }
    }

    /**
     * Capture any links from the response and store internally.
     *
     * @param array $body
     */
    private function captureLinks($body)
    {
        if (isset($body['links']) && is_array($body['links'])) {
            $this->last_links = $body['links'];
        }
    }

    /**
     * Determine if there has been an error and if so, provide some useful feedback.
     *
     * @param array $body
     * @param ResponseInterface $response
     * @throws ValidationException
     * @throws \Exception
     */
    private function detectAndHandleErrorResponse(array $body, ResponseInterface $response)
    {
        if ($response->getStatusCode() >= 400) {
            if (isset($body['errors']) && ! empty($body['errors'])) {
                if ($response->getStatusCode() == 422) {
                    $this->last_validation_errors = $body['errors'];

                    Log::error('Validation errors', $this->last_validation_errors);
                    $errors = [];
                    foreach (data_get($body, 'errors') AS $error) {
                        $errors[$error['meta']['field']][] = $error['detail'];
                    }
                    throw ValidationException::withMessages($errors)->errorBag($this->last_http_exception)->status(422);
                }

                if (is_array(data_get($body, 'errors.*.detail'))) {
                    $error_message = implode('<br>', data_get($body, 'errors.*.detail'));
                } else {
                    $error_message = data_get($body, 'errors.*.detail');
                }

                $message = "There was a problem communicating with the API: {$error_message}";

                throw new Exception($message, 0, $this->last_http_exception);
            }

            $message = "There was a problem communicating with the API: {$response->getStatusCode()}";

            throw new Exception($message, 0, $this->last_http_exception);
        }
    }
}
