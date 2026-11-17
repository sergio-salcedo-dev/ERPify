<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Shared\Images;

use Erpify\Tests\Support\RepositoryRoot;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * `GET /api/v1/images/{imageId}` serves any image to any session, and what makes that defensible is that every
 * aggregate holding an image brings its own read policy. This is what keeps that from being prose.
 *
 * **The decision lives in the ADR and is not reopened here** (`docs/adr/image-read-route-contract.md`, D1). What this
 * holds is the bookkeeping: every property of an aggregate that holds an `ImageId` is in {@see self::REGISTRY}, and
 * each entry names the anchor in D1 under which its audience, its audit type and its cache window were decided. A
 * consumer is therefore a one-line diff here PLUS the paragraph it points at, and neither can be written alone — an
 * entry whose anchor the ADR lacks reds, and so does a holder with no entry.
 *
 * **The universe is derived from the code, not listed.** Any property declared (or promoted) under `src` outside
 * `Shared/Images` whose name ends in `image_id`, in any case, and any ORM column named so. The registry is what is
 * written by hand; what it is compared against is what the tree contains, in both directions.
 *
 * **Blind spots, stated because a green here is narrower than it reads.** A holder under another name (`$logo`,
 * `$picture`), in a join table, in a JSON column or reached through a service rather than a field is invisible. A
 * property is judged by its declaration, so a holder that exists only as a method pair is invisible too. It never
 * judges whether the policy the anchor points at is any good, nor that the anchor is the right one: it proves the
 * paragraph exists, not what it says. It is a floor on the accidental case, never a ceiling on the deliberate one.
 *
 * @internal
 */
#[CoversNothing]
final class ImageConsumerAuthorizationGateTest extends TestCase
{
    private const string SRC = __DIR__ . '/../../../../src';

    private const string ADR = '/docs/adr/image-read-route-contract.md';

    /** The module that owns the identifier; a reference inside it is not a consumer relation. */
    private const string OWNING_MODULE = 'Shared/Images';

    /**
     * `<file relative to src>::<holder property>` (or `…::column:<name>` for a mapped column no property reveals) =>
     * the anchor in D1 of {@see self::ADR} that records its read audience, audit type and cache window. The file is
     * part of the key because two aggregates may spell a holder alike and each owes its own policy. A consumer story
     * adds its line and the paragraph together.
     *
     * @var array<string, string>
     */
    private const array REGISTRY = [];

    /**
     * A declared or promoted property: a visibility word (with PHP 8.4's `(set)` / `(get)` asymmetric form) or `var`
     * first, then any further modifiers; or `readonly` / `final` first, then the same. A bare `static` is no
     * declaration here — it is also how a local variable is made static.
     */
    private const string PROPERTY_DECLARATION = '/(?:\b(?:public|protected|private|var)(?:\((?:set|get)\))?\s+'
        . '|\b(?:readonly|final)\s+)'
        . '(?:(?:public|protected|private|readonly|static|final)(?:\((?:set|get)\))?\s+)*'
        . '(?:\??[\w\\\|&()]+\s+)?\$(\w*image_?id)\b/i';

    /** A `Column` attribute, aliased or not, whose `name:` ends in `image_id` wherever it sits among the arguments. */
    private const string COLUMN_NAME = '/\bColumn\((?:(?!\)\]).)*?\bname:\s*[\'"](\w*image_?id)[\'"]/is';

    #[Test]
    public function everyAggregateHoldingAnImageHasARegisteredReadPolicyRecordedInTheAdr(): void
    {
        $this->assertSame(
            [],
            $this->registryProblems($this->holdersUnderSrc(), self::REGISTRY, $this->decisionD1()),
        );
    }

