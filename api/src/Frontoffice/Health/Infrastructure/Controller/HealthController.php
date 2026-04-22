<?php

declare(strict_types=1);

namespace Erpify\Frontoffice\Health\Infrastructure\Controller;

use DateTimeImmutable;
use DateTimeInterface;
use Erpify\Shared\Application\UseCase\Result;
use Erpify\Shared\Infrastructure\Http\Responder\ResponderInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final readonly class HealthController
{
    public function __construct(
        private ResponderInterface $responder,
    ) {
    }

    #[Route('/health', name: 'frontoffice_health', methods: ['GET'])]
    public function __invoke(): Response
    {
        return $this->responder->respond(Result::ok([
            'status' => 'ok',
            'service' => 'Front office',
            'datetime' => (new DateTimeImmutable())->format(DateTimeInterface::ATOM),
        ]));
    }
}
