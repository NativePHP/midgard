<?php

namespace Native\Api\Client\Support\Eloquent;

use Illuminate\Support\Str;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Eloquent\Model;

class ApiQueryBuilder extends Builder
{
    public $operators = [
        '=', '!=', '<', '>', '<=', '>=',
        'like', 'not like',
        'in', 'not in',
    ];

    public $includes;

    /** @var Model */
    protected $model;

    /**
     * @var array
     */
    protected $query_params = [];

    /**
     * Get the current resource endpoint with optional suffix appended.
     *
     * TODO: Consider moving into Native\Api\Client\Support\Resource ?
     *
     * @param string $suffix
     * @return string
     */
    public function getResourceEndpoint($suffix = null)
    {
        $suffix = $suffix ? Str::start($suffix, '/') : '';

        return $this->getModel()->getEndpointPrefix() . $this->getConnection()->getTablePrefix() . $this->from . $suffix;
    }

    public function cleanBindings(array $bindings)
    {
        return $bindings;
    }

    public function setIncludes(array $includes)
    {
        $this->includes = $includes;
    }

    public function setColumns(array $columns)
    {
        $this->columns = $columns;
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

    public function setQueryParams(array $params)
    {
        $this->query_params = $params;
        return $this;
    }

    public function getQueryParams()
    {
        return $this->query_params;
    }
}
