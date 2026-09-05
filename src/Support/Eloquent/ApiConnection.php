<?php

namespace Native\Api\Client\Support\Eloquent;

use Generator;
use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Model;
use Native\Api\Client\Support\Contracts\Client;

class ApiConnection extends Connection implements ApiConnectionInterface
{
    /**
     * @var Client
     */
    protected $client;

    /**
     * @var Model|null
     */
    protected $model;

    public function __construct(Client $client, array $config = [])
    {
        $this->client = $client;

        parent::__construct(
            $client,
            $config['database'] ?? '',
            $config['prefix'] ?? '',
            $config
        );
    }

    /**
     * Get the default query grammar instance.
     *
     * @return ApiGrammar
     */
    protected function getDefaultQueryGrammar()
    {
        return new ApiGrammar($this);
    }

    /**
     * Get the default post processor instance.
     *
     * @return ApiProcessor
     */
    protected function getDefaultPostProcessor()
    {
        return new ApiProcessor;
    }

    /**
     * The API has no notion of a driver, but plenty of framework internals ask for one.
     *
     * @return string
     */
    public function getDriverName()
    {
        return 'api';
    }

    /**
     * @return string
     */
    public function getDriverTitle()
    {
        return 'API';
    }

    public function lastInsertId()
    {
        //
    }

    public function beginTransaction()
    {
        //
    }

    public function commit()
    {
        //
    }

    public function rollBack($toLevel = null)
    {
        //
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
     * @return ApiQueryBuilder
     */
    public function query()
    {
        $query = new ApiQueryBuilder(
            $this, $this->getQueryGrammar(), $this->getPostProcessor()
        );

        return $this->model ? $query->setModel($this->model) : $query;
    }

    /**
     * @param  string  $query
     * @param  array  $bindings
     * @param  bool  $useReadPdo
     * @return array
     */
    public function select($query, $bindings = [], $useReadPdo = true, array $fetchUsing = [])
    {
        return $this->client->get($query);
    }

    /**
     * @param  string  $query
     * @param  array  $bindings
     * @return mixed
     */
    public function insert($query, $bindings = [])
    {
        return $this->client->post($query, ['json' => $bindings]);
    }

    /**
     * @param  string  $query
     * @param  array  $bindings
     * @return mixed
     */
    public function update($query, $bindings = [])
    {
        return $this->client->patch($query, ['json' => $bindings]);
    }

    /**
     * @param  string  $query
     * @param  array  $bindings
     * @return mixed
     */
    public function delete($query, $bindings = [])
    {
        return $this->client->delete($query);
    }

    public function getClient()
    {
        return $this->client;
    }

    /**
     * Run a select statement against the API and return a generator.
     *
     * @param  string  $query
     * @param  array  $bindings
     * @param  bool  $useReadPdo
     * @return Generator
     */
    public function cursor($query, $bindings = [], $useReadPdo = true, array $fetchUsing = [])
    {
        $records = $this->select($query, $bindings, $useReadPdo, $fetchUsing) ?? [];

        foreach ($records as $record) {
            yield $record;
        }
    }
}
