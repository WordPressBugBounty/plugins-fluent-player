<?php

namespace FluentPlayer\Framework\Database\Orm;

interface Castable
{
    /**
     * Get the name of the caster class to use when casting from / to this cast target.
     *
     * @param  array  $arguments
     * @return string
     * @return string|\FluentPlayer\Framework\Database\Orm\CastsAttributes|\FluentPlayer\Framework\Database\Orm\CastsInboundAttributes
     */
    public static function castUsing(array $arguments);
}
