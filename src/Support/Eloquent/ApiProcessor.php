<?php

namespace Native\Api\Client\Support\Eloquent;

use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\Processors\Processor;

class ApiProcessor extends Processor
{
    protected ?array $response = null;

    /**
     * @param  mixed  $results
     * @return mixed
     */
    public function processSelect(Builder $query, $results)
    {
        if (! empty($query->aggregate)) {
            $results = [$results];
        }

        return $results;
    }

    /**
     * @param  string  $sql
     * @param  array  $values
     * @param  string|null  $sequence
     * @return int|string
     */
    public function processInsertGetId(Builder $query, $sql, $values, $sequence = null)
    {
        $this->response = $query->getConnection()->insert($sql, $values);

        $id = $this->response[0]['id'];

        return is_numeric($id) ? (int) $id : $id;
    }

    public function getResponse(): array
    {
        return $this->response[0] ?? $this->response ?? [];
    }
}
