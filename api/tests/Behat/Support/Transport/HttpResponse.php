<?php

declare(strict_types=1);

namespace Erpify\Tests\Behat\Support\Transport;

use Erpify\Tests\Behat\Support\Json\Json;
use GuzzleHttp\Psr7\Response as GuzzleResponse;
use JsonException;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;
use UnexpectedValueException;

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

            // Every streamed line is expected to be a JSON object/array that contributes to the
            // accumulated payload. A line that decodes to a bare scalar is malformed stream data;
            // silently dropping it would yield a misleadingly-empty body, so fail loudly instead.
            if (!\is_array($decoded)) {
                throw new UnexpectedValueException(\sprintf(
                    'Streamed response line "%s" did not decode to a JSON object or array.',
                    $item,
                ));
            }

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
        $content = match (true) {
            \is_string($this->value) => $this->value,
            $this->value instanceof StreamedResponse => $this->getStreamedResponse()->getContent(),
            $this->value instanceof SymfonyResponse => $this->value->getContent(),
            default => $this->value->getBody()->getContents(),
        };

        return new Json($content);
    }
}
