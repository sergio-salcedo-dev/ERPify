<?php

declare(strict_types=1);

namespace Erpify\Tests\Behat\Context\Json;

use Behat\Step\Then;
use Erpify\Tests\Behat\Context\Abstraction\AbstractContext;
use Erpify\Tests\Behat\Support\Json\JsonResponseAwareTrait;
use JsonException;

/**
 * Validates RFC 9457 Problem Details error payloads of the last HTTP response
 * (top-level errors and per-field validation errors).
 *
 * @SuppressWarnings("PHPMD.CyclomaticComplexity")
 */
class JsonErrorContext extends AbstractContext
{
    use JsonResponseAwareTrait;

    /**
     * Validate that the response contains an error with the given ` message `.
     *
     * @throws JsonException
     */
    #[Then('the error :message should exist')]
    public function theErrorMessageShouldExist(string $message): void
    {
        /** @var array<string, mixed> $jsonResponse */
        $jsonResponse = $this->getJson()->getContent(true);

        $this->assertIsErrorResponse($jsonResponse);

        foreach ($jsonResponse['errors'] as $error) {
            self::assertArrayHasKey('title', $error);

            if ($message === $this->getErrorTitle($error)) {
                return;
            }
        }

        self::fail(
            \sprintf(
                'The error message "%s" was not found',
                $message,
            ),
        );
    }

    /**
     * Validate that the response contains error with given `message` and `code`.
     *
     * @throws JsonException
     */
    #[Then('the error :message with code :code should exist')]
    public function theErrorMessageWithCodeShouldExist(string $message, string $errorCode): void
    {
        /** @var array<string, mixed> $jsonResponse */
        $jsonResponse = $this->getJson()->getContent(true);

        $this->assertIsErrorResponse($jsonResponse);

        foreach ($jsonResponse['errors'] as $error) {
            self::assertArrayHasKey('title', $error);
            self::assertArrayHasKey('code', $error);

            if ($message === $this->getErrorTitle($error) && $errorCode === $error['code']) {
                return;
            }
        }

        self::fail(
            \sprintf(
                'The error message "%s" was not found',
                $message,
            ),
        );
    }

    /**
     * Validate that the response contains error with given `message` and `code`.
     *
     * @throws JsonException
     */
    #[Then('the :code error :message should exist')]
    public function theNotFoundErrorMessageShouldExist(string $code, string $message): void
    {
        /** @var array<string, mixed> $jsonResponse */
        $jsonResponse = (array) $this->getJson()->getContent(true);

        $this->assertIsErrorResponse($jsonResponse);

        foreach ($jsonResponse['errors'] as $error) {
            self::assertArrayHasKey('title', $error);
            self::assertArrayHasKey('status', $error);
            self::assertArrayHasKey('source', $error);
            self::assertIsArray($error['source']);
            self::assertArrayHasKey('type', $error['source']);

            if (
                $message === $this->getErrorTitle($error)
                && (int) $code === $error['status']
                && 'httpException' === $error['source']['type']
            ) {
                return;
            }
        }

        self::fail(
            \sprintf(
                'The error message "%s" with code "%s" was not found',
                $message,
                $code,
            ),
        );
    }

    /**
     * Validate that the response contains an error for a given ` field `.
     *
     * @throws JsonException
     */
    #[Then('the validation error on :field should be :message')]
    public function theValidationErrorOnFieldShouldBe(string $field, string $message): void
    {
        /** @var array<string, mixed> $jsonResponse */
        $jsonResponse = $this->getJson()->getContent(true);

        $this->assertIsErrorResponse($jsonResponse);

        foreach ($jsonResponse['errors'] as $error) {
            self::assertArrayHasKey('source', $error);
            self::assertIsArray($error['source']);

            if (!\array_key_exists('parameter', $error['source'])) {
                continue;
            }

            self::assertArrayHasKey('parameter', $error['source']);
            self::assertArrayHasKey('title', $error);

            if (
                $error['source']['parameter'] === $field
                && $this->getErrorTitle($error) === $message
            ) {
                return;
            }
        }

        self::fail(
            \sprintf(
                'The validation message "%s" for field "%s" was not found',
                $message,
                $field,
            ),
        );
    }

