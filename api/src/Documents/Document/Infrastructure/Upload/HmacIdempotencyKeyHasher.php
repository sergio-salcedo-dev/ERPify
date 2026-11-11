<?php

declare(strict_types=1);

namespace Erpify\Documents\Document\Infrastructure\Upload;

use Erpify\Documents\Document\Application\Upload\IdempotencyKeyHasher;
use Override;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * HMAC-SHA256 under `kernel.secret` of the case-folded actor id and the key, separated by a byte neither can
 * contain. Keyed, so a stored hash cannot be confirmed against a guessed key; actor-scoped, so two callers with
 * the same key never meet.
 */
#[AsAlias(IdempotencyKeyHasher::class)]
final readonly class HmacIdempotencyKeyHasher implements IdempotencyKeyHasher
{
    private const string PURPOSE = 'documents.upload.idempotency-key';

    public function __construct(
        #[Autowire(param: 'kernel.secret')]
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
