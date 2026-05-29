<?php

declare(strict_types=1);

namespace Erpify\Tests\Behat\Context\Json;

use Behat\Gherkin\Node\PyStringNode;
use Behat\Step\Then;
use Erpify\Tests\Behat\Context\Abstraction\AbstractContext;
use Erpify\Tests\Behat\Support\Json\JsonResponseAwareTrait;
use Erpify\Tests\Behat\Support\Json\JsonSchema;
use Erpify\Tests\Behat\Support\PostProcess\JsonToolTrait;
use JsonException;

use const DIRECTORY_SEPARATOR;

/**
 * Validates the last HTTP response against JSON schemas (inline, file, or Swagger dump definitions).
 */
class JsonSchemaContext extends AbstractContext
{
    use JsonResponseAwareTrait;
    use JsonToolTrait;

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

    public function checkSchemaFile(string $filename): void
    {
        self::assertTrue(\is_file($filename), "The JSON schema doesn't exist");
    }
}
