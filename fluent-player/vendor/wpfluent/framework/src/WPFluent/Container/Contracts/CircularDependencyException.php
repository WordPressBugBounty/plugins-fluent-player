<?php

namespace FluentPlayer\Framework\Container\Contracts;

use Exception;
use FluentPlayer\Framework\Container\Contracts\Psr\ContainerExceptionInterface;

class CircularDependencyException extends Exception implements ContainerExceptionInterface
{
    //
}
