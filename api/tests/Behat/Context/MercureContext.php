<?php

declare(strict_types=1);

namespace Erpify\Tests\Behat\Context;

use Behat\Hook\BeforeScenario;
use Behat\Step\Given;
use Behat\Step\Then;
use Erpify\Tests\Behat\Context\Abstraction\AbstractContext;
use Erpify\Tests\Behat\Support\Json\Json;
use Erpify\Tests\Behat\Support\Mercure\RecordingHub;
use Erpify\Tests\Behat\Support\PostProcess\JsonToolTrait;
use Erpify\Tests\Behat\Support\RecordedItemSelector;
use JsonException;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;

/**
 * Asserts the realtime {@see Update}s that the bank realtime publisher handler
 * (`Erpify\Backoffice\Bank\Infrastructure\Messenger\RefreshRealtimeOnBankChanged`) publishes when a
 * bank event is consumed — the structured downstream sink, read off the recording hub
 * ({@see RecordingHub}) bound in `services_test.yaml`. Topics are the addressing (collection +
 * per-bank), `getData()` is the JSON payload, inspected with the shared {@see JsonToolTrait} dot-path.
 *
 * The created/updated payload is `{type, bank:{…}}` (assert `bank.*`); the delete payload is
 * `{type:"bank.deleted", id}` with no `bank` object (assert the top-level `id`).
 *
 * @SuppressWarnings("PHPMD.TooManyPublicMethods")
 */
final class MercureContext extends AbstractContext
{
    use JsonToolTrait;

    private ?int $selectedIndex = null;

    public function __construct(private readonly HubInterface $hub)
    {
    }

    #[BeforeScenario]
    #[Given('I reset the mercure context')]
    public function reset(): void
    {
        // Guard: a missing recording-hub bind silently reintroduces the ~5s live-hub hang on consume.
        self::assertInstanceOf(
            RecordingHub::class,
            $this->hub,
            'The Mercure HubInterface is not the recording test double; bind RecordingHub in services_test.yaml',
        );

        RecordingHub::reset();
        $this->selectedIndex = null;
    }

    #[Then(':number Mercure update was published')]
    #[Then(':number Mercure updates were published')]
    public function mercureUpdatesWerePublished(int $number): void
    {
        $count = \count(RecordingHub::updates());

        self::assertSame(
            $number,
            $count,
            \sprintf('%d Mercure updates were published, but %d was expected', $count, $number),
        );
    }

    #[Then('I get the published Mercure update number :value')]
    public function selectMercureUpdate(int $value): void
    {
        $this->selectedIndex = $value - 1;
    }

    #[Then('The Mercure update topic :number should be equal to :value')]
    public function topicShouldBeEqualTo(int $number, string $value): void
    {
        $topics = $this->selectedUpdate()->getTopics();
        $topic = $topics[$number - 1] ?? null;

        self::assertSame(
            $value,
            $topic,
            \sprintf('Topic %d is "%s" but "%s" was expected', $number, \is_string($topic) ? $topic : 'null', $value),
        );
    }

    #[Then('The Mercure update should have :count topic')]
    #[Then('The Mercure update should have :count topics')]
    public function shouldHaveTopics(int $count): void
    {
        self::assertCount($count, $this->selectedUpdate()->getTopics());
    }

    /**
     * @throws JsonException
     */
    #[Then('The Mercure update property :property should be equal to :value')]
    public function propertyShouldBeEqualTo(string $property, string $value): void
    {
        $this->jsonPropertyShouldBeEqualTo($this->selectedUpdateJson(), $property, $value);
    }

    /**
     * @throws JsonException
     */
    #[Then('The Mercure update property :property should be null')]
    public function propertyShouldBeNull(string $property): void
    {
        $this->jsonPropertyShouldBeNull($this->selectedUpdateJson(), $property);
    }

    /**
     * @throws JsonException
     */
    #[Then('The Mercure update property :property should not be null')]
    public function propertyShouldNotBeNull(string $property): void
    {
        $this->jsonPropertyShouldNotBeNull($this->selectedUpdateJson(), $property);
    }

    /**
     * @throws JsonException
     */
    #[Then('The Mercure update property :property should have :count element')]
    #[Then('The Mercure update property :property should have :count elements')]
    public function propertyShouldHaveElements(string $property, int $count): void
    {
        $this->jsonPropertyShouldHaveElements($this->selectedUpdateJson(), $property, $count);
    }

    /**
     * @throws JsonException
     */
    #[Then('The Mercure update property :property should exist')]
    public function propertyShouldExist(string $property): void
    {
        $this->jsonPropertyShouldExist($this->selectedUpdateJson(), $property);
    }

    /**
     * @throws JsonException
     */
    #[Then('The Mercure update property :property should not exist')]
    public function propertyShouldNotExist(string $property): void
    {
        $this->jsonPropertyShouldNotExist($this->selectedUpdateJson(), $property);
    }

    /**
     * @throws JsonException
     */
    private function selectedUpdateJson(): Json
    {
        return new Json($this->selectedUpdate()->getData());
    }

    private function selectedUpdate(): Update
    {
        return (new RecordedItemSelector())->select(RecordingHub::updates(), $this->selectedIndex, 'Mercure update');
    }
}
