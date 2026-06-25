<?php

namespace FluentPlayer\Framework\Database\Query;

use FluentPlayer\Framework\Database\BaseGrammar;

interface ExpressionInterface
{
    /**
     * Get the value of the expression.
     *
     * @param  \FluentPlayer\Framework\Database\BaseGrammar $grammar
     * @return string|int|float
     */
    public function getValue(BaseGrammar $grammar);
}
