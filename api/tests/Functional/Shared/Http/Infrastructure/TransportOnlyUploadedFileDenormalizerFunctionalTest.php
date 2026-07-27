<?php

declare(strict_types=1);

namespace Erpify\Tests\Functional\Shared\Http\Infrastructure;

use Erpify\Shared\Http\Infrastructure\TransportOnlyUploadedFileDenormalizer;
use Erpify\Tests\Functional\Shared\Http\Infrastructure\Fixtures\PlainFileBearingPayload;
use Erpify\Tests\Functional\Shared\Http\Infrastructure\Fixtures\UploadBearingPayload;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Serializer\Exception\NotNormalizableValueException;
use Symfony\Component\Serializer\Normalizer\DenormalizerInterface;

/**
 * An upload reaches a payload through the request's file bag or not at all.
 *
 * A payload member typed `UploadedFile` is filled from the merge of the parsed body and
 * `$request->files`, so it is reachable from either side — and `UploadedFile` is a value the serializer
 * can build out of body data: `path` and `originalName` are constructor parameters, and `test` switches
 * off the `is_uploaded_file()` check that `FileValidator` short-circuits on. Left open, a caller names a
 * server path and has it read, stored and served back, with `#[Assert\File]` passing because it inspects
 * the real bytes at that path. A path that does not exist reaches `File::__construct` and throws
 * `FileNotFoundException`, which carries no error-contract marker and surfaces as a 500 — one request per
 * probe of the container's filesystem.
 *
 * Driven through the container's serializer rather than the rule in isolation: a unit test of
 * {@see TransportOnlyUploadedFileDenormalizer} passes just as happily when the service is no longer in the
 * normalizer chain, which is the way the protection is most likely to be lost.
 *
 * @internal
 */
#[CoversClass(TransportOnlyUploadedFileDenormalizer::class)]
final class TransportOnlyUploadedFileDenormalizerFunctionalTest extends KernelTestCase
{
    /**
     * A file every container has, that a caller has no business reading through the API.
     */
    private const string EXFILTRATION_TARGET = '/etc/hostname';

    private const string ABSENT_PATH = '/etc/erpify-does-not-exist-probe';

    public function testABodyDescribingAnUploadIsRefusedInsteadOfReadingTheNamedFile(): void
    {
        $exception = null;

        try {
            $this->denormalizer()->denormalize(
                ['name' => 'Exfiltration probe', 'image' => $this->describeUpload(self::EXFILTRATION_TARGET)],
                UploadBearingPayload::class,
            );
        } catch (NotNormalizableValueException $notNormalizableValueException) {
            $exception = $notNormalizableValueException;
        }

        $this->assertInstanceOf(
            NotNormalizableValueException::class,
            $exception,
            'a request body described an upload and the serializer built one — the named file is readable',
        );
        $this->assertStringNotContainsString(
            (string) \file_get_contents(self::EXFILTRATION_TARGET),
            $exception->getMessage(),
            'the refusal must not carry the bytes it refused to hand over',
        );
    }

    /**
     * The vector belongs to `File`, not to `UploadedFile`: a member typed as any other `File` subclass is
     * constructible from a path just the same, and `checkPath: false` drops even the FileNotFoundException
     * that would otherwise mark an absent path. A guard anchored on `UploadedFile` leaves this unclaimed.
     */
    public function testABodyDescribingAPlainFileIsRefusedOnTheSameTerms(): void
    {
        $exception = null;

        try {
            $this->denormalizer()->denormalize(
                [
                    'name' => 'Exfiltration probe',
                    'image' => ['path' => self::EXFILTRATION_TARGET, 'checkPath' => false],
                ],
                PlainFileBearingPayload::class,
            );
        } catch (NotNormalizableValueException $notNormalizableValueException) {
            $exception = $notNormalizableValueException;
        }

        $this->assertInstanceOf(
            NotNormalizableValueException::class,
            $exception,
            'a request body described a plain File and the serializer built one — the named file is readable',
        );
        $this->assertStringNotContainsString(
            (string) \file_get_contents(self::EXFILTRATION_TARGET),
            $exception->getMessage(),
            'the refusal must not carry the bytes it refused to hand over',
        );
    }

    /**
     * The refusal has to reach the caller. With expected types attached the argument resolver renders
     * "This value should be of type …" and the authored sentence never leaves the process.
     */
    public function testTheRefusalReachesTheCallerAsItsOwnSentence(): void
    {
        $exception = null;

        try {
            $this->denormalizer()->denormalize(
                ['name' => 'Exfiltration probe', 'image' => $this->describeUpload(self::EXFILTRATION_TARGET)],
                UploadBearingPayload::class,
            );
        } catch (NotNormalizableValueException $notNormalizableValueException) {
            $exception = $notNormalizableValueException;
        }

        $this->assertInstanceOf(NotNormalizableValueException::class, $exception);
        $this->assertTrue(
            $exception->canUseMessageForUser(),
            'the refusal is authored for the caller; unmarked, the resolver drops it',
        );
        $this->assertSame(
            [],
            $exception->getExpectedTypes() ?? [],
            'any expected type wins over the message and renders a description of the member instead',
        );
    }

    /**
     * The same shape naming a path that does not exist must be refused on the same terms. Any difference a
     * caller can observe between the two — a distinct exception, a distinct status — is a filesystem
     * existence oracle they can walk one request at a time.
     */
    public function testABodyNamingAnAbsentPathIsRefusedOnTheSameTermsAsAnExistingOne(): void
    {
        $this->assertFileDoesNotExist(self::ABSENT_PATH);

        $this->expectException(NotNormalizableValueException::class);

        $this->denormalizer()->denormalize(
            ['name' => 'Existence probe', 'image' => $this->describeUpload(self::ABSENT_PATH)],
            UploadBearingPayload::class,
        );
    }

    /**
     * The other half of the rule, and the reason it is written as "not already an `UploadedFile`" rather
     * than "never an `UploadedFile`": a genuine multipart upload arrives as the object itself and has to
     * pass through untouched. A refusal that also blocked real uploads would take the endpoint with it.
     */
    public function testAGenuineUploadObjectPassesThrough(): void
    {
        $upload = new UploadedFile(self::EXFILTRATION_TARGET, 'hostname.txt', test: true);

        $payload = $this->denormalizer()->denormalize(
            ['name' => 'Genuine upload', 'image' => $upload],
            UploadBearingPayload::class,
        );

        $this->assertSame($upload, $payload->image);
    }

    /**
     * A payload with no file member at all is untouched by the rule — it claims `UploadedFile` and nothing
     * else, so ordinary mapping cannot be collateral damage.
     */
    public function testAPayloadWithoutAFileMemberIsUnaffected(): void
    {
        $payload = $this->denormalizer()->denormalize(['name' => 'No file'], UploadBearingPayload::class);

        $this->assertSame('No file', $payload->name);
        $this->assertNotInstanceOf(UploadedFile::class, $payload->image);
    }

    /**
     * The body shape that makes the attack work: `test: true` is what stops `isValid()` consulting
     * `is_uploaded_file()`, so the constructed object passes for a real upload everywhere downstream.
     *
     * @return array<string, string|bool>
     */
    private function describeUpload(string $path): array
    {
        return ['path' => $path, 'originalName' => 'logo.png', 'mimeType' => 'image/png', 'test' => true];
    }

    private function denormalizer(): DenormalizerInterface
    {
        $denormalizer = self::getContainer()->get(DenormalizerInterface::class);
        $this->assertInstanceOf(DenormalizerInterface::class, $denormalizer);

        return $denormalizer;
    }
}
