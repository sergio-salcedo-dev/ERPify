<?php

declare(strict_types=1);

namespace Erpify\Frontoffice\Mercure\Infrastructure\Controller;

use DateTimeImmutable;
use DateTimeInterface;
use Erpify\Frontoffice\Mercure\Domain\MercureDemoTopic;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/mercure/publish-demo', name: 'frontoffice_mercure_publish_demo', methods: ['POST'])]
final readonly class MercurePublishDemoController
{
    public function __construct(
        private HubInterface $hub,
        #[Autowire('%kernel.environment%')]
        private string $environment,
    ) {
    }

    public function __invoke(): JsonResponse
    {
        if ('dev' !== $this->environment) {
            throw new NotFoundHttpException();
        }

        $payload = [
            'message' => 'Mercure demo publish',
            'at' => new DateTimeImmutable()->format(DateTimeInterface::ATOM),
        ];

        $this->hub->publish(new Update(
            MercureDemoTopic::URI,
            json_encode($payload, JSON_THROW_ON_ERROR),
            false,
        ));

        return new JsonResponse(['published' => true]);
    }
}
