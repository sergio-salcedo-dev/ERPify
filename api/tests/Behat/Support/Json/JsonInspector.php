<?php

declare(strict_types=1);

namespace Erpify\Tests\Behat\Support\Json;

use Exception;
use JsonException;
use JsonSchema\SchemaStorage;
use JsonSchema\Uri\UriResolver;
use JsonSchema\Uri\UriRetriever;
use JsonSchema\Validator;
use RuntimeException;
use Symfony\Component\PropertyAccess\PropertyAccessor;

readonly class JsonInspector
{
    private PropertyAccessor $propertyAccessor;

    public function __construct(private string $evaluationMode)
    {
        $magicMethods = \defined(PropertyAccessor::class . '::DISALLOW_MAGIC_METHODS')
            ? PropertyAccessor::MAGIC_GET | PropertyAccessor::MAGIC_SET
            : false;
        $throwException = \defined(PropertyAccessor::class . '::DO_NOT_THROW')
            ? PropertyAccessor::THROW_ON_INVALID_INDEX | PropertyAccessor::THROW_ON_INVALID_PROPERTY_PATH
            : true;

        $this->propertyAccessor = new PropertyAccessor($magicMethods, $throwException);
    }

    public function evaluate(Json $json, $expression): mixed
    {
        if ('javascript' === $this->evaluationMode) {
            $expression = \str_replace('->', '.', $expression);
        }

        try {
            return $json->read($expression, $this->propertyAccessor);
        } catch (Exception $exception) {
            throw new RuntimeException(\sprintf("Failed to evaluate expression '%s'", $expression), $exception->getCode(), $exception);
        }
    }

    /**
     * @throws JsonException
     */
    public function validate(Json $json, JsonSchema $jsonSchema): bool
    {
        if (!\class_exists(Validator::class)) {
            throw new RuntimeException('Missing extension, please install dev package for "justinrainbow/json-schema"');
        }

        $validator = new Validator();

        $schemaStorage = new SchemaStorage(new UriRetriever(), new UriResolver());
        $jsonSchema->resolve($schemaStorage);

        return $jsonSchema->validate($json, $validator);
    }
}
