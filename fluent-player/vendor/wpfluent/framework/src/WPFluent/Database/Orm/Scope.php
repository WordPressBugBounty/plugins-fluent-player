<?php

namespace FluentPlayer\Framework\Database\Orm;

interface Scope
{
    /**
     * Apply the scope to a given Orm query builder.
     *
     * @param  \FluentPlayer\Framework\Database\Orm\Builder  $builder
     * @param  \FluentPlayer\Framework\Database\Orm\Model  $model
     * @return void
     */
    public function apply(Builder $builder, Model $model);
}
