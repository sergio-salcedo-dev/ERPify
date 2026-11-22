<?php

declare(strict_types=1);

namespace Erpify\Tests\Behat\Context;

use Behat\Behat\Context\Context;
use Behat\Hook\AfterScenario;
use Behat\Hook\BeforeScenario;
use Behat\Step\Given;
use Behat\Step\Then;
use Doctrine\ORM\EntityManagerInterface;
use Erpify\Documents\Document\Application\Upload\UploadEvidence;
use Erpify\Documents\Document\Domain\Storage\DocumentStorage;
use Erpify\Documents\Document\Domain\Storage\StorageArea;
use Erpify\Shared\Crypto\Application\StreamEnvelopeEncryptor;
use Erpify\Shared\Crypto\Domain\EncryptionScopeId;
use Erpify\Shared\Uuid\Domain\Uuid;
use Erpify\Tests\Behat\Context\Abstraction\AbstractContext;
use Erpify\Tests\Behat\State\HttpResponseContainer;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\Uid\Uuid as RandomUuid;

/**
 * Steps for the audited read of a document: a `Ready` document whose original is really sealed and stored, the
 * byte-level tampering of that object, the raw body of the last response (the HTTP steps decode streamed bodies as
 * newline-delimited JSON, which an original is not), and closing the read switch for one scenario.
 *
 * Closing the switch: the test environment reads `documents.read_enabled` from `DOCUMENTS_READ_ENABLED`, and the
 * kernel reboots between requests, so the next request's container reads the closed switch. What the switch held
 * before the scenario is put back after it (unset stays unset), and the objects a scenario sealed are removed with it.
 */
final class DocumentsReadContext extends AbstractContext implements Context
{
    private const string VARIABLE = 'DOCUMENTS_READ_ENABLED';

    /** @var array<string, string> document id => scope id, for the objects this scenario sealed */
    private array $sealed = [];

    /** @var array{env: ?string, server: ?string, process: string|false}|null what the switch held before the scenario */
    private ?array $before = null;

    public function __construct(
        private readonly DocumentStorage $storage,
        private readonly StreamEnvelopeEncryptor $encryptor,
        private readonly EntityManagerInterface $entityManager,
        private readonly HttpResponseContainer $httpResponseContainer,
        #[Autowire(service: 'test.service_container')]
        private readonly Container $container,
    ) {
    }

    /**
     * @SuppressWarnings("PHPMD.Superglobals") the three places the switch is read from, each kept apart: one may hold
     * a value the others do not, and restoring means putting back exactly what each held, absent included.
     */
    #[BeforeScenario]
    public function rememberTheReadSwitch(): void
    {
        $this->before = [
            'env' => \is_string($_ENV[self::VARIABLE] ?? null) ? $_ENV[self::VARIABLE] : null,
            'server' => \is_string($_SERVER[self::VARIABLE] ?? null) ? $_SERVER[self::VARIABLE] : null,
            'process' => \getenv(self::VARIABLE),
        ];
    }

    #[Given('document reads are switched off')]
    public function documentReadsAreSwitchedOff(): void
    {
        $this->set('false');
    }

    /**
     * Seals `$content` under a fresh scope into the final namespace and files the `Ready` row that describes it, with
     * the digest and size of what was sealed.
     */
    #[Given('/^a ready document with id "([^"]*)" holding "([^"]*)"$/')]
    public function aReadyDocumentWithIdHolding(string $id, string $content): void
    {
        $scope = RandomUuid::v4()->toRfc4122();
        $source = \fopen('php://memory', 'w+');
        self::assertIsResource($source);
        \fwrite($source, $content);
        \rewind($source);

        $this->storage->stageWith($scope, function ($sink) use ($scope, $source): void {
            $this->encryptor->seal(
                EncryptionScopeId::forDocument($scope),
                $source,
                $sink,
                UploadEvidence::ORIGINAL_ASSOCIATED_DATA,
            );
        });
        \fclose($source);
        $this->storage->promote($scope);
        $this->sealed[$id] = $scope;

        $this->entityManager->getConnection()->executeStatement(
            'INSERT INTO document_series (id, created_at) VALUES (:id, NOW()) ON CONFLICT DO NOTHING',
            ['id' => $id],
        );
        $this->entityManager->getConnection()->executeStatement(
            'INSERT INTO document (id, scope_id, series_id, uploader_id, state, digest, size_bytes, promoted_at, '
            . 'next_scan_at, created_at, updated_at) '
            . "VALUES (:id, :scope, :id, :uploader, 'ready', :digest, :size, NOW(), NOW(), NOW(), NOW())",
            [
                'id' => $id,
                'scope' => $scope,
                'uploader' => Uuid::generate(),
                'digest' => \hash('sha256', $content),
                'size' => \strlen($content),
            ],
        );
    }

    #[Given('/^the sealed object of document "([^"]*)" is altered$/')]
    public function theSealedObjectOfDocumentIsAltered(string $id): void
    {
        $scope = $this->sealed[$id] ?? null;
        self::assertNotNull($scope, 'This scenario sealed no object for that document.');

        $root = $this->container->getParameter('erpify.documents.storage_root');
        self::assertIsString($root);
        $path = \sprintf(
            '%s/%s/%s/%s',
            $root,
            StorageArea::Final->value,
            \substr($scope, -4, 2) . '/' . \substr($scope, -2),
            $scope,
        );
        $bytes = \file_get_contents($path);
        self::assertIsString($bytes);
        $middle = \intdiv(\strlen($bytes), 2);
        $bytes[$middle] = \chr(\ord($bytes[$middle]) ^ 0xFF);
        \file_put_contents($path, $bytes);
    }

    #[Then('/^the response body should be "([^"]*)"$/')]
    public function theResponseBodyShouldBe(string $expected): void
    {
        $result = $this->httpResponseContainer->getResult();
        self::assertNotNull($result, 'No request was made before this step.');

        self::assertSame($expected, $result->getRawBody());
    }

    /**
     * @SuppressWarnings("PHPMD.Superglobals") see {@see rememberTheReadSwitch()}
     */
    #[AfterScenario]
    public function restoreTheReadSwitchAndDiscardTheSealedObjects(): void
    {
        $before = $this->before ?? ['env' => null, 'server' => null, 'process' => false];
        $this->before = null;

        $this->restoreEntry($_ENV, $before['env']);
        $this->restoreEntry($_SERVER, $before['server']);

        \putenv(false === $before['process'] ? self::VARIABLE : self::VARIABLE . '=' . $before['process']);

        foreach ($this->sealed as $scope) {
            $this->storage->delete(StorageArea::Staging, $scope);
            $this->storage->delete(StorageArea::Final, $scope);
        }

        $this->sealed = [];
    }

    /**
     * @SuppressWarnings("PHPMD.Superglobals") Symfony's env processor reads `$_ENV`/`$_SERVER` before `getenv()`,
     * so the switch has to land in all three for the next kernel to see it.
     */
    private function set(string $value): void
    {
        $_ENV[self::VARIABLE] = $value;
        $_SERVER[self::VARIABLE] = $value;
        \putenv(self::VARIABLE . '=' . $value);
    }

    /**
     * @param array<mixed> $bag `$_ENV` or `$_SERVER`
     */
    private function restoreEntry(array &$bag, ?string $previous): void
    {
        if (null === $previous) {
            unset($bag[self::VARIABLE]);

            return;
        }

        $bag[self::VARIABLE] = $previous;
    }
}