    /**
     * The check itself, driven over planted holders and a planted D1 so it is exercised while the real registry is
     * empty: an unregistered holder is named, an entry whose anchor D1 lacks is named, a stale entry is named, and a
     * registered holder whose anchor D1 records passes.
     */
    #[Test]
    public function aHolderWithoutAnEntryFailsNamingItAndOneWithItsAnchorPasses(): void
    {
        $d1 = 'Per-consumer policy: `D1:logo` records who reads it.';
        $foo = 'Backoffice/Foo/Domain/Foo.php::fooImageId';
        $holders = [$foo => 'Backoffice/Foo/Domain/Foo.php'];

        $unregistered = $this->registryProblems($holders, [], $d1);
        $this->assertCount(1, $unregistered);
        $this->assertStringContainsString($foo, $unregistered[0]);

        $this->assertSame([], $this->registryProblems($holders, [$foo => 'D1:logo'], $d1));

        $missingAnchor = $this->registryProblems($holders, [$foo => 'D1:foo'], $d1);
        $this->assertCount(1, $missingAnchor);
        $this->assertStringContainsString('D1:foo', $missingAnchor[0]);

        $notAnAnchor = $this->registryProblems($holders, [$foo => 'logo'], $d1);
        $this->assertCount(1, $notAnAnchor);

        $stale = $this->registryProblems([], [$foo => 'D1:logo'], $d1);
        $this->assertCount(1, $stale);
        $this->assertStringContainsString($foo, $stale[0]);
    }

    /**
     * Two aggregates that spell their holder alike are two holders: registering one leaves the other named.
     */
    #[Test]
    public function aHolderSharingItsSpellingWithARegisteredOneStillFails(): void
    {
        $d1 = '`D1:logo`';
        $first = 'Backoffice/Bank/Domain/Entity/Bank.php::logoImageId';
        $second = 'Backoffice/Brand/Domain/Entity/Brand.php::logoImageId';

        $problems = $this->registryProblems(
            [$first => 'Backoffice/Bank/Domain/Entity/Bank.php', $second => 'Backoffice/Brand/Domain/Entity/Brand.php'],
            [$first => 'D1:logo'],
            $d1,
        );

        $this->assertCount(1, $problems);
        $this->assertStringContainsString($second, $problems[0]);
    }

    /**
     * @param array<string, string> $holders  `file::holder` => the file declaring it
     * @param array<string, string> $registry `file::holder` => anchor in D1
     *
     * @return list<string>
     */
    private function registryProblems(array $holders, array $registry, string $decision): array
    {
        $problems = [];

        foreach (\array_diff_key($holders, $registry) as $holder => $file) {
            $problems[] = \sprintf(
                "%s (%s) holds an image and has no registered read policy.\n"
                . '`GET /api/v1/images/{imageId}` carries no `#[IsGranted]`, and the argument for that '
                . '(`docs/adr/image-read-route-contract.md`, D1) is that every consumer brings its own read policy. '
                . "This is the change that has to write it:\n"
                . '  - decide who may read this image, whether the read is audited and for how long a client may '
                . "keep it, and record it in D1 under an anchor `D1:<name>`;\n"
                . "  - add `file::holder => anchor` to REGISTRY in this test.\n"
                . 'Deleting this test is not one of the options.',
                $holder,
                $file,
            );
        }

        foreach (\array_diff_key($registry, $holders) as $holder => $anchor) {
            $problems[] = \sprintf(
                'REGISTRY names %s (%s), which the tree no longer declares. Remove the entry and its paragraph in D1.',
                $holder,
                $anchor,
            );
        }

        foreach (\array_intersect_key($registry, $holders) as $holder => $anchor) {
            $wellFormed = 1 === \preg_match('/^D1:[A-Za-z][A-Za-z0-9_.-]*$/', $anchor);

            if (!$wellFormed || !\str_contains($decision, '`' . $anchor . '`')) {
                $problems[] = \sprintf(
                    '%s points at `%s`, which is not an anchor D1 of %s records.',
                    $holder,
                    $anchor,
                    self::ADR,
                );
            }
        }

        return $problems;
    }

    /**
     * The ADR is read through the read-only `./` bind mount; a missing mount must fail the run rather than let the
     * anchor check go quiet.
     */
    #[Test]
    public function theAdrDecisionTheAnchorsPointIntoIsReachable(): void
    {
        $this->assertStringContainsString('Authentication is the whole frontier', $this->decisionD1());
    }

