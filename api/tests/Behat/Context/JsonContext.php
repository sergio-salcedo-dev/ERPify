<?php

declare(strict_types=1);

namespace Erpify\Tests\Behat\Context;

use Behat\Gherkin\Node\PyStringNode;
use Behat\Gherkin\Node\TableNode;
use Behat\Step\Given;
use Behat\Step\Then;
use DateMalformedStringException;
use Erpify\Tests\Behat\Assertion\Json\Json;
use Erpify\Tests\Behat\Assertion\Json\JsonSchema;
use Erpify\Tests\Behat\Assertion\NodeModifier\NodeModifierInterface;
use Erpify\Tests\Behat\Context\Abstraction\AbstractContext;
use Erpify\Tests\Behat\Support\PostProcess\JsonPathToolTrait;
use Erpify\Tests\Behat\Support\PostProcess\JsonToolTrait;
use Erpify\Tests\Behat\Transport\HttpResponseContainer;
use Exception;
use Flow\JSONPath\JSONPathException;
use JsonException;
use RuntimeException;

use const DIRECTORY_SEPARATOR;

/**
 * This context gives the capability to manipulate and validate Json data.
 *
 * @SuppressWarnings("PHPMD.TooManyMethods")
 * @SuppressWarnings("PHPMD.TooManyPublicMethods")
 * @SuppressWarnings("PHPMD.ExcessiveClassComplexity")
 * @SuppressWarnings("PHPMD.ExcessivePublicCount")
 * @SuppressWarnings("PHPMD.ExcessiveClassLength")
 */
class JsonContext extends AbstractContext
{
    use JsonPathToolTrait;
    use JsonToolTrait;

    public function __construct(
        protected readonly HttpResponseContainer $httpResponseContainer,
    ) {
    }

    /**
     * @throws JsonException
     */
    public function getJson(): Json
    {
        $lastResult = $this->httpResponseContainer->getResult();
        self::assertNotNull($lastResult, 'No HTTP Call made');

        return $lastResult->getJson();
    }

    /**
     * Validate that the data is a proper JSON.
     */
    #[Then('the response should be in JSON')]
    public function theResponseShouldBeInJson(): void
    {
        try {
            $this->getJson();
        } catch (Exception $exception) {
            self::fail($exception->getMessage());
        }
    }

    /**
     * Validate that the data is not a proper JSON.
     */
    #[Then('the response should not be in JSON')]
    public function theResponseShouldNotBeInJson(): void
    {
        try {
            $this->getJson();
        } catch (Exception) {
            return;
        }

        self::fail(\sprintf('JSON %s is valid but should not', \json_encode($this->getJson()->getContent())));
    }

    /**
     * Validate the JSON property `node` is equal to `text`.
     *
     * @throws JsonException
     * @throws Exception
     */
    #[Then('the JSON node :node should be equal to :text')]
    public function theJsonNodeShouldBeEqualTo(string $node, string $text): void
    {
        $this->jsonPropertyShouldBeEqualTo($this->getJson(), $node, $text);
    }

    /**
     * Validate the JSON property `node` is not equal to `text`.
     *
     * @throws JsonException
     */
    #[Then('the JSON node :node should not be equal to :text')]
    public function theJsonNodeShouldNotBeEqualTo(string $node, string $text): void
    {
        $this->jsonPropertyShouldNotBeEqualTo($this->getJson(), $node, $text);
    }

    /**
     * Validate the JSON `nodes` are equal to `text`.
     *
     * @throws JsonException
     */
    #[Then('the JSON nodes should be equal to:')]
    #[Then('/^the JSON nodes should be equal to \(if path not empty\):$/')]
    public function theJsonNodesShouldBeEqualTo(TableNode $tableNode): void
    {
        foreach ($tableNode->getRowsHash() as $node => $text) {
            if ('' === $node) {
                continue;
            }

            if (!\is_string($text)) {
                self::fail(\sprintf('Expected text for node %s to be a string, %s given', $node, \gettype($text)));
            }

            $this->theJsonNodeShouldBeEqualTo($node, $text);
        }
    }

