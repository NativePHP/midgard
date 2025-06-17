<?php

namespace Native\Api\Client\Support\Eloquent;

use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Model;
use Native\Api\Client\Support\Contracts\Client;

class ApiConnection extends Connection implements ApiConnectionInterface
{
    protected $client;

    protected $model;

    public function __construct(Client $client, array $config = [])
    {
        $this->client = $this->pdo = $client;
        $this->config = $config;
        $this->postProcessor = new ApiProcessor;
        $this->queryGrammar = new ApiGrammar;
    }

    public function lastInsertId()
    {

    }

    public function beginTransaction()
    {

    }

    public function rollBack($toLevel = null)
    {

    }

    public function getModel()
    {
        return $this->model;
    }

    public function setModel(Model $model)
    {
        $this->model = $model;

        return $this;
    }

    /**
     * Get a new query builder instance.
     *
     * @return \Illuminate\Database\Query\Builder
     */
    public function query()
    {
        return (new ApiQueryBuilder(
            $this, $this->getQueryGrammar(), $this->getPostProcessor()
        ))->setModel($this->model);
    }

    public function select($query, $bindings = [], $useReadPdo = true)
    {
        return $this->client->get($query);
    }

    public function insert($query, $bindings = [])
    {
        $options = [
            'json' => $bindings,
        ];

        return $this->client->post($query, $options);
    }

    public function update($query, $bindings = [], $useReadPdo = true)
    {
        $options = [
            'json' => $bindings,
        ];

        return $this->client->patch($query, $options);
    }

    public function delete($query, $bindings = [])
    {
        return $this->client->delete($query);
    }

    public function getClient()
    {
        return $this->client;
    }

    /**
     * Run a select statement against the database and returns a generator.
     *
     * @param  string  $query
     * @param  array  $bindings
     * @param  bool  $useReadPdo
     * @return \Generator
     */
    public function cursor($query, $bindings = [], $useReadPdo = true)
    {
        $records = $this->select($query, $bindings, $useReadPdo) ?? [];

        foreach ($records as $record) {
            yield $record;
        }
    }
}
