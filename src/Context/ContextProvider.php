<?php

declare(strict_types=1);

namespace PhpLogger\Context;

interface ContextProvider
{
    public function current(): RequestContext;
}
