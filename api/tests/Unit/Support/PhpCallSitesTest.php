<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Support;

use Erpify\Tests\Support\PhpCallSites;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The credential-proof gate decides what counts as a call through this reader, so both of its directions cost
 * something: text that is not a call read as one is a false green, and a real call missed is a red over correct
 * code that teaches people to distrust the gate.
 *
 * @internal
 */
#[CoversClass(PhpCallSites::class)]
final class PhpCallSitesTest extends TestCase
{
    private const string CALL = '$this->currentPasswordProofThrottle->ensureWithinBudget($id);';

    /**
     * The shape `php-cs-fixer` produces past 120 columns, a comment inside the chain and the nullsafe operator
     * are each still one call.
     */
    #[DataProvider('provideACallSpelledAcrossTokensIsStillACallCases')]
    public function testACallSpelledAcrossTokensIsStillACall(string $body): void
    {
        $this->assertSame(
            [['currentPasswordProofThrottle', 'ensureWithinBudget']],
            PhpCallSites::calls($body),
        );
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideACallSpelledAcrossTokensIsStillACallCases(): iterable
    {
        yield 'wrapped' => ["\$this->currentPasswordProofThrottle\n    ->ensureWithinBudget(\$id);"];
        yield 'comment inside the chain' => ['$this->currentPasswordProofThrottle /* x */ ->ensureWithinBudget($id);'];
        yield 'nullsafe' => ['$this?->currentPasswordProofThrottle?->ensureWithinBudget($id);'];
    }

    /**
     * A call that survives only in a comment, a string literal or a nowdoc is not a call.
     */
    #[DataProvider('provideTextThatIsNotACallIsNotACallCases')]
    public function testTextThatIsNotACallIsNotACall(string $body): void
    {
        $this->assertSame([], PhpCallSites::calls($body));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideTextThatIsNotACallIsNotACallCases(): iterable
    {
        yield 'comment' => ['// ' . self::CALL];
        yield 'docblock' => ['/** ' . self::CALL . ' */'];
        yield 'string literal' => ['$m = \'' . self::CALL . "';"];
        yield 'nowdoc' => ["\$m = <<<'TXT'\n" . self::CALL . "\nTXT;"];
        yield 'a property read, not a call' => ['$x = $this->currentPasswordProofThrottle->ensureWithinBudget;'];
        yield 'a static call' => ['CurrentPasswordProofThrottle::ensureWithinBudget($id);'];
    }

    public function testCallsKeepSourceOrder(): void
    {
        $this->assertSame(
            [['a', 'first'], ['b', 'second'], ['a', 'third']],
            PhpCallSites::calls('$this->a->first(); $this->b->second(fn () => $this->a->third());'),
        );
    }

    public function testMethodBodiesKeepClosuresInsideAndSkipAbstractMethods(): void
    {
        $bodies = PhpCallSites::methodBodies(<<<'PHP'
            <?php
            abstract class A {
                abstract public function declared(): void;
                public function &byReference(): array { return $this->x; }
                public function outer(): void { $f = function () { return "{$x}"; }; }
                public function after(): void {}
            }
            PHP);

        $this->assertSame(['byReference', 'outer', 'after'], \array_keys($bodies));
        $this->assertStringContainsString('return "{$x}";', $bodies['outer'] ?? '');
        $this->assertSame('{}', $bodies['after'] ?? null);
    }
}
