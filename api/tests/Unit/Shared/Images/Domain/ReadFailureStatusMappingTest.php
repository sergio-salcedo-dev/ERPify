<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Shared\Images\Domain;

use Erpify\Shared\ErrorContract\Application\ProblemDetailsFactory;
use Erpify\Shared\Images\Domain\Read\ImageNotAvailable;
use Erpify\Shared\Images\Domain\Read\ImageTemporarilyUnavailable;
use Erpify\Shared\Images\Domain\Read\UnservableImage;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;
use Throwable;

/**
 * The status and `type` each read failure answers with, asserted through the factory that decides them.
 *
 * **These three classes exist ONLY to produce these answers, and nothing was asserting the answers.** Every
 * test that names them checks the exception CLASS — `expectException(ImageTemporarilyUnavailable::class)` —
 * which is satisfied whatever that class maps to. So the mapping was reachable by a one-token edit with the
 * whole suite green: change `extends DomainException` to `extends RuntimeException`, or drop
 * `implements ServiceUnavailable`, and every retryable storage failure starts answering 500 while
 * `docs/architecture-api.md` and the Postman collection keep publishing 503. `ErrorContractGateTest` cannot
 * see it either — its universe is `Shared/ErrorContract/Domain/Exception/` alone.
 *
 * The mechanism the mapping rests on is why the classes are shaped as they are: `ProblemDetailsFactory`
 * resolves a marker inside the `instanceof DomainException` arm of its `match`, so a marker carried by
 * anything else is never read. `UnservableImage` sits outside that arm and carries no marker deliberately,
 * and that is asserted here too — a marker added to it later would be a silent change of wire contract.
 *
 * @internal
 */
#[CoversNothing]
final class ReadFailureStatusMappingTest extends TestCase
{
    private const string CID = '00000000-0000-7000-8000-000000000000';

    private const string INSTANCE = '/api/v1/images/019831b7-0000-7000-8000-00000000dead';

    #[Test]
    #[DataProvider('provideEachReadFailureAnswersItsDocumentedStatusCases')]
    public function eachReadFailureAnswersItsDocumentedStatus(
        Throwable $failure,
        int $expectedStatus,
        string $expectedType,
    ): void {
        $problem = (new ProblemDetailsFactory('prod', new NullLogger()))
            ->fromThrowable($failure, self::CID, self::INSTANCE)
        ;

        $this->assertSame($expectedStatus, $problem->status);
        $this->assertSame($expectedType, $problem->type);
    }

    /**
     * @return iterable<string, array{Throwable, int, string}>
     */
    public static function provideEachReadFailureAnswersItsDocumentedStatusCases(): iterable
    {
        yield 'an absent row or absent bytes' => [
            ImageNotAvailable::forRequestedImage(),
            404,
            'not-found',
        ];

        yield 'a retryable substrate failure' => [
            ImageTemporarilyUnavailable::fromStorageFailure(new RuntimeException('substrate')),
            503,
            'service-unavailable',
        ];

        yield 'an object whose bytes fail their own digest' => [
            UnservableImage::becauseTheDigestDoesNotMatch(),
            500,
            'unhandled-exception',
        ];

        yield 'an object above the serving budget' => [
            UnservableImage::becauseItExceedsTheServingBudget(),
            500,
            'unhandled-exception',
        ];
    }
}
