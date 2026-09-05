<?php

namespace Native\Api\Client\Support\Eloquent;

use Illuminate\Database\Connectors\ConnectorInterface;
use Native\Api\Client\Support\Contracts\Client;

class ApiConnector implements ConnectorInterface
{
    /**
     * @var Client
     */
    protected $client;

    public function __construct(Client $client)
    {
        $this->client = $client;
    }

    /**
     * Establish the API connection.
     *
     * @return ApiConnection
     */
    public function connect(array $config)
    {
        $connection = $this->createConnection($config);

        return $connection;
    }

    /**
     * Create a new API connection.
     *
     * @return ApiConnection
     */
    public function createConnection(array $config)
    {
        return new ApiConnection($this->client, $config);
    }
}
