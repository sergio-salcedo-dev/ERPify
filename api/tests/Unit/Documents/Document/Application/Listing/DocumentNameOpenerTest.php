<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Documents\Document\Application\Listing;

use Erpify\Documents\Document\Application\Listing\DocumentNameOpener;
use Erpify\Documents\Document\Application\Listing\NameCause;
use Erpify\Documents\Document\Application\Listing\NameSource;
use Erpify\Documents\Document\Application\Listing\OpenedName;
use Erpify\Documents\Document\Application\Upload\CommitUpload;
use Erpify\Shared\Crypto\Application\EnvelopeEncryptor;
use Erpify\Shared\Crypto\Application\SealedValue;
use Erpify\Shared\Crypto\Domain\EncryptionScopeId;
use Erpify\Shared\Crypto\Domain\Exception\DecryptionFailed;
use Erpify\Shared\Crypto\Domain\Exception\DekDestroyed;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The opener's own rules, with the sealer stubbed: what it asks for (one batch, the document's scope and the filename
 * associated data, nothing for a source with no ciphertext) and how each answer becomes a name or a cause. The
 * sealer's behaviour is `SodiumEnvelopeEncryptorDecryptManyTest`'s; the real keystore is
 * `DocumentNameOpenerFunctionalTest`'s.
 *
 * @internal
 */
#[CoversClass(DocumentNameOpener::class)]
final class DocumentNameOpenerTest extends TestCase
{
    /**
     * @param array<string, OpenedName> $opened
     */
    private function at(array $opened, string $documentId): OpenedName
    {
        $this->assertArrayHasKey($documentId, $opened);

        return $opened[$documentId];
    }

    #[Test]
    public function itAsksForOneBatchOfTheOpenableSourcesUnderTheFilenameAssociatedData(): void
    {
        $encryptor = $this->createMock(EnvelopeEncryptor::class);
        $encryptor->expects($this->once())->method('decryptMany')->willReturnCallback(
            function (array $values): array {
                $this->assertSame(['doc-a'], \array_keys($values), 'a source with no ciphertext is not asked for');
                $sealed = $values['doc-a'] ?? null;
                $this->assertInstanceOf(SealedValue::class, $sealed);
                $this->assertSame(EncryptionScopeId::forDocument('scope-a')->toString(), $sealed->scope->toString());
                $this->assertSame('token-a', $sealed->ciphertext);
                $this->assertSame(CommitUpload::filenameAssociatedData('scope-a'), $sealed->aad);

                return ['doc-a' => 'name.pdf'];
            },
        );

        $opened = (new DocumentNameOpener($encryptor))->open([
            new NameSource('doc-a', 'scope-a', 'token-a'),
            new NameSource('doc-b', 'scope-b', null),
        ]);

        $this->assertSame('name.pdf', $this->at($opened, 'doc-a')->name);
        $this->assertNull($this->at($opened, 'doc-b')->name);
        $this->assertNull($this->at($opened, 'doc-b')->failure);
    }

    #[Test]
    public function aNonTextPlaintextIsUnreadableAndEachCauseIsClassified(): void
    {
        $scope = EncryptionScopeId::forDocument('s');
        $encryptor = $this->createStub(EnvelopeEncryptor::class);
        $encryptor->method('decryptMany')->willReturn([
            'binary' => "\xFF\xFE not utf-8",
            'destroyed' => DekDestroyed::forScope($scope),
            'tampered' => DecryptionFailed::forScope($scope),
        ]);

        $opened = (new DocumentNameOpener($encryptor))->open([
            new NameSource('binary', 's', 't'),
            new NameSource('destroyed', 's', 't'),
            new NameSource('tampered', 's', 't'),
        ]);

        $this->assertSame(NameCause::Unreadable, $this->at($opened, 'binary')->failure);
        $this->assertSame(NameCause::KeyMissing, $this->at($opened, 'destroyed')->failure);
        $this->assertSame(NameCause::DecryptionFailed, $this->at($opened, 'tampered')->failure);
        $this->assertNull($this->at($opened, 'binary')->name);
    }

    #[Test]
    public function aSetOfOnlyNamelessSourcesNeverReachesTheSealer(): void
    {
        $encryptor = $this->createMock(EnvelopeEncryptor::class);
        $encryptor->expects($this->never())->method('decryptMany');

        $opened = (new DocumentNameOpener($encryptor))->open([new NameSource('doc', 'scope', null)]);

        $this->assertNull($this->at($opened, 'doc')->name);
    }
}
