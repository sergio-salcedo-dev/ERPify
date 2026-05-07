<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Doctrine\Stubs\Controller;

use Erpify\Tests\Doctrine\TestDebugDataHolder;
use Symfony\Bridge\Doctrine\Middleware\Debug\Query;

final class FakeAction
{
    public function record(TestDebugDataHolder $holder, Query $query): void
    {
        $holder->addQuery('default', $query);
    }
}