    /**
     * The derivation, driven over planted sources so that it is exercised while the registry is empty: a declared or
     * promoted property, in any case and in any modifier order, and a mapped column.
     */
    #[Test]
    public function theDerivationFindsEveryDeclaredOrPromotedHolder(): void
    {
        $found = $this->holdersIn(<<<'PHP'
            <?php
            final class Planted
            {
                private ?string $logoImageId = null;
                public readonly ?string $avatar_image_id;
                protected static $accountImageId;
                readonly private string $cardImageID;
                var $legacyImageId;
                private(set) ?string $asymImageId = null;
                public private(set) string $dualImageId;
                protected(set) string $protImageId;
                final public string $finalImageId;
                public function __construct2(readonly string $promotedImageId, readonly ?string $typedImageId = null)
                {
                }

                public function __construct3(private(set) ?string $promotedAsymImageId = null)
                {
                }

                public function __construct(private ?string $heroImageId = null, string $notAProperty = '')
                {
                }

                #[ORM\Column(name: 'scan_image_id', type: Types::GUID)]
                private ?string $scan = null;

                #[ORM\Column(type: Types::GUID, options: ['comment' => 'x'], name: 'badge_image_id')]
                private ?string $badge = null;

                #[Column(name: "plain_image_id")]
                private ?string $plain = null;

                #[ORM\Id, ORM\Column(length: 36, name: 'multi_image_id')]
                private ?string $multi = null;
            }
            PHP);

        \sort($found);
        $this->assertSame(
            [
                'accountImageId',
                'asymImageId',
                'avatar_image_id',
                'cardImageID',
                'column:badge_image_id',
                'column:multi_image_id',
                'column:plain_image_id',
                'column:scan_image_id',
                'dualImageId',
                'finalImageId',
                'heroImageId',
                'legacyImageId',
                'logoImageId',
                'promotedAsymImageId',
                'promotedImageId',
                'protImageId',
                'typedImageId',
            ],
            $found,
        );
    }

    /**
     * Nothing a comment, a local variable, a static local or a bare parameter says is a holder.
     */
    #[Test]
    public function theDerivationFindsNothingThatIsNotADeclaration(): void
    {
        $this->assertSame([], $this->holdersIn(<<<'PHP'
            <?php
            /** private ?string $commentedImageId */
            // public $alsoCommentedImageId
            function read(string $imageId): void { $localImageId = $imageId; $this->usedImageId = 1; }
            function memo(): void { static $cachedImageId; static ?string $typedCachedImageId = null; }
            PHP));
    }

    /**
     * Without this the assertion above is satisfied by a walk that found no files at all — a moved module or
     * a broken path reads exactly like a clean tree.
     */
    #[Test]
    public function theSweepReachesTheSourceTree(): void
    {
        $this->assertGreaterThan(100, \iterator_count($this->phpFilesUnderSrc()));
    }

    /**
     * @return array<string, string> `file::holder` => the file declaring it, relative to `src`
     */
    private function holdersUnderSrc(): array
    {
        $holders = [];

        foreach ($this->phpFilesUnderSrc() as $file) {
            $relative = \str_replace('\\', '/', \substr($file->getPathname(), \strlen($this->srcRoot()) + 1));

            if (\str_starts_with($relative, self::OWNING_MODULE . '/')) {
                continue;
            }

            foreach ($this->holdersIn((string) \file_get_contents($file->getPathname())) as $holder) {
                $holders[$relative . '::' . $holder] = $relative;
            }
        }

        \ksort($holders);

        return $holders;
    }

    /**
     * @return list<string>
     */
    private function holdersIn(string $source): array
    {
        $code = '';

        foreach (\token_get_all($source) as $token) {
            if (\is_array($token) && \in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            $code .= \is_array($token) ? $token[1] : $token;
        }

        \preg_match_all(self::PROPERTY_DECLARATION, $code, $properties);
        \preg_match_all(self::COLUMN_NAME, $code, $columns);

        return \array_values(\array_unique([
            ...$properties[1],
            ...\array_map(static fn (string $name): string => 'column:' . $name, $columns[1]),
        ]));
    }

    private function decisionD1(): string
    {
        $root = RepositoryRoot::path() ?? $this->fail(
            'The ADR is unreachable: restore the read-only `./` bind mount at /app/repo.',
        );
        $adr = (string) \file_get_contents($root . self::ADR);

        $found = \preg_match('/^### D1 .*?(?=^### D2 )/ms', $adr, $decision);
        $this->assertSame(1, $found, 'D1 is no longer in the ADR.');

        return $decision[0];
    }

    private function srcRoot(): string
    {
        return (string) \realpath(self::SRC);
    }

    /**
     * @return iterable<SplFileInfo>
     */
    private function phpFilesUnderSrc(): iterable
    {
        /** @var iterable<SplFileInfo> $files */
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(
            $this->srcRoot(),
            RecursiveDirectoryIterator::SKIP_DOTS,
        ));

        foreach ($files as $file) {
            if ($file->isFile() && 'php' === $file->getExtension()) {
                yield $file;
            }
        }
    }
}
