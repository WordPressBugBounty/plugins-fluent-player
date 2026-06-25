<?php

namespace FluentPlayer\Framework\Container;

use Exception;
use FluentPlayer\Framework\Container\Contracts\Psr\NotFoundExceptionInterface;

class EntryNotFoundException extends Exception implements NotFoundExceptionInterface
{
    //
}
