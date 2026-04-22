<?php

declare(strict_types=1);

namespace Erpify\Shared\Infrastructure\Http\Responder;

use Erpify\Shared\Application\UseCase\Result;
use Symfony\Component\HttpFoundation\Response;

interface ResponderInterface
{
    public function respond(Result $result): Response;
}
