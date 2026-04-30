<?php

declare(strict_types=1);

namespace Erpify\Backoffice\Bank\Infrastructure\Controller;

use Erpify\Shared\Infrastructure\Http\JsonApiErrorBuilder;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Validator\ConstraintViolationListInterface;

trait ValidationTrait
{
    private function validationErrorResponse(ConstraintViolationListInterface $constraintViolationList): JsonResponse
    {
        return new JsonResponse(
            JsonApiErrorBuilder::fromViolations($constraintViolationList),
            Response::HTTP_UNPROCESSABLE_ENTITY,
        );
    }
}
