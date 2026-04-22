<?php

declare(strict_types=1);

namespace Erpify\Shared\Infrastructure\Http\Responder;

use Erpify\Shared\Application\UseCase\Result;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

final class JsonResponder implements ResponderInterface
{
    public function respond(Result $result): Response
    {
        if (Result::STATUS_NO_CONTENT === $result->status) {
            return new Response(status: $result->status);
        }

        return new JsonResponse(['data' => $result->data], $result->status);
    }
}