    /**
     * Validate that the response should contain an error for a given ` field `.
     *
     * @throws JsonException
     */
    #[Then('the validation error on :field should contain :message')]
    public function theValidationErrorOnFieldShouldContain(string $field, string $message): void
    {
        /** @var array<string, mixed> $jsonResponse */
        $jsonResponse = $this->getJson()->getContent(true);

        $this->assertIsErrorResponse($jsonResponse);

        foreach ($jsonResponse['errors'] as $error) {
            self::assertArrayHasKey('source', $error);
            self::assertIsArray($error['source']);

            if (!\array_key_exists('parameter', $error['source'])) {
                continue;
            }

            self::assertArrayHasKey('parameter', $error['source']);
            self::assertArrayHasKey('title', $error);

            if (
                $error['source']['parameter'] === $field
                && \str_contains($this->getErrorTitle($error), $message)
            ) {
                return;
            }
        }

        self::fail(
            \sprintf(
                'The validation message "%s" for field "%s" was not found',
                $message,
                $field,
            ),
        );
    }

    /**
     * Validate that the response does not contain an error for the given ` field `.
     *
     * @throws JsonException
     */
    #[Then('the validation error on :field should not exist')]
    public function theValidationErrorOnFieldShouldNotExist(string $field): void
    {
        /** @var array<string, mixed> $jsonResponse */
        $jsonResponse = $this->getJson()->getContent(true);

        $this->assertIsErrorResponse($jsonResponse);

        foreach ($jsonResponse['errors'] as $error) {
            self::assertArrayHasKey('source', $error);
            self::assertIsArray($error['source']);

            if (!\array_key_exists('parameter', $error['source'])) {
                continue;
            }

            if ($error['source']['parameter'] === $field) {
                self::fail(\sprintf('The validation error on field "%s" exist', $field));
            }
        }
    }

    /**
     * Validate that the response contains error for given `field` with given `code`.
     *
     * @throws JsonException
     */
    #[Then('the validation error code on :field should be :code')]
    public function theValidationErrorCodeOnFieldShouldBe(string $field, string $code): void
    {
        /** @var array<string, mixed> $jsonResponse */
        $jsonResponse = $this->getJson()->getContent(true);

        $this->assertIsErrorResponse($jsonResponse);

        foreach ($jsonResponse['errors'] as $error) {
            self::assertArrayHasKey('source', $error);
            self::assertIsArray($error['source']);

            if (!\array_key_exists('parameter', $error['source'])) {
                continue;
            }

            self::assertArrayHasKey('parameter', $error['source']);
            self::assertArrayHasKey('code', $error);

            if (
                $error['source']['parameter'] === $field
                && $error['code'] === $code
            ) {
                return;
            }
        }

        self::fail(
            \sprintf(
                'The validation code "%s" for field "%s" was not found',
                $code,
                $field,
            ),
        );
    }

    /**
     * @param array<string, mixed> $array
     *
     * @phpstan-assert array{errors: list<array<string, mixed>>, meta: array{requestId: mixed}} $array
     */
    public function assertIsErrorResponse(array $array): void
    {
        self::assertArrayHasKey('errors', $array);
        self::assertIsList($array['errors']);

        foreach ($array['errors'] as $error) {
            self::assertIsArray($error);
        }

        self::assertArrayHasKey('meta', $array);
        self::assertIsArray($array['meta']);
        self::assertArrayHasKey('requestId', $array['meta']);
    }

    /**
     * @param array<string, mixed> $error
     */
    public function getErrorTitle(array $error): string
    {
        self::assertArrayHasKey('title', $error);
        $title = $error['title'];
        self::assertIsString($title);
        $errorTitle = $title;

        $meta = $error['meta'] ?? [];
        $messageParameters = \is_array($meta) ? ($meta['messageParameters'] ?? []) : [];

        if (!\is_array($messageParameters)) {
            return $errorTitle;
        }

        foreach ($messageParameters as $key => $value) {
            if (!\is_scalar($value) && null !== $value) {
                continue;
            }

            $errorTitle = \str_replace([(string) $key, "\u{202f}"], [(string) $value, ' '], $errorTitle);
        }

        return $errorTitle;
    }
}
