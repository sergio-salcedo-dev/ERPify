<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Iam\Identity\Domain\Entity;

use DateTimeImmutable;
use Erpify\Iam\Identity\Domain\Entity\GeneratedRecoverySecret;
use Erpify\Iam\Identity\Domain\Entity\RecoverySecret;
use Erpify\Tests\Unit\Iam\Identity\Domain\Entity\Mother\UserMother;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * The one carrier that ever holds the plaintext, and the reason it is a type rather than a tuple: the row and
 * the legible half leave the aggregate together exactly once, and nothing downstream may reconstruct the
 * second from the first.
 *
 * @internal
 */
#[CoversClass(GeneratedRecoverySecret::class)]
final class GeneratedRecoverySecretTest extends TestCase
{
    private const string NOW = '2026-08-28T12:00:00+00:00';

    #[Test]
    public function itHandsBackTheRowAndAPresentationThatOpensIt(): void
    {
        $generated = RecoverySecret::mint(UserMother::DEFAULT_ID, new DateTimeImmutable(self::NOW));

        $halves = \explode('.', $generated->plaintext(), 2);
        $this->assertCount(2, $halves, 'the carrier holds no `<selector>.<secret>` presentation');
        $this->assertSame((string) $generated->secret->getId(), $halves[0]);
        $this->assertTrue($generated->secret->verify($halves[1], new DateTimeImmutable(self::NOW)));
    }

    #[Test]
    public function thePlaintextIsHeldPrivatelyAndReachableOnlyThroughItsAccessor(): void
    {
        // A promoted PUBLIC property would put the plaintext on every `var_dump`, every serializer walking
        // the object and every debug payload that reaches an error page. The accessor is the only door.
        $property = (new ReflectionClass(GeneratedRecoverySecret::class))->getProperty('plaintext');

        $this->assertTrue($property->isPrivate(), 'the once-only plaintext is a public property');
    }

    #[Test]
    public function theRowItCarriesIsTheOneTheAccessorDescribes(): void
    {
        $secret = RecoverySecret::mint(UserMother::DEFAULT_ID, new DateTimeImmutable(self::NOW))->secret;

        $carrier = new GeneratedRecoverySecret($secret, 'selector.secret');

        $this->assertSame($secret, $carrier->secret);
        $this->assertSame('selector.secret', $carrier->plaintext());
    }
}
