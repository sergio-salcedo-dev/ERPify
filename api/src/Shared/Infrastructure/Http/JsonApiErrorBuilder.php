<?php

declare(strict_types=1);

namespace Erpify\Shared\Infrastructure\Http;

use Symfony\Component\Validator\ConstraintViolationListInterface;

final class JsonApiErrorBuilder
{
    /**
     * @param list<array<string, mixed>> $errors
     *
     * @return array{errors: list<array<string, mixed>>, meta: array{requestId: string}}
     */
    public static function envelope(array $errors): array
    {
        return [
            'errors' => $errors,
            'meta' => ['requestId' => \bin2hex(\random_bytes(8))],
        ];
    }

    /** @return array<string, mixed> */
    public static function error(string $parameter, string $title): array
    {
        return [
            'source' => ['parameter' => $parameter],
            'title' => $title,
        ];
    }

    /** @return array{errors: list<array<string, mixed>>, meta: array{requestId: string}} */
    public static function fromViolations(
        ConstraintViolationListInterface $violations,
        ?string $defaultParameter = null,
    ): array {
        $errors = [];

        foreach ($violations as $violation) {
            $parameter = '' !== $violation->getPropertyPath()
                ? self::toSnakeCase($violation->getPropertyPath())
                : ($defaultParameter ?? '');
            $errors[] = self::error($parameter, (string) $violation->getMessage());
        }

        return self::envelope($errors);
    }

    private static function toSnakeCase(string $propertyPath): string
    {
        return \strtolower((string) \preg_replace('/[A-Z]/', '_$0', \lcfirst($propertyPath)));
    }
}
