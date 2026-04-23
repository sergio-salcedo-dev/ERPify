<?php

declare(strict_types=1);

namespace Erpify\Tests\Behat\State;

use Erpify\Tests\Behat\Support\Transport\HttpResponse;

class HttpResponseContainer
{
    private ?HttpResponse $httpResponse = null;

    public function store(HttpResponse $httpResponse): self
    {
        $this->httpResponse = $httpResponse;

        return $this;
    }

    public function getResult(): ?HttpResponse
    {
        return $this->httpResponse;
    }
}
