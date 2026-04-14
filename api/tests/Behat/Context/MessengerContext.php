<?php

declare(strict_types=1);

namespace Erpify\Tests\Behat\Context;

use Behat\Behat\Context\Context;
use Behat\Step\Then;
use Behat\Step\When;
use JsonException;
use RuntimeException;

/**
 * Steps for Symfony Messenger async processing and Mailpit-backed notifications.
 */
final class MessengerContext implements Context
{
    use MessengerBehatTrait;

    #[When('I process pending async messenger messages')]
    public function processPendingAsyncMessengerMessages(): void
    {
        $this->consumePendingAsyncMessengerMessages();
    }

    /**
     * @throws JsonException
     */
    #[Then('the async messenger transport should be empty')]
    public function assertAsyncMessengerTransportEmpty(): void
    {
        $this->assertMessengerTransportCount('async', 0);
    }

    /**
     * @throws JsonException
     */
    #[Then('the messenger failed transport should be empty')]
    public function assertMessengerFailedTransportEmpty(): void
    {
        $this->assertMessengerTransportCount('failed', 0);
    }

    #[Then('the last bank created notification email should mention event :eventName')]
    public function assertLastNotificationEmailMentionsEvent(string $eventName): void
    {
        $this->assertDomainEventNameFormat($eventName);
        $base = \rtrim(\getenv('MAILPIT_API_BASE_URL') ?: 'http://mailpit:8025', '/');

        $list = $this->httpGetJson($base . '/api/v1/messages?limit=1');
        $messages = $list['messages'] ?? null;

        if (!\is_array($messages) || [] === $messages) {
            throw new RuntimeException('Mailpit has no messages (is MAILER_DSN pointing at Mailpit and was the async consumer run?).');
        }

        $id = $messages[0]['ID'] ?? $messages[0]['Id'] ?? null;

        if (!\is_string($id) || '' === $id) {
            throw new RuntimeException(\sprintf('Unexpected Mailpit messages list payload: %s', \json_encode($list, JSON_THROW_ON_ERROR)));
        }

        $detail = $this->httpGetJson($base . '/api/v1/message/' . \rawurlencode($id));
        $text = $detail['Text'] ?? null;

        if (!\is_string($text)) {
            throw new RuntimeException('Mailpit message detail has no Text body.');
        }

        if (!\str_contains($text, $eventName)) {
            throw new RuntimeException(
                \sprintf("Latest email body does not contain %s:\n%s", $eventName, $text),
            );
        }
    }
}
