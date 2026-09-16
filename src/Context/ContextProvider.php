<?php

declare(strict_types=1);

namespace Logger\Context;

interface ContextProvider
{
    public function current(): RequestContext;
}
