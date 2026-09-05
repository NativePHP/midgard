<?php

namespace Native\Api\Client\Support\Eloquent;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Str;

class ApiQueryBuilder extends Builder
{
    public $operators = [
        '=', '!=', '<', '>', '<=', '>=',
        'like', 'not like',
        'in', 'not in',
    ];

    public $includes;

    /** @var Model|null */
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
     * @param  string  $suffix
     * @return string
     */
    public function getResourceEndpoint($suffix = null)
    {
        $suffix = $suffix ? Str::start($suffix, '/') : '';

        return $this->getModel()->getEndpointPrefix().$this->getConnection()->getTablePrefix().$this->from.$suffix;
    }

    public function cleanBindings(array $bindings)
    {
        return $bindings;
    }

    public function setIncludes(array $includes): void
    {
        $this->includes = $includes;
    }

    public function setColumns(array $columns): void
    {
        $this->columns = $columns;
    }

    /**
     * @return Model|null
     */
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
