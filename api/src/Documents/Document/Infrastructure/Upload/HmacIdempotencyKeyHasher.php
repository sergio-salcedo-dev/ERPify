<?php

declare(strict_types=1);

namespace Erpify\Documents\Document\Infrastructure\Upload;

use Erpify\Documents\Document\Application\Upload\IdempotencyKeyHasher;
use Override;
use SensitiveParameter;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * HMAC-SHA256 under `DOCUMENTS_IDEMPOTENCY_SECRET` of the case-folded actor id and the key, separated by a byte
 * neither can contain. Keyed, so a stored hash cannot be confirmed against a guessed key; actor-scoped, so two
 * callers with the same key never meet.
 *
 * A secret of its own and not `kernel.secret`: rotating the application secret (sessions, CSRF) must not
 * silently unmatch every stored hash, which would let a client that retries across the rotation file the same
 * evidence twice. Rotating THIS one has that effect, deliberately and only for retries in flight — a duplicate,
 * never a loss.
 */
#[AsAlias(IdempotencyKeyHasher::class)]
final readonly class HmacIdempotencyKeyHasher implements IdempotencyKeyHasher
{
    private const string PURPOSE = 'documents.upload.idempotency-key';

    public function __construct(
        #[Autowire(env: 'DOCUMENTS_IDEMPOTENCY_SECRET')]
        #[SensitiveParameter]
        private string $secret,
    ) {
    }

    #[Override]
    public function hash(string $actorId, string $idempotencyKey): string
    {
        return \hash_hmac(
            'sha256',
            self::PURPOSE . "\0" . \strtolower($actorId) . "\0" . $idempotencyKey,
            $this->secret,
        );
    }
}
