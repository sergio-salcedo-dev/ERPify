<?php

declare(strict_types=1);

namespace Erpify\Shared\Audit\Application;

use Erpify\Shared\Audit\Domain\ActorContext;

/**
 * Produces the {@see ActorContext} for the act being audited. The one piece of the audit axis that
 * changes when real authentication arrives: until then it resolves an in-flight request to anonymous
 * and off-request execution to system; the day users exist, only this resolution gains user/api-key
 * actors — schema, bus, writer, transport and handler stay untouched.
 */
interface ActorContextFactory
{
    public function current(): ActorContext;
}
