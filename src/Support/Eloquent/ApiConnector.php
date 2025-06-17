<?php

namespace Native\Api\Client\Support\Eloquent;

use Native\Api\Client\Support\Contracts\Client;
use Illuminate\Database\Connectors\ConnectorInterface;

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
     * @param array $config
     * @return Client
     */
    public function connect(array $config)
    {
        $connection = $this->createConnection($config);

        return $connection;
    }

    /**
     * Create a new API connection.
     *
     * @param  array   $config
     * @return Client
     */
    public function createConnection(array $config)
    {
        return new ApiConnection($this->client, $config);
    }
}
