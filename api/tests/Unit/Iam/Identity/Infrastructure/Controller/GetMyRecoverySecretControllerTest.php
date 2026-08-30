<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Iam\Identity\Infrastructure\Controller;

use DateTimeImmutable;
use Erpify\Iam\Identity\Domain\Entity\RecoverySecret;
use Erpify\Iam\Identity\Infrastructure\Controller\GetMyRecoverySecretController;
use Erpify\Iam\Identity\Infrastructure\Security\SecurityUser;
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
final class GetMyRecoverySecretControllerTest extends TestCase
{
    private const string NOW = '2026-08-28T12:00:00+00:00';

    #[Test]
    public function anAccountHoldingNoSecretIsToldSoWithBothInstantsNull(): void
    {
        $payload = $this->emit(new InMemoryRecoverySecretRepository());

        $this->assertFalse($payload['exists']);
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
        $this->assertIsString($payload['mintedAt']);
        $this->assertSame('2036-08-28T12:00:00+00:00', $payload['expiresAt']);
    }

    #[Test]
    public function theEmittedPayloadNeverCarriesTheSelector(): void
    {
        // The closed key set is asserted for every case inside `emit()`, which refuses a field a future DTO
        // grows. This case is the other direction and the one with teeth: the selector must not reach the
        // wire under ANY spelling, so the whole serialised body is searched rather than a named field.
        $secret = $this->secret();
        $payload = $this->emit(new InMemoryRecoverySecretRepository($secret));

        $this->assertStringNotContainsString(
            (string) $secret->getId(),
            \json_encode($payload, JSON_THROW_ON_ERROR),
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
        $generated = RecoverySecret::mint(UserMother::DEFAULT_ID, new DateTimeImmutable(self::NOW));
        $generated->secret->pullDomainEvents();

        return $generated->secret;
    }

    /**
     * Drives the endpoint and hands back its payload, narrowed by assertions rather than by a PHPDoc shape.
     *
     * The distinction is load-bearing: annotating the decoded body with its expected shape would make the
     * key-set assertion below provably true, so the one check that refuses a field the DTO grows would stop
     * being able to fail. The narrowing has to come from something that throws.
     *
     * The closed set lives here rather than in one case, so every case pays it.
     *
     * @return array{exists: bool, mintedAt: string|null, expiresAt: string|null}
     */
    private function emit(InMemoryRecoverySecretRepository $secrets): array
    {
        $controller = new GetMyRecoverySecretController($secrets, ResourceResponderBuilder::wired());
        $response = $controller(new SecurityUser(UserMother::create()));

        $this->assertSame(Response::HTTP_OK, $response->getStatusCode(), (string) $response->getContent());

        $body = \json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertIsArray($body);
        $this->assertArrayHasKey('data', $body);

        $data = $body['data'];
        $this->assertIsArray($data);
        $this->assertSame(['exists', 'mintedAt', 'expiresAt'], \array_keys($data));
        $this->assertArrayHasKey('exists', $data);
        $this->assertArrayHasKey('mintedAt', $data);
        $this->assertArrayHasKey('expiresAt', $data);

        $exists = $data['exists'];
        $mintedAt = $data['mintedAt'];
        $expiresAt = $data['expiresAt'];
        $this->assertIsBool($exists);
        $this->assertTrue(null === $mintedAt || \is_string($mintedAt));
        $this->assertTrue(null === $expiresAt || \is_string($expiresAt));

        return ['exists' => $exists, 'mintedAt' => $mintedAt, 'expiresAt' => $expiresAt];
    }
}
