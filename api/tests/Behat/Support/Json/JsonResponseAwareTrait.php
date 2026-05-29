<?php

declare(strict_types=1);

namespace Erpify\Tests\Behat\Support\Json;

use Erpify\Tests\Behat\State\HttpResponseContainer;
use JsonException;

/**
 * Shared accessor for the JSON contexts split out of the former monolithic JsonContext.
 *
 * Provides the constructor dependency and turns the last stored HTTP result into a
 * {@see Json} value object, so each focused context only declares its own step methods.
 */
trait JsonResponseAwareTrait
{
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
}
