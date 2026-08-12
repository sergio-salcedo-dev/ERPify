<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Iam\Identity\Infrastructure\Security;

use Erpify\Iam\Identity\Domain\Email;
use Erpify\Iam\Identity\Infrastructure\Security\RecoveryBudgetKey;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The budget key must denote the same thing the identity lookup denotes. Every case below is a spelling that
 * strict RFC validation admits into the endpoint and that {@see Email} folds onto one mailbox; a key that
 * folded fewer of them would hand out one budget per spelling.
 *
 * @internal
 */
#[CoversClass(RecoveryBudgetKey::class)]
final class RecoveryBudgetKeyTest extends TestCase
{
    private const string CANONICAL = 'nora@erpify.test';

    #[DataProvider('provideEverySpellingOfOneMailboxSharesOneBucketCases')]
    public function testEverySpellingOfOneMailboxSharesOneBucket(string $spelling): void
    {
        $this->assertSame(self::CANONICAL, RecoveryBudgetKey::forEmail($spelling));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideEverySpellingOfOneMailboxSharesOneBucketCases(): iterable
    {
        yield 'as typed' => [self::CANONICAL];
        yield 'upper case' => ['NORA@Erpify.TEST'];
        yield 'ascii padding' => ['  nora@erpify.test '];
        // The one the plain `trim()` missed: `\u{00A0}` is not ASCII whitespace, so it survived the old key
        // while `Email` stripped it — one mailbox, unbounded budgets, and the limiter evadable by repetition.
        yield 'no-break space, leading' => ["\u{00A0}" . self::CANONICAL];
        yield 'no-break space, trailing' => [self::CANONICAL . "\u{00A0}"];
        yield 'no-break space, repeated' => ["\u{00A0}\u{00A0}" . self::CANONICAL];
        yield 'zero-width space' => ["\u{200B}" . self::CANONICAL];
        yield 'byte order mark' => ["\u{FEFF}" . self::CANONICAL];
    }

    public function testTheKeyIsTheIdentityLookupsOwnCanonicalForm(): void
    {
        // Not merely "equal by coincidence": the bucket is the value the repository would resolve the address
        // to, so the budget and the identity cannot drift apart under a future change to either.
        $canonical = Email::tryFrom("\u{00A0}NORA@Erpify.TEST ");

        $this->assertInstanceOf(Email::class, $canonical);
        $this->assertSame($canonical->toString(), RecoveryBudgetKey::forEmail("\u{00A0}NORA@Erpify.TEST "));
    }

    public function testAnAddressTheValueObjectRefusesStillYieldsAKey(): void
    {
        // A limiter that declined to charge for malformed input would answer, by its own behaviour, which
        // spellings denote a real mailbox. So the fallback must key it rather than refuse.
        $this->assertSame('not-an-address', RecoveryBudgetKey::forEmail('  Not-An-Address '));
    }

    public function testTwoDifferentMailboxesDoNotCollide(): void
    {
        $this->assertNotSame(
            RecoveryBudgetKey::forEmail(self::CANONICAL),
            RecoveryBudgetKey::forEmail('other@erpify.test'),
        );
    }
}