    /**
     * Validate the JSON property `node` match the given pattern.
     *
     * @throws JsonException
     */
    #[Then('the JSON node :node should match :pattern')]
    public function theJsonNodeShouldMatch(string $node, string $pattern): void
    {
        $this->jsonPropertyShouldMatch($this->getJson(), $node, $pattern);
    }

    /**
     * Validate the JSON property `node` is null.
     *
     * @throws JsonException
     */
    #[Then('the JSON node :node should be null')]
    public function theJsonNodeShouldBeNull(string $node): void
    {
        $this->jsonPropertyShouldBeNull($this->getJson(), $node);
    }

    /**
     * Validate the JSON property `node` is not null.
     *
     * @throws JsonException
     */
    #[Then('the JSON node :node should not be null')]
    public function theJsonNodeShouldNotBeNull(string $node): void
    {
        $this->jsonPropertyShouldNotBeNull($this->getJson(), $node);
    }

    /**
     * Validate the JSON property `node` is true.
     *
     * @throws JsonException
     */
    #[Then('the JSON node :node should be true')]
    public function theJsonNodeShouldBeTrue(string $node): void
    {
        $this->jsonPropertyShouldBeTrue($this->getJson(), $node);
    }

    /**
     * Validate the JSON property `node` is false.
     *
     * @throws JsonException
     */
    #[Then('the JSON node :node should be false')]
    public function theJsonNodeShouldBeFalse(string $node): void
    {
        $this->jsonPropertyShouldBeFalse($this->getJson(), $node);
    }

    /**
     * Validate the JSON property `node` is equal, as a string, to `text`.
     *
     * @throws JsonException
     * @throws Exception
     */
    #[Then('the JSON node :node should be equal to the string :text')]
    public function theJsonNodeShouldBeEqualToTheString(string $node, string $text): void
    {
        $this->jsonPropertyShouldBeEqualTo($this->getJson(), $node, $text);
    }

    /**
     * Validate the JSON property `node` is equal, as a number, to `number`.
     *
     * @throws JsonException
     * @throws Exception
     */
    #[Then('the JSON node :node should be equal to the number :number')]
    public function theJsonNodeShouldBeEqualToTheNumber(string $node, float|int $number): void
    {
        $this->jsonPropertyShouldBeEqualTo($this->getJson(), $node, $number);
    }

    /**
     * Validate the JSON property `node` has `count` children.
     */
    #[Then('the JSON node :node should have :count element(s)')]
    public function theJsonNodeShouldHaveElements(string $node, int $count): void
    {
        $this->jsonPropertyShouldHaveElements($this->getJson(), $node, $count);
    }

    /**
     * Validate the JSON property `node` contains `text`.
     */
    #[Then('the JSON node :node should contain :text')]
    public function theJsonNodeShouldContain(string $node, string $text): void
    {
        $this->jsonPropertyShouldContains($this->getJson(), $node, $text);
    }

    /**
     * Validate the JSON properties `nodes` contains `text`.
     */
    #[Then('the JSON nodes should contain:')]
    public function theJsonNodesShouldContain(TableNode $tableNode): void
    {
        foreach ($tableNode->getRowsHash() as $node => $text) {
            if ('' === $node) {
                continue;
            }

            if (!\is_string($text)) {
                self::fail(\sprintf('Expected text for node %s to be a string, %s given', $node, \gettype($text)));
            }

            $this->theJsonNodeShouldContain($node, $text);
        }
    }

    /**
     * Validate the JSON propert `node` does not contain `text`.
     */
    #[Then('the JSON node :node should not contain :text')]
    public function theJsonNodeShouldNotContain(string $node, string $text): void
    {
        $this->jsonPropertyShouldNotContains($this->getJson(), $node, $text);
    }

