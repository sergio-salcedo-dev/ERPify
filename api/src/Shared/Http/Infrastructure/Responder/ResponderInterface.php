<?php

declare(strict_types=1);

namespace Erpify\Shared\Http\Infrastructure\Responder;

use Erpify\Shared\Kernel\Application\Result;
use Symfony\Component\HttpFoundation\Response;

interface ResponderInterface
{
    public function respond(Result $result): Response;
}
