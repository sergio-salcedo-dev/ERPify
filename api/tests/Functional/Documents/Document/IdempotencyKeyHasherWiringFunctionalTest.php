<?php

declare(strict_types=1);

namespace Erpify\Tests\Functional\Documents\Document;

use Erpify\Documents\Document\Application\Upload\IdempotencyKeyHasher;
use Erpify\Documents\Document\Infrastructure\Upload\HmacIdempotencyKeyHasher;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The hasher the container builds is keyed by `DOCUMENTS_IDEMPOTENCY_SECRET` and not by `kernel.secret`.
 *
 * The unit test proves the digest follows whatever secret it is handed; only the container knows which one it is
 * handed. Without this, wiring the hasher back to the application secret passes every test while rotating that
 * secret goes back to unmatching every stored hash.
 *
 * @internal
 */
#[CoversClass(HmacIdempotencyKeyHasher::class)]
final class IdempotencyKeyHasherWiringFunctionalTest extends KernelTestCase
{
    private const string ACTOR = '0190e9c2-7b5a-7d40-9c8f-2f9b5d3e1c01';

    #[Test]
    public function theContainerKeysTheHasherWithItsOwnSecretAndNotTheApplicationOne(): void
    {
        self::bootKernel();
        $hasher = self::getContainer()->get(IdempotencyKeyHasher::class);
        $this->assertInstanceOf(HmacIdempotencyKeyHasher::class, $hasher);

        $digest = $hasher->hash(self::ACTOR, 'key-1');

        $this->assertSame(
            (new HmacIdempotencyKeyHasher($this->envSecret()))->hash(self::ACTOR, 'key-1'),
            $digest,
            'the container keys the hasher with something other than DOCUMENTS_IDEMPOTENCY_SECRET, so rotating that '
            . 'other secret unmatches every stored idempotency hash',
        );
    }

    /**
     * @SuppressWarnings("PHPMD.Superglobals") Symfony's env processor reads `$_SERVER`/`$_ENV` before `getenv()`,
     * so reading the same place is what makes this the value the container resolved.
     */
    private function envSecret(): string
    {
        $value = $_SERVER['DOCUMENTS_IDEMPOTENCY_SECRET'] ?? $_ENV['DOCUMENTS_IDEMPOTENCY_SECRET'] ?? null;
        $this->assertIsString($value, 'DOCUMENTS_IDEMPOTENCY_SECRET is not set in the test environment');

        return $value;
    }
}
