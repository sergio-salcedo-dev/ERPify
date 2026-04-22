<?php

declare(strict_types=1);

namespace Erpify\Tests\Behat\Transport;

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
