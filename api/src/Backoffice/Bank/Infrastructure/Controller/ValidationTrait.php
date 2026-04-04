<?php

declare(strict_types=1);

namespace Erpify\Backoffice\Bank\Infrastructure\Controller;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Validator\ConstraintViolationListInterface;

trait ValidationTrait
{
    private function validationErrorResponse(ConstraintViolationListInterface $violations): JsonResponse
    {
        $errors = [];
        foreach ($violations as $violation) {
            $errors[] = [
                'field' => $this->toSnakeCase($violation->getPropertyPath()),
                'message' => $violation->getMessage(),
            ];
        }

        return new JsonResponse(['errors' => $errors], 422);
    }

    private function toSnakeCase(string $propertyPath): string
    {
        return strtolower((string) preg_replace('/[A-Z]/', '_$0', lcfirst($propertyPath)));
    }
}
