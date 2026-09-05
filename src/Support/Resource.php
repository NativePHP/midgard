<?php

namespace Native\Api\Client\Support;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\Relations\HasOneOrMany;
use Illuminate\Support\Str;
use Native\Api\Client\Support\Eloquent\ApiConnectionInterface;

abstract class Resource extends Model
{
    /**
     * @var string Where this resource is located.
     *
     * Analogous to $table. Use this instead to keep your models free from corruption
     */
    protected $endpoint;

    protected $endpoint_prefix;

    protected $endpoint_suffix;

    protected $connection = 'api';

    protected $perPage = 20;

    protected $primaryKey = 'internal_id';

    protected $relation_methods = [];

    protected $relation_resources = [];

    /**
     * {@inheritdoc}
     */
    protected static $unguarded = true;

    /**
     * Hydrate the relations the API inlined in the payload.
     *
     * Registered once per model class rather than once per instance, otherwise every
     * `new Resource` would stack another copy of this listener on the class.
     */
    protected static function booted()
    {
        static::retrieved(function ($model) {
            $relationships = $model->getAttributes()['relationships'] ?? [];

            foreach ($relationships as $relationship => $relations) {
                if (empty($relations)) {
                    continue;
                }

                $relation_models = [];

                foreach ($relations as $relation_data) {
                    $relationship_object = $model->$relationship();

                    if ($relationship_object instanceof HasOneOrMany) {
                        $relation_models[] = $relationship_object->make($relation_data);
                    } else {
                        // We only want one
                        $relation_models = $relationship_object->make($relation_data);
                        break;
                    }
                }

                $model->setRelation($relationship, $relation_models);
            }
        });
    }

    /**
     * This relies on an appropriate sub-resource defined on the server. The server must perform the
     * actual hasMany/hasManyThrough as this is better all around: fewer requests, fewer queries, fewer problems.
     *
     * @param  string  $related
     * @param  string  $through
     * @param  null  $firstKey
     * @param  null  $secondKey
     * @param  null  $localKey
     * @param  null  $secondLocalKey
     * @return resource|HasManyThrough
     */
    public function hasManyThrough(
        $related,
        $through,
        $firstKey = null,
        $secondKey = null,
        $localKey = null,
        $secondLocalKey = null
    ) {
        if (! $this->isApiResource()) {
            return parent::hasManyThrough($related, $through, $firstKey, $secondKey, $localKey, $secondLocalKey);
        }

        /** @var resource $instance */
        $instance = new $related;

        $instance->setEndpointPrefix($this->newQuery()->toBase()->getResourceEndpoint($this->getKey()));

        return $instance;
    }

    public function isApiResource()
    {
        return interface_exists(ApiConnectionInterface::class)
            && $this->getConnection() instanceof ApiConnectionInterface;
    }

    public function getEndpointPrefix()
    {
        if (isset($this->endpoint_prefix)) {
            return Str::finish($this->endpoint_prefix, '/');
        }
    }

    public function setEndpointPrefix($prefix = null)
    {
        $this->endpoint_prefix = $prefix;

        return $this;
    }

    public function getTable()
    {
        if (! $this->isApiResource()) {
            return parent::getTable();
        }

        return $this->endpoint ?? Str::snake(Str::plural(class_basename($this)), '-');
    }

    public function qualifyColumn($column)
    {
        if (! $this->isApiResource()) {
            return parent::qualifyColumn($column);
        }

        if (Str::contains($column, '.')) {
            return Str::after($column, '.');
        }

        return $column;
    }

    public function getForeignKey()
    {
        if (! $this->isApiResource()) {
            return parent::getForeignKey();
        }

        return Str::snake(class_basename($this)).'_id';
    }

    /**
     * Get a new query builder that doesn't have any global scopes.
     *
     * @return Builder|static
     */
    public function newQueryWithoutScopes()
    {
        $query = $this->newModelQuery();

        if ($this->with) {
            $query->setIncludes($this->with);
        }

        if ($this->fields) {
            $query->setColumns($this->fields);
        }

        return $query;
    }

    /**
     * Get a new query builder that doesn't have any global scopes or eager loading.
     *
     * @return Builder|static
     */
    public function newModelQuery()
    {
        return $this->newEloquentBuilder(
            $this->newBaseQueryBuilder()
        )->setModel($this);
    }

    /**
     * The resource name will often be the pluralised, kebab-case form of the related resource's class name.
     * This is problematic when the API you're calling uses a specific, context-sensitive naming convention.
     * So we'll do some tweaking here to calculate that.
     *
     * N.B. We could get around this (and thus avoid forcing the models to declare this extra array)
     *      if the resource class overrides the relationship methods (belongsTo, hasOne etc)
     *      adding the name of the method to the relation_resources array in the process..
     *      But that's a lot more work.
     */
    public function getRelationNameForResource($resource): string
    {
        if ($resource instanceof self) {
            $resource = $resource->getTable();
        }

        return array_search($resource, $this->getRelationResources()) ?: $resource;
    }

    public function getRelationResources(): array
    {
        if (empty($this->relation_methods)) {
            return [];
        }

        if (! empty($this->relation_resources)) {
            return $this->relation_resources;
        }

        foreach ($this->relation_methods as $relation) {
            $this->relation_resources[$relation] = $this->{$relation}()->getRelated()->getTable();
        }

        return $this->relation_resources;
    }

    /**
     * Get a new query builder instance for the connection.
     *
     * @return \Illuminate\Database\Query\Builder
     */
    protected function newBaseQueryBuilder()
    {
        $connection = $this->getConnection();

        if (! $connection instanceof ApiConnectionInterface) {
            return parent::newBaseQueryBuilder();
        }

        return $connection->setModel($this)->query();
    }
}
