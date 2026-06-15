<?php

declare(strict_types=1);

namespace Erpify\Tests\Behat\Context;

use Behat\Step\Given;
use Behat\Step\Then;
use Erpify\Shared\Domain\Event\DomainEvent;
use Erpify\Tests\Behat\Context\Abstraction\AbstractContext;
use Erpify\Tests\Behat\Support\Json\Json;
use Erpify\Tests\Behat\Support\PostProcess\JsonToolTrait;
use JsonException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Handler\HandlerDescriptor;
use Symfony\Component\Messenger\Handler\HandlersLocatorInterface;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\Serializer\SerializerInterface;

/**
 * Asserts what landed on a Messenger transport, the in-memory companion to the
 * `php.lint.event-bus` gate: a scenario can prove a domain event was published to the `async`
 * outbox transport (and processed by its handlers) without reaching into SQL.
 *
 * Reads the transport by id from the test container — under `framework.test: true` the `async`
 * transport is the `in-memory://?serialize=true` double (see `config/packages/messenger.yaml`),
 * so {@see InMemoryTransport::getSent()} returns the encoded-then-decoded envelopes, exactly the
 * shape a Doctrine/AMQP transport would deliver.
 *
 * @SuppressWarnings("PHPMD.CouplingBetweenObjects")
 */
final class MessengerContext extends AbstractContext
{
    use JsonToolTrait;

    public function __construct(
        #[Autowire(service: 'test.service_container')]
        private readonly Container $container,
        #[Autowire(service: 'messenger.bus.default.messenger.handlers_locator')]
        private readonly HandlersLocatorInterface $handlersLocator,
        private readonly SerializerInterface $serializer,
    ) {
    }

    #[Given('I reset the :transportName transport')]
    public function iResetTheTransport(string $transportName): void
    {
        $this->transport($transportName)->reset();
    }

    #[Then(':count message was sent to :transportName')]
    #[Then(':count messages were sent to :transportName')]
    public function messagesWereSent(int $count, string $transportName): void
    {
        $sent = $this->transport($transportName)->getSent();

        self::assertCount(
            $count,
            $sent,
            \sprintf('%d messages were sent to "%s", but %d was expected', \count($sent), $transportName, $count),
        );
    }

    /**
     * @param class-string $messageClass
     */
    #[Then('message :number sent to :transportName is instance of :messageClass')]
    public function messageSentIsInstanceOf(int $number, string $transportName, string $messageClass): void
    {
        $message = $this->envelope($transportName, $number)->getMessage();

        self::assertInstanceOf(
            $messageClass,
            $message,
            \sprintf(
                'Message %d on "%s" is a "%s", not the expected "%s"',
                $number,
                $transportName,
                $message::class,
                $messageClass,
            ),
        );
    }

    /**
     * @throws JsonException
     */
    #[Then('message :number sent to :transportName should have field :field with value :value')]
    public function messageSentShouldHaveField(int $number, string $transportName, string $field, string $value): void
    {
        $message = $this->envelope($transportName, $number)->getMessage();

        $this->jsonPropertyShouldBeEqualTo($this->messageToJson($message), $field, $value);
    }

    /**
     * Runs the message through its registered handlers in-process — the lightweight way to assert
     * a handler's side effects (audit row, idempotency claim, mail) without spinning the worker
     * command. For the real `messenger:consume` worker loop, see {@see MessengerConsumeContext}.
     */
    #[Then('message :number sent to :transportName is processed')]
    public function messageSentIsProcessed(int $number, string $transportName): void
    {
        $envelope = $this->envelope($transportName, $number);

        $handlersRan = 0;

        /** @var HandlerDescriptor $handler */
        foreach ($this->handlersLocator->getHandlers($envelope) as $handler) {
            $handler->getHandler()($envelope->getMessage());
            ++$handlersRan;
        }

        self::assertGreaterThanOrEqual(
            1,
            $handlersRan,
            \sprintf('No handler found for message %d on transport "%s"', $number, $transportName),
        );
    }

    /**
     * @throws JsonException
     */
    private function messageToJson(object $message): Json
    {
        if ($message instanceof DomainEvent) {
            // The domain event's own canonical payload — stable and getter-independent, unlike
            // Symfony normalization of its private readonly properties.
            return new Json(\json_encode($message->toPrimitives(), JSON_THROW_ON_ERROR));
        }

        return new Json($this->serializer->serialize($message, 'json'));
    }

    private function envelope(string $transportName, int $number): Envelope
    {
        $envelope = $this->transport($transportName)->getSent()[$number - 1] ?? null;

        self::assertInstanceOf(
            Envelope::class,
            $envelope,
            \sprintf('Message %d not found on transport "%s"', $number, $transportName),
        );

        return $envelope;
    }

    private function transport(string $transportName): InMemoryTransport
    {
        $serviceId = 'messenger.transport.' . $transportName;

        self::assertTrue(
            $this->container->has($serviceId),
            \sprintf('Transport "%s" is not registered', $transportName),
        );

        $transport = $this->container->get($serviceId);

        self::assertInstanceOf(
            InMemoryTransport::class,
            $transport,
            \sprintf('Transport "%s" is not an in-memory test double', $transportName),
        );

        return $transport;
    }
}
