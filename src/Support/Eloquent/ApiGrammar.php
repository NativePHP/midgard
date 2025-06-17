<?php

namespace Native\Api\Client\Support\Eloquent;

use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Log;
use Illuminate\Database\Query\Builder;
use GuzzleHttp\Psr7\Query;
use Illuminate\Database\Query\Grammars\Grammar as BaseGrammar;

class ApiGrammar extends BaseGrammar
{
    protected $resource_end_point;

    /**
     * The components that make up a select clause.
     *
     * @var array
     */
    protected $selectComponents = [
        'aggregate',
        'columns',
        'from',
        'joins',
        'wheres',
        'groups',
        'havings',
        'orders',
        'limit',
        'offset',
        'unions',
        'lock',
        'includes'
    ];

    public function compileSelect(Builder $query)
    {
        $components = $this->compileComponents($query);
        $params = [];
        $limit = 25;

        if (array_key_exists('aggregate', $components)) {
            $params['aggregate'] = $components['aggregate'];
        }

        if (array_key_exists('columns', $components)) {
            $params['fields'] = $components['columns'];
        }

        if (array_key_exists('limit', $components)) {
            $params['limit'] = $limit = $components['limit'] - 1;
        }

        if (array_key_exists('orders', $components)) {
            $params['sort'] = $components['orders'];
        }

        if (array_key_exists('offset', $components)) {
            $params['page'] = (int) ceil($components['offset'] / $limit) + 1;
        }

        if (array_key_exists('includes', $components)) {
            $params['include'] = $components['includes'];
        }

        $params = array_merge($query->getQueryParams(), $params);

        $query_string = Query::build($params);

        if (count($query->wheres) === 1) {
            if ($query->wheres[0]['column'] == 'internal_id' 
                && $query->wheres[0]['operator'] == '=') {
                $resource_id = $query->wheres[0]['value'];
                unset($components['wheres']);
            }
        }
        if (!empty($components['wheres'])) {
            $query_string .= ($query_string ? '&' : '' ) . "{$components['wheres']}";
        }

        $api_query = "{$query->getResourceEndpoint()}?{$query_string}";

        if(isset($resource_id)) {
            $api_query = $query->getResourceEndpoint().($resource_id ? '/'.$resource_id : '').'?'.$query_string;
        }

        Log::debug('API GET', [$api_query]);

        return $api_query;
    }

    public function compileInsert(Builder $query, array $values)
    {
        Log::debug('API POST', [$query->getResourceEndpoint(), $values]);
        return $query->getResourceEndpoint();
    }

    public function compileUpdate(Builder $query, $values)
    {
        $resource_id = $query->bindings['where'][0];
        $this->setResourceEndpoint($query->getResourceEndpoint());

        unset($query->bindings['where']);

        Log::debug('API PUT', ["{$this->getResourceEndpoint()}/{$resource_id}", $values]);
        return "{$this->getResourceEndpoint()}/{$resource_id}";
    }

    public function prepareBindingsForUpdate(array $bindings, array $values)
    {
        $cleanBindings = Arr::except($bindings, ['select', 'join']);
        $resource_end_point = $this->getResourceEndpoint();

        if (isset($values["{$resource_end_point}.updated_at"])) {
            $values['updated_at'] = $values["{$resource_end_point}.updated_at"];
            unset($values["{$resource_end_point}.updated_at"]);
        }

        return array_merge($bindings['join'], $values, Arr::flatten($cleanBindings));
    }

    public function compileDelete(Builder $query)
    {
        $resource_id = $query->bindings['where'][0];
        $this->setResourceEndpoint($query->getResourceEndpoint());

        unset($query->bindings['where']);

        Log::debug('API DELETE', ["{$this->getResourceEndpoint()}/{$resource_id}"]);
        return "{$this->getResourceEndpoint()}/{$resource_id}";
    }

    protected function setResourceEndpoint($resource_end_point)
    {
        $this->resource_end_point = $resource_end_point;
    }

    protected function getResourceEndpoint()
    {
        return $this->resource_end_point;
    }
    /**
     * Remove * from the fields query param.
     *
     * @param array $columns
     * @return array
     */
    protected function stripStar(?array $columns)
    {
        if (is_null($columns)) {
            return;
        }

        if (($star = array_search('*', $columns)) !== false) {
            unset($columns[ $star ]);
        }

        return $this->fields = array_values($columns);
    }

    protected function compileColumns(Builder $query, $columns)
    {
        return $this->stripStar($query->columns);
    }

    protected function compileFrom(Builder $query, $table)
    {
        return $query->from;
    }

