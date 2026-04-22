<?php

declare(strict_types=1);

namespace Erpify\Tests\Behat\Assertion\Json;

use JsonException;
use Override;
use RuntimeException;
use stdClass;
use Stringable;
use Symfony\Component\PropertyAccess\PropertyAccessor;

class Json implements Stringable
{
    protected array|stdClass $content;

    /**
     * @throws JsonException
     */
    public function __construct($content)
    {
        $this->content = $this->decode((string) $content);
    }

    /**
     * @throws JsonException
     */
    #[Override]
    public function __toString(): string
    {
        return $this->encode(false);
    }

    /**
     * @SuppressWarnings("PHPMD.BooleanArgumentFlag")
     *
     * @throws JsonException
     */
    public function getContent(bool $toArray = false): array|stdClass
    {
        if ($toArray) {
            return \json_decode(
                \json_encode($this->content, JSON_THROW_ON_ERROR),
                true,
                512,
                JSON_THROW_ON_ERROR,
            );
        }

        return $this->content;
    }

    public function read($expression, PropertyAccessor $propertyAccessor): mixed
    {
        if (\is_array($this->content)) {
            $expression = \preg_replace('/^root/', '', (string) $expression);
        }

        if (!\is_array($this->content)) {
            $expression = \preg_replace('/^root./', '', (string) $expression);
        }

        // If root asked, we return the entire content
        if ('' === \trim((string) $expression)) {
            return $this->content;
        }

        return $propertyAccessor->getValue($this->content, $expression);
    }

    /**
     * @SuppressWarnings("PHPMD.BooleanArgumentFlag")
     *
     * @throws JsonException
     */
    public function encode(bool $pretty = true): string
    {
        $flags = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;

        if ($pretty && \defined('JSON_PRETTY_PRINT')) {
            $flags |= JSON_PRETTY_PRINT;
        }

        return \json_encode($this->content, JSON_THROW_ON_ERROR | $flags);
    }

    /**
     * @throws JsonException
     */
    private function decode(string $content): array|stdClass
    {
        $result = \json_decode($content, false, 512, JSON_THROW_ON_ERROR);

        if (JSON_ERROR_NONE !== \json_last_error()) {
            throw new RuntimeException(\sprintf("The string '%s' is not valid json", $content));
        }

        return $result;
    }
}
