<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Iam\Identity\Infrastructure\Controller;

use DateInterval;
use DateTimeImmutable;
use Erpify\Iam\Identity\Application\Resource\RecoverySecretResource;
use Erpify\Iam\Identity\Domain\Entity\RecoverySecret;
use Erpify\Iam\Identity\Infrastructure\Controller\GetMyRecoverySecretController;
use Erpify\Iam\Identity\Infrastructure\Security\SecurityUser;
use Erpify\Shared\Clock\Domain\SystemClock;
use Erpify\Tests\Double\Clock\FixedClock;
use Erpify\Tests\Support\ResourceResponderBuilder;
use Erpify\Tests\Unit\Iam\Identity\Application\InMemoryRecoverySecretRepository;
use Erpify\Tests\Unit\Iam\Identity\Domain\Entity\Mother\UserMother;
use Erpify\Tests\Unit\Shared\Persistence\Double\LockOrderJournal;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * The read that makes a ten-year credential governable, and the two things it must never do.
 *
 * This endpoint is the whole of the owner's visibility into a secret that survives a password rotation and
 * is not rotated when spent. What it emits therefore has to answer "do I hold one, and until when" — and
 * nothing else: the SELECTOR is the row's primary key and a denial capability, so whoever reads it can hold
 * the channel shut in silence without authenticating, and the plaintext exists once, in the mint response.
 * Both absences are asserted against the emitted payload rather than the DTO, because a `#[SerializedName]`
 * is the kind of thing that puts a field on the wire under another name.
 *
 * The payload is read through the real normalize-then-respond chain ({@see ResourceResponderBuilder}) for
 * that reason: a double would answer with whatever the controller handed it, which is the half already
 * known.
 *
 * @internal
 */
#[CoversClass(GetMyRecoverySecretController::class)]
#[CoversClass(RecoverySecretResource::class)]
final class GetMyRecoverySecretControllerTest extends TestCase
{
    private const string NOW = '2026-08-28T12:00:00+00:00';

    /** The mint's own TTL away from {@see NOW}: the value the owner plans around. */
    private const string LAPSES = '2036-08-28T12:00:00+00:00';

    #[Test]
    public function anAccountHoldingNoSecretIsToldSoWithBothInstantsNull(): void
    {
        $payload = $this->emit(new InMemoryRecoverySecretRepository());

        $this->assertFalse($payload['exists']);
        $this->assertFalse($payload['live']);
        $this->assertNull($payload['mintedAt']);
        $this->assertNull($payload['expiresAt']);
    }

    #[Test]
    public function anAccountHoldingOneIsToldWhenItWasMintedAndWhenItDies(): void
    {
        // The two instants ARE the governance: an owner who cannot see when it was issued and when it lapses
        // has no way to decide about a credential that outlives every password they will set.
        $payload = $this->emit(new InMemoryRecoverySecretRepository($this->secret()));

        $this->assertTrue($payload['exists']);
        $this->assertTrue($payload['live']);
        // Both instants are pinned to a VALUE, not to a type. `expiresAt` comes from the injected clock, but
        // `mintedAt` is the aggregate's `createdAt`, taken from the ambient {@see SystemClock} — so asserting
        // only `assertIsString` here leaves the two slots interchangeable: feeding `expiresAt` into the
        // `mintedAt` position passes, and the owner reads "Created 2036 / Expires 2036" over a credential
        // minted today. Freezing the ambient clock is what makes the value assertable at all.
        $this->assertSame(self::NOW, $payload['mintedAt']);
        $this->assertSame(self::LAPSES, $payload['expiresAt']);
    }

    #[Test]
    public function aLapsedSecretStillExistsButIsNotLive(): void
    {
        // An expired row still occupies the slot, so `exists` alone would read as "covered" over a credential
        // that no longer signs anyone in. The read is judged against the injected clock, at the exact
        // instant of expiry — the boundary redemption itself refuses at.
        $payload = $this->emit(new InMemoryRecoverySecretRepository($this->secret()), self::LAPSES);

        $this->assertTrue($payload['exists']);
        $this->assertFalse($payload['live']);
        $this->assertSame(self::LAPSES, $payload['expiresAt']);
    }

    #[Test]
    public function aSecretOneSecondShortOfItsExpiryIsStillLive(): void
    {
        $lapses = new DateTimeImmutable(self::LAPSES);
        $oneSecondShort = $lapses->sub(new DateInterval('PT1S'))->format(DATE_ATOM);
        $payload = $this->emit(new InMemoryRecoverySecretRepository($this->secret()), $oneSecondShort);

        $this->assertTrue($payload['live']);
    }

