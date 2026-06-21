<?php

declare(strict_types=1);

namespace Erpify\Frontoffice\Health\Infrastructure\Controller;

use DateTimeInterface;
use Erpify\Shared\Clock\Domain\Clock;
use Erpify\Shared\Http\Infrastructure\Responder\ResponderInterface;
use Erpify\Shared\Kernel\Application\Result;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final readonly class HealthController
{
    public function __construct(
        private ResponderInterface $responder,
        private Clock $clock,
    ) {
    }

    #[Route('/health', name: 'frontoffice_health', methods: ['GET'])]
    public function __invoke(): Response
    {
        return $this->responder->respond(Result::ok([
            'status' => 'ok',
            'service' => 'Front office',
            'datetime' => $this->clock->now()->format(DateTimeInterface::ATOM),
        ]));
    }
}
