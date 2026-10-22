<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Organization\Membership\Domain\Exception;

use Erpify\Organization\Membership\Domain\Exception\MembershipCardinalityViolated;
use Erpify\Shared\ErrorContract\Domain\Exception\ClientError;
use Erpify\Shared\ErrorContract\Domain\Exception\ServiceUnavailable;
use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * @internal
 */
#[CoversClass(MembershipCardinalityViolated::class)]
final class MembershipCardinalityViolatedTest extends TestCase
{
    #[Test]
    public function itNamesTheBrokenInvariantAndNobodyItWasRaisedFor(): void
    {
        $cause = new LogicException('two rows');

        $exception = MembershipCardinalityViolated::severalForOneUser($cause);

        $this->assertSame('membership-cardinality-violated', $exception->type());
        $this->assertSame('A user resolves to more than one membership.', $exception->title());
        $this->assertSame([], $exception->context(), 'the subject is a person, so no id travels');
        $this->assertSame($cause, $exception->getPrevious());
    }

    #[Test]
    public function itIsMarkerLessSoTheContractKeepsItAServerError(): void
    {
        // A duplicated link is corrupted state: neither a 4xx the caller could correct nor a 503 a retry clears.
        $reflection = new ReflectionClass(MembershipCardinalityViolated::class);
        $this->assertFalse($reflection->implementsInterface(ClientError::class));
        $this->assertFalse($reflection->implementsInterface(ServiceUnavailable::class));
    }
}