    /**
     * Validate the JSON properties `nodes` does not contain `text`.
     */
    #[Then('the JSON nodes should not contain:')]
    public function theJsonNodesShouldNotContain(TableNode $tableNode): void
    {
        foreach ($tableNode->getRowsHash() as $node => $text) {
            if ('' === $node) {
                continue;
            }

            if (!\is_string($text)) {
                self::fail(\sprintf('Expected text for node %s to be a string, %s given', $node, \gettype($text)));
            }

            $this->theJsonNodeShouldNotContain($node, $text);
        }
    }

    /**
     * Validate the JSON property `node` exists.
     */
    #[Then('the JSON node :name should exist')]
    public function theJsonNodeShouldExist(string $node): void
    {
        $this->jsonPropertyShouldExist($this->getJson(), $node);
    }

    /**
     * Validate the JSON property `node` does not exist.
     */
    #[Then('the JSON node :name should not exist')]
    public function theJsonNodeShouldNotExist(string $node): void
    {
        $this->jsonPropertyShouldNotExist($this->getJson(), $node);
    }

    /**
     * Validate the JSON is validated by `schema`.
     */
    #[Then('the JSON should be valid according to this schema:')]
    public function theJsonShouldBeValidAccordingToThisSchema(PyStringNode $pyStringNode): void
    {
        $this->jsonShouldBeValid($this->getJson(), new JsonSchema($pyStringNode));
    }

    /**
     * Validate the JSON is not validated by `schema`.
     *
     * @throws JsonException
     */
    #[Then('the JSON should be invalid according to this schema:')]
    public function theJsonShouldBeInvalidAccordingToThisSchema(PyStringNode $pyStringNode): void
    {
        $this->jsonShouldNotBeValid($this->getJson(), new JsonSchema($pyStringNode));
    }

    /**
     * Validate the JSON is validated by schema in file `filename`.
     *
     * @throws JsonException
     */
    #[Then('the JSON should be valid according to the schema :filename')]
    public function theJsonShouldBeValidAccordingToTheSchema(string $filename): void
    {
        $this->checkSchemaFile($filename);
        $this->jsonShouldBeValid(
            $this->getJson(),
            new JsonSchema(
                new PyStringNode([(string) \file_get_contents($filename)], 0),
                'file://' . \str_replace(DIRECTORY_SEPARATOR, '/', (string) \realpath($filename)),
            ),
        );
    }

    /**
     * Validate the JSON is not validated by schema in file `filename`.
     */
    #[Then('the JSON should be invalid according to the schema :filename')]
    public function theJsonShouldBeInvalidAccordingToTheSchema(string $filename): void
    {
        $this->checkSchemaFile($filename);
        $this->jsonShouldNotBeValid(
            $this->getJson(),
            new JsonSchema(
                new PyStringNode([(string) \file_get_contents($filename)], 0),
                'file://' . \str_replace(DIRECTORY_SEPARATOR, '/', (string) \realpath($filename)),
            ),
        );
    }

