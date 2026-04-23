<?php

declare(strict_types=1);

namespace Erpify\Tests\Behat\Support\Transport;

use Erpify\Tests\Behat\Support\Json\Json;
use GuzzleHttp\Psr7\Response as GuzzleResponse;
use JsonException;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class HttpResponse
{
    public function __construct(
        private GuzzleResponse|string|SymfonyResponse $value,
        private string $streamedResponse = '',
    ) {
    }

    public function update(
        GuzzleResponse|string|SymfonyResponse $value,
        string $streamedResponse = '',
    ): self {
        $this->value = $value;
        $this->streamedResponse = $streamedResponse;

        return $this;
    }

    public function getValue(): GuzzleResponse|string|SymfonyResponse
    {
        return $this->value;
    }

    /**
     * @throws JsonException
     */
    public function getStreamedResponse(): SymfonyResponse
    {
        $data = [];
        $items = \array_filter(
            \explode("\n", $this->streamedResponse),
            static fn (?string $line): bool => null !== $line && '' !== \trim($line),
        );

        foreach ($items as $item) {
            $decoded = \json_decode($item, true, 512, JSON_THROW_ON_ERROR);
            \assert(\is_array($decoded));
            $data = \array_merge_recursive($data, $decoded);
        }

        return new SymfonyResponse(
            \json_encode($data, JSON_THROW_ON_ERROR),
            status: \is_string($this->value) ? 200 : $this->value->getStatusCode(),
        );
    }

    /**
     * @throws JsonException
     */
    public function getJson(): Json
    {
        if (\is_string($this->value)) {
            return new Json($this->value);
        }

        if ($this->value instanceof StreamedResponse) {
            return new Json($this->getStreamedResponse()->getContent());
        }

        if ($this->value instanceof SymfonyResponse) {
            return new Json($this->value->getContent());
        }

        return new Json($this->value->getBody()->getContents());
    }
}
