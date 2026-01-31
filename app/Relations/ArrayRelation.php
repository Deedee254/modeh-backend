<?php

namespace App\Relations;

use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * A lightweight Relation implementation that maps an array-of-ids attribute
 * on the parent model to actual Eloquent models (e.g. subjects stored as
 * JSON ids on a profile model). This allows callers to eager-load the
 * accessor-like data using ->with('subjectModels') without throwing the
 * "undefined relationship" exception.
 */
class ArrayRelation extends Relation
{
    /**
     * Attribute name on the parent model that contains array of related ids
     * @var string
     */
    protected string $attribute;

    public function __construct(Builder $query, Model $parent, string $attribute = 'subjects')
    {
        parent::__construct($query, $parent);
        $this->attribute = $attribute;
    }

    /**
     * Apply constraints for single-model queries.
     */
    public function addConstraints()
    {
        $ids = $this->parent->{$this->attribute} ?? [];
        if (is_array($ids) && count($ids) > 0) {
            $this->query->whereIn($this->query->getModel()->getTable() . '.id', $ids);
        } else {
            // No ids -> ensure empty result
            $this->query->whereRaw('1 = 0');
        }
    }

    /**
     * Apply constraints for eager-loading a set of parent models.
     * We aggregate ids across parents and query once.
     */
    public function addEagerConstraints(array $models)
    {
        $ids = [];
        foreach ($models as $model) {
            $vals = $model->{$this->attribute} ?? [];
            if (is_array($vals)) {
                $ids = array_merge($ids, $vals);
            }
        }
        $ids = array_values(array_filter(array_unique($ids)));

        if (count($ids) > 0) {
            $this->query->whereIn($this->query->getModel()->getTable() . '.id', $ids);
        } else {
            // ensure empty result set
            $this->query->whereRaw('1 = 0');
        }
    }

    /**
     * Initialize the relation on a set of models (empty collection)
     */
    public function initRelation(array $models, $relation)
    {
        foreach ($models as $model) {
            $model->setRelation($relation, collect([]));
        }
        return $models;
    }

    /**
     * Match the eagerly loaded results back onto their parent models.
     */
    public function match(array $models, Collection $results, $relation)
    {
        foreach ($models as $model) {
            $ids = $model->{$this->attribute} ?? [];
            $model->setRelation($relation, $results->filter(function ($item) use ($ids) {
                return in_array($item->id, (array) $ids);
            })->values());
        }
        return $models;
    }

    /**
     * Get results for the relation on the current parent model.
     */
    public function getResults()
    {
        $ids = $this->parent->{$this->attribute} ?? [];
        if (empty($ids) || !is_array($ids)) return collect([]);
        return $this->query->whereIn($this->query->getModel()->getTable() . '.id', $ids)->get();
    }
}
