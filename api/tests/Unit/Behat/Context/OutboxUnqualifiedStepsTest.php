<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Behat\Context;

use Behat\Gherkin\Node\TableNode;
use Closure;
use Doctrine\ORM\EntityManagerInterface;
use Erpify\Tests\Behat\Context\OutboxContext;
use Erpify\Tests\Unit\Behat\Context\Fixtures\OutboxContextFactory;
use Erpify\Tests\Unit\Gate\Support\BehatVocabularyReader;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use stdClass;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Every outbox phrasing that names no queue refuses, and says which one to use instead.
 *
 * The phrasings stay registered rather than being deleted: reaching for one gets the canonical form
 * back instead of "step undefined", and nobody re-adds it believing it is missing. What they cannot do
 * is assert, because "the outbox" is not one queue — a read with no queue named concatenates every
 * inspectable queue into a single 1-based list, so position 1 is an `async` envelope right up until
 * `async` happens to be empty and it silently becomes a `failed` one, and a count sums queues the
 * scenario never asked about.
 *
 * A refusal that returned quietly would be worse than either, so what is pinned here is that each one
 * throws — the shape of the defect this whole change is about.
 *
 * {@see CoversNothing} because the subject is test infrastructure — `tests/` sits outside the coverage
 * allowlist, so there is no production line here to credit.
 *
 * @internal
 */
#[CoversNothing]
final class OutboxUnqualifiedStepsTest extends TestCase
{
    /**
     * Each unqualified phrasing stays registered so a scenario reaching for it is told the canonical
     * form rather than that the step does not exist — and every one of them refuses, because a
     * position or a count over the concatenation of every queue answers a question the scenario did
     * not ask. A refusal that returned quietly would be the worst of both.
     */
    #[DataProvider('provideAnUnqualifiedStepRefusesAndNamesTheQueuedFormCases')]
    public function testAnUnqualifiedStepRefusesAndNamesTheQueuedForm(Closure $step): void
    {
        $context = $this->contextHolding((object) ['bankId' => 'ACME']);

        try {
            $step($context);
        } catch (RuntimeException $runtimeException) {
            $this->assertStringContainsString('Name the queue instead:', $runtimeException->getMessage());
            $this->assertSuggestionIsADeclaredStep($runtimeException->getMessage(), 'Name the queue instead: ');

            return;
        }

        $this->fail('The unqualified phrasing returned instead of refusing.');
    }

    /**
     * @return iterable<string, array{Closure(OutboxContext): void}>
     */
    public static function provideAnUnqualifiedStepRefusesAndNamesTheQueuedFormCases(): iterable
    {
        yield 'count' => [static function (OutboxContext $c): never {
            $c->outboxEventsWereCreated(1);
        }];
        yield 'select by number' => [static function (OutboxContext $c): never {
            $c->selectEventByNumber(1);
        }];
        yield 'remove by number' => [static function (OutboxContext $c): never {
            $c->removeEventByNumber(1);
        }];
        yield 'remove by type' => [
            static function (OutboxContext $c): never {
                $c->removeEventByType(stdClass::class);
            },
        ];
        yield 'table match' => [
            static function (OutboxContext $c): never {
                $c->anOutboxEventCreatedContaining(new TableNode([['bankId', 'ACME']]));
            },
        ];
        yield 'negative table match' => [
            static function (OutboxContext $c): never {
                $c->noOutboxEventCreatedContaining(new TableNode([['bankId', 'ACME']]));
            },
        ];
    }

    private function contextHolding(object ...$events): OutboxContext
    {
        return OutboxContextFactory::holding(
            $this->createStub(MessageBusInterface::class),
            $this->createStub(EntityManagerInterface::class),
            ...$events,
        );
    }

    /**
     * A refusal earns its keep only if what it points at exists. Nothing else ties the suggested
     * phrasing to a declared pattern: production code and test would otherwise assert the same literal
     * typed twice, so renaming the canonical step leaves every refusal handing back a phrase that
     * produces "step undefined" — the outcome the mechanism exists to prevent — with all gates green.
     *
     * The vocabulary reader resolves step *lines* against declared patterns, and a suggestion is one.
     */
    private function assertSuggestionIsADeclaredStep(string $message, string $marker): void
    {
        $position = \strpos($message, $marker);

        $this->assertNotFalse(
            $position,
            \sprintf('The refusal carries no "%s" suggestion at all: %s', $marker, $message),
        );

        $suggestion = \trim(\substr($message, $position + \strlen($marker)));
        $reader = new BehatVocabularyReader(
            \dirname(__DIR__, 3) . '/Behat',
            \dirname(__DIR__, 4) . '/features',
        );

        $this->assertSame(
            [],
            $reader->unresolvedStepsAmong([$suggestion]),
            \sprintf(
                'The refusal suggests "%s", which matches no declared step pattern. A redirect to a step '
                . 'that does not exist is "step undefined" with extra words.',
                $suggestion,
            ),
        );
    }
}
