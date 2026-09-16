<?php

declare(strict_types=1);

namespace Logger\Interfaces\Context;

use Logger\Context\RequestContext;

interface ContextProviderInterface
{
    public function current(): RequestContext;
}
