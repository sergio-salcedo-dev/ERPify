<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Backoffice\Bank\Domain\Entity;

use DateTimeInterface;
use Erpify\Backoffice\Bank\Domain\Entity\Bank;
use Erpify\Shared\Clock\Domain\SystemClock;
use Erpify\Tests\Double\Clock\FixedClock;
use Erpify\Tests\Support\PHPUnit\FreezeSystemClockExtension;
use Erpify\Tests\Unit\Backoffice\Bank\Domain\Entity\Mother\BankMother;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionNamedType;

/**
 * A logo change is a decision, not a business event: it moves `logoImageId` and `updatedAt` and records nothing, and
 * holding the id the bank already holds is a no-op that bumps nothing. The clock moves between storing and changing,
 * which is what makes an `updatedAt` that should not have moved observable.
 *
 * @internal
 */
#[CoversClass(Bank::class)]
final class BankLogoTest extends TestCase
{
    private const string STORED_AT = '2026-06-14T09:30:00+00:00';

    private const string CHANGED_AT = '2026-06-15T11:00:00+00:00';

    private const string LOGO = '0190b1b2-c3d4-7e5f-8a9b-0c1d2e3f4a5c';

    private const string OTHER_LOGO = '0190b1b2-c3d4-7e5f-8a9b-0c1d2e3f4a5d';

    protected function tearDown(): void
    {
        FreezeSystemClockExtension::pin();

        parent::tearDown();
    }

    public function testANewBankHoldsNoLogo(): void
    {
        $this->assertNull(BankMother::create()->getLogoImageId());
    }

    public function testSettingALogoHoldsItBumpsUpdatedAtAndRecordsNothing(): void
    {
        $bank = $this->storedBank();

        $bank->changeLogo(self::LOGO);

        $this->assertSame(self::LOGO, $bank->getLogoImageId());
        $this->assertSame(self::CHANGED_AT, $bank->getUpdatedAt()->format(DateTimeInterface::ATOM));
        $this->assertSame([], $bank->pullDomainEvents(), 'a logo change records no domain event');
    }

    public function testReplacingAndRemovingEachMoveTheIdAndBumpUpdatedAt(): void
    {
        $bank = $this->storedBank();
        $bank->changeLogo(self::LOGO);

        SystemClock::set(FixedClock::at('2026-06-16T08:00:00+00:00'));
        $bank->changeLogo(self::OTHER_LOGO);
        $this->assertSame(self::OTHER_LOGO, $bank->getLogoImageId());
        $this->assertSame('2026-06-16T08:00:00+00:00', $bank->getUpdatedAt()->format(DateTimeInterface::ATOM));

        SystemClock::set(FixedClock::at('2026-06-17T08:00:00+00:00'));
        $bank->changeLogo(null);
        $this->assertNull($bank->getLogoImageId());
        $this->assertSame('2026-06-17T08:00:00+00:00', $bank->getUpdatedAt()->format(DateTimeInterface::ATOM));
        $this->assertSame([], $bank->pullDomainEvents());
    }

    public function testHoldingTheIdItAlreadyHoldsIsANoOpThatBumpsNothing(): void
    {
        $bank = $this->storedBank();
        $bank->changeLogo(self::LOGO);

        $stampedAt = $bank->getUpdatedAt()->format(DateTimeInterface::ATOM);

        SystemClock::set(FixedClock::at('2026-06-18T08:00:00+00:00'));
        $bank->changeLogo(self::LOGO);

        $this->assertSame(self::LOGO, $bank->getLogoImageId());
        $this->assertSame($stampedAt, $bank->getUpdatedAt()->format(DateTimeInterface::ATOM));
    }

    public function testRemovingWhenNoneIsHeldIsANoOpThatBumpsNothing(): void
    {
        $bank = $this->storedBank();

        $bank->changeLogo(null);

        $this->assertNull($bank->getLogoImageId());
        $this->assertSame(self::STORED_AT, $bank->getUpdatedAt()->format(DateTimeInterface::ATOM));
    }

    /**
     * An image has exactly one holder, so no way of producing a second `Bank` may carry the id along. None exists
     * today; this is the tripwire for the one that would: a `__clone`, or a method that hands back another instance of
     * the aggregate, must either not exist or be given a deliberate answer here.
     */
    public function testNoCloneOrDuplicatePathCopiesTheLogoId(): void
    {
        $reflection = new ReflectionClass(Bank::class);

        $this->assertFalse($reflection->hasMethod('__clone'), 'a __clone would copy the logo id');
        $this->assertTrue($reflection->isFinal());

        $factories = [];
        $fluent = [];

        foreach ($reflection->getMethods() as $method) {
            $returnType = $method->getReturnType();

            if (!$returnType instanceof ReflectionNamedType) {
                continue;
            }

            if (!\in_array($returnType->getName(), ['self', 'static', Bank::class], true)) {
                continue;
            }

            if ($method->isStatic()) {
                $factories[] = $method->getName();

                continue;
            }

            $fluent[] = $method->getName();
        }

        $this->assertSame(['create'], $factories, 'the only way to make a Bank is the factory, which holds no logo');
        $this->assertNull(BankMother::create()->getLogoImageId());

        // An instance method returning the aggregate must hand back itself, never a second holder of the id.
        $bank = BankMother::withLogo(self::LOGO);

        $this->assertSame(['setId'], $fluent, 'a new instance-returning method owes a case here');

        $this->assertSame($bank, $bank->setId(BankMother::DEFAULT_ID));
    }

    private function storedBank(): Bank
    {
        SystemClock::set(FixedClock::at(self::STORED_AT));

        $bank = BankMother::drained();

        SystemClock::set(FixedClock::at(self::CHANGED_AT));

        return $bank;
    }
}
