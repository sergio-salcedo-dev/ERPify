<?php

declare(strict_types=1);

namespace Erpify\Backoffice\Bank\Infrastructure\Controller;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Validator\ConstraintViolationListInterface;

trait ValidationTrait
{
    private function validationErrorResponse(ConstraintViolationListInterface $constraintViolationList): JsonResponse
    {
        $errors = [];
        foreach ($constraintViolationList as $violation) {
            $errors[] = [
                'field' => $this->toSnakeCase($violation->getPropertyPath()),
                'message' => $violation->getMessage(),
            ];
        }

        return new JsonResponse(['errors' => $errors], \Symfony\Component\HttpFoundation\Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    private function toSnakeCase(string $propertyPath): string
    {
        return strtolower((string) preg_replace('/[A-Z]/', '_$0', lcfirst($propertyPath)));
    }
}
