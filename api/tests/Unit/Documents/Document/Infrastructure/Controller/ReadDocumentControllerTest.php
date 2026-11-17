<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Documents\Document\Infrastructure\Controller;

use Erpify\Documents\Document\Application\Read\ErasureLedgerOverride;
use Erpify\Documents\Document\Application\Read\ReadDocument;
use Erpify\Documents\Document\Domain\Exception\DocumentGone;
use Erpify\Documents\Document\Domain\Exception\DocumentNotFound;
use Erpify\Documents\Document\Domain\Storage\DocumentStorage;
use Erpify\Documents\Document\Infrastructure\Controller\ReadDocumentController;
use Erpify\Documents\Document\Infrastructure\Read\NeverSetErasureLedgerOverride;
use Erpify\Shared\Audit\Application\AuditLogger;
use Erpify\Shared\Crypto\Application\StreamEnvelopeEncryptor;
use Erpify\Shared\Uuid\Domain\InvalidUuidException;
use Erpify\Shared\Uuid\Domain\Uuid;
use Erpify\Tests\Unit\Documents\Document\Application\InMemoryDocumentRepository;
use Erpify\Tests\Unit\Documents\Document\Infrastructure\Http\RecordingTimeBudget;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The order the controller decides in, before the use case is reached: a malformed id, the ledger's override, the
 * switch. The use case itself (and the whole response) is held by the functional tests.
 *
 * @internal
 *
 * @SuppressWarnings("PHPMD.CouplingBetweenObjects") the controller and every collaborator it is built from
 */
#[CoversClass(ReadDocumentController::class)]
#[CoversClass(NeverSetErasureLedgerOverride::class)]
final class ReadDocumentControllerTest extends TestCase
{
    #[Test]
    public function aMalformedIdIsRefusedBeforeTheSwitchIsConsulted(): void
    {
        $this->expectException(InvalidUuidException::class);

        $this->controller(readEnabled: false)(new Request(), 'not-a-uuid');
    }

    #[Test]
    public function whileReadsAreSwitchedOffTheAnswerIsNotFoundWhateverTheId(): void
    {
        $this->expectException(NotFoundHttpException::class);

        $this->controller(readEnabled: false)(new Request(), Uuid::generate());
    }

    #[Test]
    public function theOverrideAnswersGoneBeforeAnyLookup(): void
    {
        $this->expectException(DocumentGone::class);

        $this->controller(readEnabled: true, override: $this->overridden())(new Request(), Uuid::generate());
    }

    #[Test]
    public function theOverrideAnswersGoneWhateverTheSwitchSays(): void
    {
        $this->expectException(DocumentGone::class);

        $this->controller(readEnabled: false, override: $this->overridden())(new Request(), Uuid::generate());
    }

    #[Test]
    public function aSwitchedOnRouteWithNoOverrideReachesTheUseCase(): void
    {
        $this->expectException(DocumentNotFound::class);

        $this->controller(readEnabled: true)(new Request(), Uuid::generate());
    }

    #[Test]
    public function theAdapterBoundUntilTheLedgerShipsIsNeverSet(): void
    {
        $this->assertFalse((new NeverSetErasureLedgerOverride())->isActive());
    }

    private function controller(
        bool $readEnabled,
        ErasureLedgerOverride $override = new NeverSetErasureLedgerOverride(),
    ): ReadDocumentController {
        return new ReadDocumentController(
            new ReadDocument(
                new InMemoryDocumentRepository(),
                $this->createStub(DocumentStorage::class),
                $this->createStub(StreamEnvelopeEncryptor::class),
                $this->createStub(AuditLogger::class),
                new NullLogger(),
            ),
            $override,
            new RecordingTimeBudget(),
            $readEnabled,
        );
    }

    private function overridden(): ErasureLedgerOverride
    {
        $override = $this->createStub(ErasureLedgerOverride::class);
        $override->method('isActive')->willReturn(true);

        return $override;
    }
}
