<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Iam\Identity\Domain\Entity;

use DateTimeImmutable;
use Erpify\Iam\Identity\Application\ChangeMyPassword;
use Erpify\Iam\Identity\Domain\Entity\RecoverySecret;
use Erpify\Iam\Identity\Domain\Repository\RecoverySecretRepository;
use Erpify\Tests\Support\ConstructorCollaboratorTypes;
use Erpify\Tests\Unit\Iam\Identity\Domain\Entity\Mother\UserMother;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use ReflectionParameter;

/**
 * The three properties the recovery secret's threat model rests on, each asserted BY CONSTRUCTION rather
 * than by sampling behaviour.
 *
 * **Why the selector is the thing being contained.** It is the row's primary key and the half of the
 * presented credential that SELECTS — so it is not a secret, but it is a capability: whoever learns one can
 * spend that selector's whole redemption budget and hold the channel closed in silence, without ever
 * authenticating as anybody. The budget is deliberately keyed on it and nothing else (keying on the address
 * or the identity would put the recovery channel in the same namespace the attack already occupies), which
 * is exactly what makes leaking it a denial capability rather than a curiosity.
 *
 * @internal
 */
#[CoversClass(RecoverySecret::class)]
final class RecoverySecretSelectorContainmentTest extends TestCase
{
    private const string NOW = '2026-08-28T12:00:00+00:00';

    #[Test]
    public function noEventTheAggregateRecordsCarriesTheSelector(): void
    {
        $generated = RecoverySecret::mint(UserMother::DEFAULT_ID, new DateTimeImmutable(self::NOW));
        $secret = $generated->secret;
        $secret->redeem();
        $secret->revoke();

        $events = $secret->pullDomainEvents();
        $selector = $secret->getId();

        $this->assertNotNull($selector);
        // All three transitions at once: minting, redeeming and revoking are the entire life of this row, so
        // a per-event assertion could go stale the day a fourth is added while this one cannot.
        $this->assertCount(3, $events, 'the aggregate no longer records all three of its transitions');

        foreach ($events as $event) {
            $this->assertSame(
                UserMother::DEFAULT_ID,
                $event->aggregateId(),
                'an event names the row instead of the user; that id reaches event_store, which has no TTL',
            );
            // Asserted on the ENCODED payload rather than by walking its members: what reaches `event_store`
            // is the serialised form, so a selector nested inside a structure would escape a shallow scan
            // while an empty payload cannot hide one anywhere.
            $this->assertStringNotContainsString(
                $selector,
                \json_encode($event->toPrimitives(), JSON_THROW_ON_ERROR),
                'the selector reached an event payload',
            );
            $this->assertSame([], $event->toPrimitives(), 'a payload appeared where the envelope says enough');
        }
    }

    #[Test]
    public function theSelectorCannotBeSuppliedByACallerAndSoCannotBeDerivedFromTheSubject(): void
    {
        // Asserted on the SIGNATURE, which is what "by construction" means here: a statistical test over
        // sampled ids would pass for a caller that derived one badly on a path the sample never reached.
        // While minting takes no selector, no caller has anywhere to put a derivation.
        $mint = new ReflectionMethod(RecoverySecret::class, 'mint');
        $parameters = \array_map(static fn (ReflectionParameter $p): string => $p->getName(), $mint->getParameters());

        $this->assertSame(
            ['userId', 'now'],
            $parameters,
            'minting accepts a selector from its caller; the aggregate must draw both halves itself',
        );

        // And the drawn value is not the subject, which is the one derivation a reader might assume from the
        // fact that both are UUIDs on the same row.
        $first = RecoverySecret::mint(UserMother::DEFAULT_ID, new DateTimeImmutable(self::NOW));
        $second = RecoverySecret::mint(UserMother::DEFAULT_ID, new DateTimeImmutable(self::NOW));

        $this->assertNotSame(UserMother::DEFAULT_ID, $first->secret->getId());
        $this->assertNotSame($first->secret->getId(), $second->secret->getId());
    }

    #[Test]
    public function changingThePasswordCannotInvalidateALiveSecret(): void
    {
        // The decision this makes structural: a routine credential rotation must not silently destroy the
        // recovery channel of someone with no shell to notice it went. Eviction is the explicit revoke.
        // Asserted over the collaborator TYPES rather than over behaviour, because the failure mode is a
        // future edit ADDING the repository — which no existing behavioural test would notice, since it
        // would still pass while quietly deleting a row nobody asserted about.
        $collaborators = ConstructorCollaboratorTypes::of(ChangeMyPassword::class);

        $this->assertNotContains(RecoverySecretRepository::class, $collaborators);
        $this->assertNotEmpty($collaborators, 'no constructor parameter type resolved, so the rule has no subject');
    }
}