    public function compileWheres(Builder $query)
    {
        $filters = [];
        $filter_style = 'and';

        foreach ($query->wheres as $group => $wheres) {
            if (! in_array($wheres['type'], ['Basic', 'Nested', 'Null', 'NotNull', 'In', 'NotIn', 'between', 'Exists'])) {
                Log::warn("Missing where type handler for type: {$wheres['type']}");
                Log::info('Wheres', $wheres);
                continue;
            }

            if (!empty($wheres['column']) && Str::contains($wheres['column'], '.')) {
                $searches = ["{$query->from}.", '.'];
                $replacements = ['', ':'];
                $wheres['column'] = str_replace($searches, $replacements, $wheres['column']);
            }


            switch ($wheres['type']) {
                case 'Basic':
                    $wheres = [$wheres];
                    break;

                case 'Nested':
                    $wheres = $wheres['query']->wheres;
                    break;

                case 'Null':
                    $wheres['operator'] = '=';
                    $wheres['value'] = 'null';
                    $wheres = [$wheres];
                    break;

                case 'NotNull':
                    $wheres['operator'] = '!=';
                    $wheres['value'] = 'null';
                    $wheres = [$wheres];
                    break;

                case 'In':
                    $wheres['operator'] = 'in';
                    $wheres['value'] = implode(',', $wheres['values']);
                    $wheres = [$wheres];
                    break;

                case 'NotIn':
                    $wheres['operator'] = 'not in';
                    $wheres['value'] = implode(',', $wheres['values']);
                    $wheres = [$wheres];
                    break;

                case 'between':
                    $wheres = [
                        [
                            'column' => $wheres['column'],
                            'value' => $wheres['values'][0],
                            'operator' => '>=',
                            'boolean' => $wheres['boolean']
                        ],
                        [
                            'column' => $wheres['column'],
                            'value' => $wheres['values'][1],
                            'operator' => '<=',
                            'boolean' => $wheres['boolean']
                        ],
                    ];
                    break;

                case 'Exists':
                    $from = $query->getModel()->getRelationNameForResource($wheres['query']->from);

                    foreach ($wheres['query']->wheres as $where_clause) {
                        if (array_key_exists('type', $where_clause) && $where_clause['type'] === 'Basic') {
                            $wheres = [
                                [
                                    'column' => $from . ':' . $where_clause['column'],
                                    'value' => $where_clause['value'],
                                    'operator' => $where_clause['operator'],
                                    'boolean' => $wheres['boolean']
                                ]
                            ];

                            break;
                        }
                    }
                    break;
            }

            $local_filters = [];

            foreach ($wheres as $where) {
                ['column' => $column, 'value' => $value, 'operator' => $operator, 'boolean' => $boolean] = $where;

                $local_filters[] = $this->buildFilter($query, $column, $operator, $value, $group);

                if ($boolean === 'or') {
                    $filter_style = 'or';
                }
            }

            if ($filter_style !== 'and' && count($wheres) > 1) {
                $local_filters[] = $this->buildFilterStyle('filter_style', $filter_style, $group);
            }

            $filters[] = implode('&', $local_filters);
        }

        $filters = implode('&', $filters);

        $filter_style_string = '';

        if ($filter_style !== 'and') {
            $filter_style_string = '&filter_style=' . $filter_style;
        }
        return $filters . $filter_style_string;
    }

    /**
     * Build a URI param for a group of filters
     *
     * @param  Builder $query
     * @param  string $column
     * @param  string $operator
     * @param  string $value
     * @param  ?string $group
     * @return string
     */
    protected function buildFilter(Builder $query, string $column, string $operator, string $value, ?string $group)
    {
        $value = rawurlencode(Str::replaceLast('%', '', Str::replaceFirst('%', '', $value)));

        if (Str::contains($column, '.')) {
            $column = Str::after($column, '.');
        }

        if ($group !== null) {
            return "filter[{$group}][{$column}][]=\"{$value}\",{$operator}";
        }

        return "filter[{$column}][]=\"{$value}\",{$operator}";
    }

    /**
     * Construct URI param for filter style for a group of filters
     *
     * @param  string $column
     * @param  string $value
     * @param  string $group
     * @return string
     */
    protected function buildFilterStyle(string $column, string $value, string $group): string
    {
        $value = rawurlencode(Str::replaceLast('%', '', Str::replaceFirst('%', '', $value)));

        if (Str::contains($column, '.')) {
            $column = Str::after($column, '.');
        }

        return "filter[{$group}][{$column}]={$value}";
    }

    protected function compileAggregate(Builder $query, $aggregate)
    {
        return $aggregate['function'];
    }

    protected function compileLimit(Builder $query, $limit)
    {
        return (int) $limit;
    }

    protected function compileOffset(Builder $query, $offset)
    {
        return (int) $offset;
    }

    protected function compileOrders(Builder $query, $orders)
    {
        if (! empty($orders)) {
            return implode(', ', $this->compileOrdersToArray($query, $orders));
        }

        return '';
    }

    protected function compileOrdersToArray(Builder $query, $orders)
    {
        return array_map(function ($order) use ($query) {
            return (strtolower($order['direction']) === 'desc' ? '-' : '') . $order['column'];
        }, $orders);
    }

    protected function compileSorts($orders)
    {
        if (empty($orders)) {
            return;
        }

        $sorts = [];

        if (! empty($orders)) {
            foreach ($orders as $order) {
                ['column' => $column, 'direction' => $direction] = $order;

                $sorts[] = ($direction === 'desc' ? '-' : '') . $column;
            }
        }

        return implode(',', $sorts);
    }

    protected function compileIncludes(Builder $query, $includes)
    {
        return implode(',', $includes);
    }
}
