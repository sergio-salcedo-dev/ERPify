<?php

declare(strict_types=1);

namespace Erpify\Tests\Behat\Assertion\Json;

use JsonException;
use JsonSchema\SchemaStorage;
use JsonSchema\Validator;
use RuntimeException;
use Stringable;

class JsonSchema extends Json
{
    public function __construct(
        Stringable $content,
        private readonly ?string $uri = null,
    ) {
        parent::__construct($content);
    }

    public function resolve(SchemaStorage $schemaStorage): self
    {
        if (!$this->hasUri()) {
            return $this;
        }

        $this->content = $schemaStorage->resolveRef((string) $this->uri);

        return $this;
    }

    /**
     * @throws JsonException
     */
    public function validate(Json $json, Validator $validator): bool
    {
        $validator->check($json->getContent(), $this->getContent());

        if (!$validator->isValid()) {
            $msg = 'JSON does not validate. Violations:' . PHP_EOL;

            foreach ($validator->getErrors() as $error) {
                $msg .= \sprintf('  - [%s] %s' . PHP_EOL, $error['property'], $error['message']);
            }

            throw new RuntimeException($msg);
        }

        return true;
    }

    private function hasUri(): bool
    {
        return null !== $this->uri;
    }
}
