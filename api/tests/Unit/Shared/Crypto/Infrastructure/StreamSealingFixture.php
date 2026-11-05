<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Shared\Crypto\Infrastructure;

use Erpify\Shared\Crypto\Domain\EncryptionScopeId;
use Erpify\Shared\Crypto\Infrastructure\ScopeKeyring;
use Erpify\Shared\Crypto\Infrastructure\SodiumStreamEnvelopeEncryptor;
use Erpify\Shared\Uuid\Domain\Uuid;
use Erpify\Tests\Unit\Shared\Crypto\InMemoryKeystore;

/**
 * The encryptor under test, its in-memory keystore and the stream helpers every stream-seal test class shares.
 */
trait StreamSealingFixture
{
    private const string KEK = '0123456789abcdef0123456789abcdef';

    private const string AAD = 'original|v1';

    private InMemoryKeystore $keystore;

    private SodiumStreamEnvelopeEncryptor $encryptor;

    protected function setUp(): void
    {
        $this->keystore = new InMemoryKeystore();
        $this->encryptor = new SodiumStreamEnvelopeEncryptor(new ScopeKeyring($this->keystore, self::KEK));
    }

    private function documentScope(): EncryptionScopeId
    {
        return EncryptionScopeId::forDocument(Uuid::generate());
    }

    /**
     * @return resource
     */
    private function memory(string $contents = '')
    {
        $stream = \fopen('php://memory', 'r+');
        $this->assertIsResource($stream);
        \fwrite($stream, $contents);
        \rewind($stream);

        return $stream;
    }

    /**
     * @param resource $stream
     */
    private function contents($stream): string
    {
        \rewind($stream);

        return (string) \stream_get_contents($stream);
    }

    private function sealedBytes(EncryptionScopeId $scope, string $plaintext): string
    {
        $sealed = $this->memory();
        $this->encryptor->seal($scope, $this->memory($plaintext), $sealed, self::AAD);

        return $this->contents($sealed);
    }

    private function opened(EncryptionScopeId $scope, string $sealed): string
    {
        $plaintext = $this->memory();
        $this->encryptor->unseal($scope, $this->memory($sealed), $plaintext, self::AAD);

        return $this->contents($plaintext);
    }
}
