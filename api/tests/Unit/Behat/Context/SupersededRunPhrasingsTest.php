<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Behat\Context;

use Closure;
use Erpify\Tests\Behat\Context\MessengerConsumerContext;
use Erpify\Tests\Behat\Context\SymfonyCommandContext;
use Erpify\Tests\Behat\Support\Execution\LastRun;
use Erpify\Tests\Behat\Support\Messenger\MessengerTransports;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\Messenger\Handler\HandlersLocatorInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Throwable;

/**
 * The six phrasings that used to be two vocabularies for the same three assertions stay registered and
 * refuse, each naming the canonical form.
 *
 * Keeping them is what stops the next reader re-adding one believing it is missing, and what turns
 * reaching for one into a correction rather than "step undefined". None of that survives if a refusal
 * ever returns quietly instead of throwing — a step that accepts anything is the defect this whole
 * change removes, so the throwing is the thing worth pinning.
 *
 * Nothing else about these contexts is exercised here: the refusals touch none of their collaborators,
 * which is why a bare container and two stubs are enough to reach them.
 *
 * {@see CoversNothing} because the subject is test infrastructure — `tests/` sits outside the coverage
 * allowlist, so there is no production line here to credit.
 *
 * @internal
 */
#[CoversNothing]
final class SupersededRunPhrasingsTest extends TestCase
{
    /**
     * @param Closure(self): void     $step
     * @param class-string<Throwable> $expected
     */
    #[DataProvider('provideASupersededPhrasingRefusesAndNamesTheCanonicalFormCases')]
    public function testASupersededPhrasingRefusesAndNamesTheCanonicalForm(
        Closure $step,
        string $expected,
        string $canonical,
    ): void {
        $this->expectException($expected);
        $this->expectExceptionMessage($canonical);

        $step($this);
    }

    /**
     * @return iterable<string, array{Closure(self): void, class-string<Throwable>, string}>
     */
    public static function provideASupersededPhrasingRefusesAndNamesTheCanonicalFormCases(): iterable
    {
        yield 'messenger: the command should succeed' => [
            static function (self $test): never {
                $test->messenger()->theCommandShouldSucceed();
            },
            RuntimeException::class,
            'the last run should succeed',
        ];
        yield 'messenger: the output should contain' => [
            static function (self $test): never {
                $test->messenger()->theOutputShouldContain('anything');
            },
            RuntimeException::class,
            'the last run output should contain "anything"',
        ];
        yield 'console: the last command should succeed' => [
            static function (self $test): never {
                $test->console()->theLastCommandShouldSucceed();
            },
            InvalidArgumentException::class,
            'the last run should succeed',
        ];
        yield 'console: the last command should fail' => [
            static function (self $test): never {
                $test->console()->theLastCommandShouldFail();
            },
            InvalidArgumentException::class,
            'the last run should fail',
        ];
        yield 'console: the command output should contain' => [
            static function (self $test): never {
                $test->console()->theCommandOutputShouldContain('anything');
            },
            InvalidArgumentException::class,
            'the last run output should contain "anything"',
        ];
        yield 'console: the command output should be JSON with a field' => [
            static function (self $test): never {
                $test->console()->theCommandOutputShouldBeJsonWithField('total');
            },
            InvalidArgumentException::class,
            'the last run output should be JSON with a "total" field',
        ];
    }

    private function messenger(): MessengerConsumerContext
    {
        return new MessengerConsumerContext(
            new LastRun(),
            new MessengerTransports(new Container()),
            $this->createStub(MessageBusInterface::class),
            $this->createStub(HandlersLocatorInterface::class),
        );
    }

    private function console(): SymfonyCommandContext
    {
        return new SymfonyCommandContext(new LastRun(), $this->createStub(KernelInterface::class));
    }
}