    #[Test]
    public function theEmittedPayloadNeverCarriesTheSelector(): void
    {
        // The closed key set is asserted for every case inside `emit()`, which refuses a field a future DTO
        // grows INSIDE `data`. This case is the other direction and the one with teeth: the selector must not
        // reach the wire under ANY spelling, so the RESPONSE BODY is searched — the bytes the client receives,
        // not a re-encoding of the three values `emit()` narrowed out of them. The distinction is the whole
        // assertion: a selector riding in an envelope block beside `data` is invisible to a reconstruction of
        // `data`'s own fields, and so is one reaching the wire under a `#[SerializedName]`.
        $secret = $this->secret();
        $content = (string) $this->respond(new InMemoryRecoverySecretRepository($secret))->getContent();

        $this->assertStringNotContainsString(
            (string) $secret->getId(),
            $content,
            'the selector reached the wire, and whoever reads it can hold the channel shut in silence',
        );
    }

    #[Test]
    public function theReadTakesNoRowLock(): void
    {
        // A page render may not take a row lock. The port splits its finders precisely so the resolving one
        // authorizes nothing, and reaching for the `ForUpdate` twin here would put a lock on the identity's
        // secret every time the profile screen is opened.
        $secrets = new InMemoryRecoverySecretRepository($this->secret());
        $journal = new LockOrderJournal();
        $secrets->lockOrderJournal = $journal;

        $this->emit($secrets);

        $this->assertSame([], $journal->crossTableOrder(), 'a read for a page render took a row lock');
    }

    private function secret(): RecoverySecret
    {
        // The aggregate stamps its own `createdAt` from the ambient clock, which no constructor argument
        // reaches; freezing it is what turns `mintedAt` from a type into a value this test can assert.
        // `FreezeSystemClockExtension` re-pins the suite instant after the case, so nothing leaks into the next one.
        SystemClock::set(FixedClock::at(self::NOW));

        $generated = RecoverySecret::mint(UserMother::DEFAULT_ID, new DateTimeImmutable(self::NOW));
        $generated->secret->pullDomainEvents();

        return $generated->secret;
    }

    /** The endpoint driven end to end, handing back the response so a case can read the bytes it emits. */
    private function respond(InMemoryRecoverySecretRepository $secrets, string $readAt = self::NOW): Response
    {
        // The read instant is installed as the ambient clock too: an injected clock disagreeing with it throws
        // on read, and the aggregate was already stamped, so moving the ambient one changes nothing it holds.
        $clock = FixedClock::at($readAt);
        SystemClock::set($clock);

        $controller = new GetMyRecoverySecretController($secrets, ResourceResponderBuilder::wired(), $clock);

        return $controller(new SecurityUser(UserMother::create()));
    }

    /**
     * Drives the endpoint and hands back its payload, narrowed by assertions rather than by a PHPDoc shape.
     *
     * The distinction is load-bearing: annotating the decoded body with its expected shape would make the
     * key-set assertion below provably true, so the one check that refuses a field the DTO grows would stop
     * being able to fail. The narrowing has to come from something that throws.
     *
     * That is also what the `assertArrayHasKey` trio is — the step that throws, so the reads under it are
     * typed. They are deliberately unfalsifiable once the key set above has passed, and that is not the same
     * defect: a PHPDoc would narrow the KEY-SET CHECK itself into a tautology, while these narrow only the
     * reads that follow it. The check that has to keep its teeth still has them.
     *
     * The closed set lives here rather than in one case, so every case pays it.
     *
     * @return array{exists: bool, live: bool, mintedAt: string|null, expiresAt: string|null}
     */
    private function emit(InMemoryRecoverySecretRepository $secrets, string $readAt = self::NOW): array
    {
        $response = $this->respond($secrets, $readAt);

        $this->assertSame(Response::HTTP_OK, $response->getStatusCode(), (string) $response->getContent());

        $body = \json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertIsArray($body);
        $this->assertArrayHasKey('data', $body);

        $data = $body['data'];
        $this->assertIsArray($data);
        $this->assertSame(['exists', 'live', 'mintedAt', 'expiresAt'], \array_keys($data));
        $this->assertArrayHasKey('exists', $data);
        $this->assertArrayHasKey('live', $data);
        $this->assertArrayHasKey('mintedAt', $data);
        $this->assertArrayHasKey('expiresAt', $data);

        $exists = $data['exists'];
        $live = $data['live'];
        $mintedAt = $data['mintedAt'];
        $expiresAt = $data['expiresAt'];
        $this->assertIsBool($exists);
        $this->assertIsBool($live);
        $this->assertTrue(null === $mintedAt || \is_string($mintedAt));
        $this->assertTrue(null === $expiresAt || \is_string($expiresAt));

        return ['exists' => $exists, 'live' => $live, 'mintedAt' => $mintedAt, 'expiresAt' => $expiresAt];
    }
}