    /**
     * Validate the JSON is validated by `schemaName` from swagger in file `filename`.
     *
     * @throws JsonException
     */
    #[Then('the JSON should be valid according to swagger :dumpPath dump schema :schemaName')]
    public function theJsonShouldBeValidAccordingToTheSwaggerSchema(string $dumpPath, string $schemaName): void
    {
        $this->checkSchemaFile($dumpPath);

        $dumpJson = (string) \file_get_contents($dumpPath);
        $schemas = \json_decode($dumpJson, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($schemas);
        self::assertArrayHasKey('definitions', $schemas);
        self::assertIsArray($schemas['definitions']);
        self::assertArrayHasKey($schemaName, $schemas['definitions']);
        $definition = \json_encode($schemas['definitions'][$schemaName], JSON_THROW_ON_ERROR);
        $this->jsonShouldBeValid(
            $this->getJson(),
            new JsonSchema(
                new PyStringNode([$definition], 0),
            ),
        );
    }

    /**
     * Validate the JSON is not validated by `schemaName` from swagger in file `filename`.
     *
     * @throws JsonException
     */
    #[Then('the JSON should not be valid according to swagger :dumpPath dump schema :schemaName')]
    public function theJsonShouldNotBeValidAccordingToTheSwaggerSchema(string $dumpPath, string $schemaName): void
    {
        $this->checkSchemaFile($dumpPath);

        $dumpJson = (string) \file_get_contents($dumpPath);
        $schemas = \json_decode($dumpJson, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($schemas);
        self::assertArrayHasKey('definitions', $schemas);
        self::assertIsArray($schemas['definitions']);
        self::assertArrayHasKey($schemaName, $schemas['definitions']);
        $definition = \json_encode($schemas['definitions'][$schemaName], JSON_THROW_ON_ERROR);
        $this->jsonShouldNotBeValid(
            $this->getJson(),
            new JsonSchema(
                new PyStringNode([$definition], 0),
            ),
        );
    }

    /**
     * Validate that the whole JSON is equal to the given `content`.
     *
     * @throws JsonException
     */
    #[Then('the JSON should be equal to:')]
    public function theJsonShouldBeEqualTo(PyStringNode $content): void
    {
        $actual = $this->getJson();

        $json = new Json($content);

        self::assertEquals(
            (string) $json,
            (string) $actual,
            "The json is equal to:\n" . $actual->encode(),
        );
    }

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
     * Validate that the date in JSON property `node` is equal to `expected`.
     *
     * @throws DateMalformedStringException
     * @throws JsonException
     */
    #[Given('the Date in JSON node :node should be equal to :expected')]
    public function theDateInJSONNodeShouldBeEqualTo(string $node, string $expected): void
    {
        $this->jsonPropertyDateShouldBeEqualTo($this->getJson(), $node, $expected);
    }

    /**
     * Main function for jsonpath steps.
     *
     * @throws JsonException
     */
    #[Then('the JSON nodes matching :nodeSelector should :operator value :expected')]
    public function theJsonNodesMatchingShould(string $nodeSelector, string $operator, int|string $expected): void
    {
        match ($operator) {
            'be equal to' => $this->theMatchingJsonNodeShouldBeEqualTo($nodeSelector, $expected),
            'be greater than' => $this->theMatchingJsonNodeShouldBe($nodeSelector, 'greater', $expected),
            'be greater than or equal to' => $this->theMatchingJsonNodeShouldBe(
                $nodeSelector,
                'greater or equal',
                $expected,
            ),
            'be less than' => $this->theMatchingJsonNodeShouldBe($nodeSelector, 'less', $expected),
            'be less than or equal to' => $this->theMatchingJsonNodeShouldBe($nodeSelector, 'less or equal', $expected),
            'be between' => $this->theMatchingJsonNodeValueShouldBeBetween($nodeSelector, (string) $expected),
            'be in' => $this->theMatchingJsonNodeValueShouldBeIn($nodeSelector, (string) $expected),
            'contain' => $this->theMatchingJsonNodeShouldContain($nodeSelector, $expected),
            'not be equal to' => $this->theMatchingJsonNodeShouldNotBeEqualTo($nodeSelector, $expected),
            'not be in' => $this->theMatchingJsonNodeValueShouldNotBeIn($nodeSelector, (string) $expected),
            'not contain' => $this->theMatchingJsonNodeShouldNotContain($nodeSelector, $expected),
            default => throw new RuntimeException('Unknown operator'),
        };
    }

    /**
     * Validate nodes matching JSON property `nodeSelector` are not equal to `expected`.
     *
     * @throws JSONPathException
     * @throws JsonException
     */
    public function theMatchingJsonNodeShouldNotBeEqualTo(string $nodeSelector, mixed $expected): void
    {
        $nodeModifier = $this->nodeModifierLocator->getFor($nodeSelector, $expected);

        if ($nodeModifier instanceof NodeModifierInterface) {
            $expected = $nodeModifier->getProcessedValue($expected);
            $nodeSelector = $nodeModifier->getPathCleaned($nodeSelector);
        }

        $values = $this->getValues($nodeSelector);

        foreach ($values as $index => $value) {
            self::assertNotEquals(
                $value,
                $expected,
                \sprintf(
                    'The node at index %d has value %s which is equal to %s',
                    $index,
                    \json_encode($value, JSON_THROW_ON_ERROR),
                    \json_encode($expected, JSON_THROW_ON_ERROR),
                ),
            );
        }
    }

    /**
     * Validate nodes matching JSON property `nodeSelector` are not included in `expectedJson` array.
     */
    public function theMatchingJsonNodeValueShouldNotBeIn(string $nodeSelector, string $expectedJson): void
    {
        $values = $this->getValues($nodeSelector);
        $expected = \json_decode($expectedJson, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($expected);

        foreach ($values as $index => $value) {
            self::assertNotContains(
                $value,
                $expected,
                \sprintf(
                    'The node at index %d has value %s which is part of %s',
                    $index,
                    \json_encode($value, JSON_THROW_ON_ERROR),
                    \json_encode($expected, JSON_THROW_ON_ERROR),
                ),
            );
        }
    }

    /**
     * Validate nodes matching JSON property `nodeSelector` does not contains a specific value.
     */
    public function theMatchingJsonNodeShouldNotContain(string $nodeSelector, int|string|null $expected): void
    {
        $nodeModifier = $this->nodeModifierLocator->getFor($nodeSelector, $expected);

        if ($nodeModifier instanceof NodeModifierInterface) {
            $expected = $nodeModifier->getProcessedValue($expected);
            $nodeSelector = $nodeModifier->getPathCleaned($nodeSelector);
        }

        $values = $this->getValues($nodeSelector);

        foreach ($values as $index => $value) {
            if (!\is_array($value)) {
                self::assertNotContains(
                    $expected,
                    $values,
                    \sprintf(
                        'The node at index %d has value %s which contains %s',
                        $index,
                        \json_encode($value, JSON_THROW_ON_ERROR),
                        \json_encode($expected, JSON_THROW_ON_ERROR),
                    ),
                );

                return;
            }

            self::assertNotContains(
                $expected,
                $value,
                \sprintf(
                    'The node at index %d has value %s which contains %s',
                    $index,
                    \json_encode($value, JSON_THROW_ON_ERROR),
                    \json_encode($expected, JSON_THROW_ON_ERROR),
                ),
            );
        }
    }

    /**
     * Validate nodes matching JSON property `nodeSelector` are equal to `expected`.
     */
    public function theMatchingJsonNodeShouldBeEqualTo(string $nodeSelector, mixed $expected): void
    {
        $nodeModifier = $this->nodeModifierLocator->getFor($nodeSelector, $expected);

        if ($nodeModifier instanceof NodeModifierInterface) {
            $expected = $nodeModifier->getProcessedValue($expected);
            $nodeSelector = $nodeModifier->getPathCleaned($nodeSelector);
        }

        $values = $this->getValues($nodeSelector);

        foreach ($values as $index => $value) {
            self::assertEquals(
                $value,
                $expected,
                \sprintf(
                    'The node at index %d has value %s which is different from %s',
                    $index,
                    \json_encode($value),
                    \json_encode($expected),
                ),
            );
        }
    }

    /**
     * Validate nodes matching JSON property `nodeSelector` are validated with `operator` and `expected` value.
     */
    public function theMatchingJsonNodeShouldBe(string $nodeSelector, string $operator, int|string $expected): void
    {
        $nodeModifier = $this->nodeModifierLocator->getFor($nodeSelector, $expected);

        if ($nodeModifier instanceof NodeModifierInterface) {
            $expected = $nodeModifier->getProcessedValue($expected);
            $nodeSelector = $nodeModifier->getPathCleaned($nodeSelector);
        }

        $values = $this->getValues($nodeSelector);

        foreach ($values as $index => $value) {
            $errorMessage = \sprintf(
                'The node at index %d has value %s, which is not %s than %s',
                $index,
                $this->scalarToString($value),
                $operator,
                $this->scalarToString($expected),
            );
            match ($operator) {
                'greater' => self::assertGreaterThan(
                    $expected,
                    $value,
                    $errorMessage,
                ),
                'greater or equal' => self::assertGreaterThanOrEqual(
                    $expected,
                    $value,
                    $errorMessage,
                ),
                'less' => self::assertLessThan(
                    $expected,
                    $value,
                    $errorMessage,
                ),
                'less or equal' => self::assertLessThanOrEqual(
                    $expected,
                    $value,
                    $errorMessage,
                ),
                default => self::fail('Unknown operator'),
            };
        }
    }

    /**
     * Validate nodes matching JSON property `nodeSelector` are contained between `min` and `max`.
     */
    public function theMatchingJsonNodeValueShouldBeBetween(
        string $nodeSelector,
        string $expectedJson,
    ): void {
        $expected = \json_decode($expectedJson, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($expected);
        self::assertArrayHasKey(0, $expected);
        self::assertArrayHasKey(1, $expected);
        $expectedFrom = $expected[0];
        $expectedTo = $expected[1];
        $nodeModifier = $this->nodeModifierLocator->getFor($nodeSelector, $expectedFrom);

        if (!$nodeModifier instanceof NodeModifierInterface) {
            throw new RuntimeException('NodeModifier not found');
        }

        $from = $nodeModifier->getProcessedValue($expectedFrom);
        $to = $nodeModifier->getProcessedValue($expectedTo);
        $nodeSelector = $nodeModifier->getPathCleaned($nodeSelector);
        $values = $this->getValues($nodeSelector);

        foreach ($values as $index => $value) {
            $value = $nodeModifier->getProcessedValue($value);
            $message = \sprintf(
                'The node at index %d has value %s, which is not between %s and %s',
                $index,
                $this->scalarToString($value),
                $this->scalarToString($expectedFrom),
                $this->scalarToString($expectedTo),
            );
            self::assertGreaterThanOrEqual($from, $value, $message);
            self::assertLessThanOrEqual($to, $value, $message);
        }
    }

    /**
     * Validate nodes matching JSON property `nodeSelector` are included in `expectedJson` array.
     */
    public function theMatchingJsonNodeValueShouldBeIn(string $nodeSelector, string $expectedJson): void
    {
        $values = $this->getValues($nodeSelector);
        $expected = \json_decode($expectedJson, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($expected);

        foreach ($values as $index => $value) {
            self::assertContains(
                $value,
                $expected,
                \sprintf(
                    'The node at index %d has value %s which is not part of %s',
                    $index,
                    \json_encode($value, JSON_THROW_ON_ERROR),
                    \json_encode($expected, JSON_THROW_ON_ERROR),
                ),
            );
        }
    }

    /**
     * Validate nodes matching JSON property `nodeSelector` have `expected` children.
     */
    #[Then('the JSON nodes matching :nodeSelector should have :expected children')]
    public function theMatchingJsonNodeShouldHaveChildrenCount(string $nodeSelector, int $expected): void
    {
        $values = $this->getValues($nodeSelector);

        foreach ($values as $index => $value) {
            self::assertIsArray($value);
            self::assertCount(
                $expected,
                $value,
                \sprintf(
                    'The node at index %d has %d children, but should have %d',
                    $index,
                    \count($value),
                    $expected,
                ),
            );
        }
    }

    /**
     * Checks that JSON node matching JMESPath is present a certain amount of time in the json with given value.
     */
    #[Then('the JSON nodes matching :nodeSelector with value :value should be found at least :expectedCount time')]
    public function theMatchingJsonNodeValueShouldFoundAtLeast(
        string $nodeSelector,
        string $expectedCount,
        string $value,
    ): void {
        $values = $this->getValues($nodeSelector);

        $count = 0;

        foreach ($values as $expectedValue) {
            if ($expectedValue === $value) {
                ++$count;
            }
        }

        self::assertGreaterThanOrEqual(
            $expectedCount,
            $count,
            \sprintf(
                'There is only %d count for value "%s" in node "%s" in the JSON (expect at least: %d)',
                $count,
                $value,
                $nodeSelector,
                $expectedCount,
            ),
        );
    }

    /**
     * Checks that JSON node matching JMESPath is present a certain amount of time in the json with given value.
     */
    #[Then('the JSON nodes matching :nodeSelector with value :value should be found exactly :expectedCount time')]
    public function theMatchingJsonNodeValueShouldFoundExactly(
        string $nodeSelector,
        int $expectedCount,
        string $value,
    ): void {
        $nodeModifier = $this->nodeModifierLocator->getFor($nodeSelector, $value);

        if ($nodeModifier instanceof NodeModifierInterface) {
            $value = $nodeModifier->getProcessedValue($value);
            $nodeSelector = $nodeModifier->getPathCleaned($nodeSelector);
        }

        $values = $this->getValues($nodeSelector);

        $count = 0;

        foreach ($values as $expectedValue) {
            if ($expectedValue === $value) {
                ++$count;
            }
        }

        self::assertEquals(
            $expectedCount,
            $count,
            \sprintf(
                'There is only %d count for value "%s" in node "%s" in the JSON (expect exactly: %d)',
                $count,
                $this->scalarToString($value),
                $nodeSelector,
                $expectedCount,
            ),
        );
    }

    /**
     * Checks that JSON node should exist in each object.
     */
    #[Then('the JSON nodes matching :nodeSelector should exist')]
    public function theMatchingJsonNodeShouldExist(string $nodeSelector): void
    {
        $originalNodeSelector = $nodeSelector;
        $values = \count($this->getValues($nodeSelector));

        // Check if the string contains a dot
        if (\str_contains($nodeSelector, '.')) {
            $length = \strrpos($nodeSelector, '.');

            if (false === $length) {
                throw new RuntimeException('The nodeSelector is invalid');
            }

            // Remove the last segment after the last dot
            $nodeSelector = \substr($nodeSelector, 0, $length);
        }

        $fullvalues = \count($this->getValues($nodeSelector));

        self::assertEquals(
            $values,
            $fullvalues,
            \sprintf(
                'There is only %d count for node "%s" in the JSON (expect exactly: %d)',
                $values,
                $originalNodeSelector,
                $fullvalues,
            ),
        );
    }

    /**
     * Validate nodes matching JSON property `nodeSelector` contains a specific value.
     */
    public function theMatchingJsonNodeShouldContain(string $nodeSelector, int|string|null $expected): void
    {
        $nodeModifier = $this->nodeModifierLocator->getFor($nodeSelector, $expected);

        if ($nodeModifier instanceof NodeModifierInterface) {
            $expected = $nodeModifier->getProcessedValue($expected);
            $nodeSelector = $nodeModifier->getPathCleaned($nodeSelector);
        }

        $values = $this->getValues($nodeSelector);

        foreach ($values as $index => $value) {
            if (!\is_array($value)) {
                self::assertContains(
                    $expected,
                    $values,
                    \sprintf(
                        'The node at index %d has value %s which does not contain %s',
                        $index,
                        \json_encode($value, JSON_THROW_ON_ERROR),
                        \json_encode($expected, JSON_THROW_ON_ERROR),
                    ),
                );

                return;
            }

            self::assertContains(
                $expected,
                $value,
                \sprintf(
                    'The node at index %d has value %s which does not contain %s',
                    $index,
                    \json_encode($value, JSON_THROW_ON_ERROR),
                    \json_encode($expected, JSON_THROW_ON_ERROR),
                ),
            );
        }
    }

    /**
     * Debug scenario to display last JSON response.
     */
    #[Then('print last JSON response')]
    public function printLastJsonResponse(): void
    {
        echo $this->getJson()
            ->encode()
        ;
    }

    /** @throws JsonException */
    private function scalarToString(mixed $value): string
    {
        if (\is_scalar($value) || null === $value) {
            return (string) $value;
        }

        return \json_encode($value, JSON_THROW_ON_ERROR) ?: '';
    }

    public function checkSchemaFile(string $filename): void
    {
        self::assertTrue(\is_file($filename), "The JSON schema doesn't exist");
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
