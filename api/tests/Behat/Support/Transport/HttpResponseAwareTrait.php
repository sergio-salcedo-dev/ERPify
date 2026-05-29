<?php

declare(strict_types=1);

namespace Erpify\Tests\Behat\Support\Transport;

use Symfony\Component\HttpFoundation\Response;

/**
 * Shared accessor for the last stored HTTP response, used by the HTTP request/response
 * Behat contexts. Consuming contexts must constructor-inject an `HttpResponseContainer`
 * exposed as `$this->httpResponseContainer`.
 */
trait HttpResponseAwareTrait
{
    public function getLastResponse(): Response
    {
        $result = $this->httpResponseContainer->getResult();
        self::assertNotNull($result, 'You cannot call this method without a request made previously');

        /** @var Response */
        return $result->getValue();
    }

    /**
     * @return array<string, string|null>
     */
    public function getLastResponseHeaders(): array
    {
        $output = [];

        /**
         * @var string            $name
         * @var list<string|null> $values
         */
        foreach ($this->getLastResponse()->headers->all() as $name => $values) {
            $output[$name] = $values[0] ?? null;
        }

        return $output;
    }
}
