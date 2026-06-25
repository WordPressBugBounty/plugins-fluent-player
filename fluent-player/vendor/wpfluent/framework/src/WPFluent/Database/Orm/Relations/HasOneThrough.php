<?php

namespace FluentPlayer\Framework\Database\Orm\Relations;

use FluentPlayer\Framework\Database\Orm\Model;
use FluentPlayer\Framework\Database\Orm\Collection;
use FluentPlayer\Framework\Database\Orm\Relations\Concerns\SupportsDefaultModels;
use FluentPlayer\Framework\Database\Orm\Relations\Concerns\InteractsWithDictionary;

class HasOneThrough extends HasOneOrManyThrough
{
    use InteractsWithDictionary, SupportsDefaultModels;

    /**
     * Get the results of the relationship.
     *
     * @return mixed
     */
    public function getResults()
    {
        return $this->first() ?: $this->getDefaultFor($this->farParent);
    }

    /**
     * Initialize the relation on a set of models.
     *
     * @param  array  $models
     * @param  string  $relation
     * @return array
     */
    public function initRelation(array $models, $relation)
    {
        foreach ($models as $model) {
            $model->setRelation($relation, $this->getDefaultFor($model));
        }

        return $models;
    }

    /**
     * Match the eagerly loaded results to their parents.
     *
     * @param  array  $models
     * @param  \FluentPlayer\Framework\Database\Orm\Collection  $results
     * @param  string  $relation
     * @return array
     */
    public function match(array $models, Collection $results, $relation)
    {
        $dictionary = $this->buildDictionary($results);

        // Once we have the dictionary we can simply spin through the parent models to
        // link them up with their children using the keyed dictionary to make the
        // matching very convenient and easy work. Then we'll just return them.
        foreach ($models as $model) {
            if (isset($dictionary[$key = $this->getDictionaryKey($model->getAttribute($this->localKey))])) {
                $value = $dictionary[$key];
                $model->setRelation(
                    $relation, reset($value)
                );
            }
        }

        return $models;
    }

    /**
     * Make a new related instance for the given model.
     *
     * @param  \FluentPlayer\Framework\Database\Orm\Model  $parent
     * @return \FluentPlayer\Framework\Database\Orm\Model
     */
    public function newRelatedInstanceFor(Model $parent)
    {
        return $this->related->newInstance();
    }
}
