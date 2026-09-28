<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Shared\Event\Application;

use Erpify\Shared\Event\Application\SubjectPseudonym;
use Erpify\Shared\Event\Application\SubjectPseudonymisation;
use Erpify\Shared\Uuid\Domain\InvalidUuidException;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The pair is interpolated into a regular expression and into a `LIKE` pattern by the event-store adapter, so
 * the UUID guards here are the safety argument for that interpolation: without a test they are
 * indistinguishable from a comment claiming they exist.
 *
 * @internal
 */
#[CoversClass(SubjectPseudonymisation::class)]
#[CoversClass(SubjectPseudonym::class)]
final class SubjectPseudonymisationTest extends TestCase
{
    private const string SUBJECT_ID = '0190f400-0000-7000-8000-0000000000d1';

    private const string PSEUDONYM = '0190f400-0000-7000-8000-0000000000d2';

    #[Test]
    public function itCarriesBothMembersByName(): void
    {
        $pair = SubjectPseudonymisation::of(
            subjectId: self::SUBJECT_ID,
            pseudonym: SubjectPseudonym::fromString(self::PSEUDONYM),
        );

        $this->assertSame(self::SUBJECT_ID, $pair->subjectId);
        $this->assertSame(self::PSEUDONYM, $pair->pseudonym);
    }

    #[Test]
    public function itRefusesAMalformedSubject(): void
    {
        $this->expectException(InvalidUuidException::class);

        // `%` and `.` are syntax in both a `LIKE` pattern and a regular expression.
        SubjectPseudonymisation::of(subjectId: '%.*%', pseudonym: SubjectPseudonym::fromString(self::PSEUDONYM));
    }

    #[Test]
    public function itRefusesAMalformedPseudonym(): void
    {
        $this->expectException(InvalidUuidException::class);

        // The replacement string has its own syntax in Postgres — `\1`…`\9` and `\&` — so it needs the same
        // guard as the pattern, and for a different reason.
        SubjectPseudonym::fromString('\1');
    }

    #[Test]
    public function itRefusesAPseudonymThatIsTheSubjectInAnotherCase(): void
    {
        $this->expectException(InvalidArgumentException::class);

        // The rewrite would report every matched row as anonymised while the id stays in the log.
        SubjectPseudonymisation::of(
            subjectId: self::SUBJECT_ID,
            pseudonym: SubjectPseudonym::fromString(\strtoupper(self::SUBJECT_ID)),
        );
    }

    #[Test]
    public function itRefusesAPseudonymThatIsTheSubjectVerbatim(): void
    {
        $this->expectException(InvalidArgumentException::class);

        SubjectPseudonymisation::of(
            subjectId: self::SUBJECT_ID,
            pseudonym: SubjectPseudonym::fromString(self::SUBJECT_ID),
        );
    }
}
