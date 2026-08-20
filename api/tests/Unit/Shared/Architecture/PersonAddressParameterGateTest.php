<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Shared\Architecture;

use Erpify\Iam\Identity\Domain\Email;
use Erpify\Iam\Identity\Domain\Repository\UserRepository;
use Erpify\Tests\Support\ApiSourceFiles;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionNamedType;
use ReflectionParameter;
use ReflectionUnionType;
use SensitiveParameter;
use Throwable;

/**
 * Static gate over `api/.person-address-parameter-policy`: the registry that closes the axis
 * {@see SensitiveRecipientAddressGateTest} names but cannot cover — a person's address carried under a
 * parameter name other than `recipientEmail` (issue #769). That gate keys on a NAME; the same value crosses
 * this codebase as `$email`, `$to`, `$identifier`, `$emailIdentifier` and `$raw`, so a name key undercounts by
 * construction. This gate keys on a REGISTRY instead — every site classified `sensitive` or `excluded`, one
 * line per declaration — with a narrow, mechanically-discovered completeness floor on top: every parameter or
 * promoted property in `api/src` typed exactly `Email` must carry a line, of either verdict, because that
 * direction is cheap to verify by reflection and cannot go stale in the completeness sense.
 *
 * **What this gate does NOT attempt, and why.** The `Email`-typed sweep is real but narrow: at the time this
 * gate was written, every site in the registry was found holding the value as a bare `string` — the exact
 * shape a type-keyed rule fails to reach (see #769's second comment, which rejected pure type-based
 * classification for this reason: `NotificationMailer::send(string $to)` cannot be soundly retyped without
 * either widening a published port's contract or losing the one caller that legitimately passes a
 * non-person address). There is no dataflow/taint engine in this toolchain (Psalm was removed; the Semgrep
 * ruleset covers three `Request`-sourced flows, not general taint — see `api/CLAUDE.md`) that could soundly
 * trace an arbitrary `string $foo` to "becomes a person's address three calls later" without false-negating
 * real sites or false-positiving every other string parameter in the tree. So the string-typed population in
 * the registry is not, and cannot be, mechanically complete — it is what a human adversarial pass found
 * (the same control `../CLAUDE.md`'s security-review process already mandates for this class of change), and
 * this gate's job is to keep what was found from rotting, not to find the next one by itself.
 *
 * Four directions, all mechanical:
 *
 *   - **Staleness** — every registry line must still resolve to a real, currently-declared parameter or
 *     promoted property. A line naming a renamed or deleted site is worse than no line: it reads as coverage.
 *   - **Enforcement** — every `sensitive` line's site must carry `#[SensitiveParameter]`. PHP does not enforce
 *     attribute agreement between an interface and its implementation, so both sides of a port/adapter pair
 *     are independent sites here, exactly as {@see SensitiveRecipientAddressGateTest} already treats them.
 *   - **Completeness of the narrow half** — every parameter or promoted property in `api/src` typed exactly
 *     `Email` (nullable included) must have a line. This is the one direction that cannot silently drift: a
 *     future caller that starts typing a parameter `Email` joins the universe the moment it is reflected,
 *     with no dependence on anyone remembering to add a line first.
 *   - **Verdict hygiene** — a line's verdict must be exactly `sensitive` or `excluded :: <non-empty reason>`.
 *     Nothing here judges whether an `excluded` reason is actually true; the registry header states that
 *     limit and the review process is the only control on that direction — same asymmetry
 *     `.person-reference-policy` documents for its own classification.
 *
 * @internal
 */
#[CoversNothing]
final class PersonAddressParameterGateTest extends TestCase
{
    private const string REGISTRY_RELATIVE_PATH = '/.person-address-parameter-policy';

    private const string SENSITIVE_VERDICT = 'sensitive';

    private const string EXCLUDED_PREFIX = 'excluded :: ';

    private const string SITE_PATTERN
        = '/^(?<fqcn>[A-Za-z0-9_\\\]+)::(?<method>[A-Za-z0-9_]+)\(\$(?<param>[A-Za-z0-9_]+)\)\s*=>\s*(?<verdict>.+)$/';

    #[Test]
    public function everyRegistryLineStillNamesADeclaredSite(): void
    {
        $stale = [];

        foreach ($this->registryLines() as $site => ['fqcn' => $fqcn, 'method' => $method, 'param' => $param]) {
            if (!$this->resolveParameter($fqcn, $method, $param) instanceof ReflectionParameter) {
                $stale[] = $site;
            }
        }

        \sort($stale);

        $this->assertSame([], $stale, \sprintf(
            "These lines in .person-address-parameter-policy no longer resolve to a declared parameter or\n"
            . "promoted property — the class, method or parameter was renamed, removed, or never existed:\n  %s",
            \implode("\n  ", $stale),
        ));
    }

    #[Test]
    public function everySensitiveSiteCarriesTheAttribute(): void
    {
        $bare = [];

        foreach ($this->registryLines() as $site => $entry) {
            if (self::SENSITIVE_VERDICT !== $entry['verdict']) {
                continue;
            }

            $parameter = $this->resolveParameter($entry['fqcn'], $entry['method'], $entry['param']);

            if (!$parameter instanceof ReflectionParameter) {
                // Staleness is asserted by its own test; a line that cannot resolve says nothing here either
                // way, and duplicating the failure would obscure which test to fix first.
                continue;
            }

            if ([] === $parameter->getAttributes(SensitiveParameter::class)) {
                $bare[] = $site;
            }
        }

        \sort($bare);

        $this->assertSame([], $bare, \sprintf(
            "These sites are classified `sensitive` in .person-address-parameter-policy but do not carry\n"
            . "#[SensitiveParameter], so a person's address at that declaration rides a stack-trace frame in\n"
            . "clear the day zend.exception_ignore_args is ever weakened:\n  %s",
            \implode("\n  ", $bare),
        ));
    }

    #[Test]
    public function everyEmailTypedSiteIsClassifiedInTheRegistry(): void
    {
        $classified = \array_keys(\iterator_to_array($this->registryLines()));
        $unclassified = \array_values(\array_diff($this->emailTypedSites(), $classified));

        \sort($unclassified);

        $this->assertSame([], $unclassified, \sprintf(
            "These parameters or promoted properties are typed Email but carry no line in\n"
            . ".person-address-parameter-policy — classify them `sensitive` or `excluded :: <reason>`:\n  %s",
            \implode("\n  ", $unclassified),
        ));
    }

    #[Test]
    public function theRegistryClassifiesOnlyRecognisedVerdicts(): void
    {
        $unrecognised = [];

        foreach ($this->registryLines() as $site => $entry) {
            $verdict = $entry['verdict'];
            $isSensitive = self::SENSITIVE_VERDICT === $verdict;
            $isExcluded = \str_starts_with($verdict, self::EXCLUDED_PREFIX)
                && '' !== \trim(\substr($verdict, \strlen(self::EXCLUDED_PREFIX)));

            if (!$isSensitive && !$isExcluded) {
                $unrecognised[] = \sprintf('%s => %s', $site, $verdict);
            }
        }

        \sort($unrecognised);

        $this->assertSame([], $unrecognised, \sprintf(
            "These lines carry a verdict that is neither exactly `sensitive` nor `excluded :: <non-empty\n"
            . "reason>` — a typo here silently drops the site from every check above:\n  %s",
            \implode("\n  ", $unrecognised),
        ));
    }

    #[Test]
    public function theGateScansTheRealTreeAndCanFire(): void
    {
        $lines = \iterator_to_array($this->registryLines());

        $this->assertNotEmpty($lines, 'The registry is empty — the completeness and enforcement checks above '
            . 'would pass vacuously.');

        $this->assertNotEmpty(
            \array_filter($lines, static fn (array $entry): bool => self::SENSITIVE_VERDICT === $entry['verdict']),
            'The registry classifies nothing as sensitive, so the attribute-enforcement check can never fire.',
        );

        $this->assertNotEmpty(
            $this->emailTypedSites(),
            \sprintf(
                'The reflection sweep found zero Email-typed parameters under api/src, so the one direction '
                . 'that cannot go stale by construction is currently doing nothing — verify the sweep still '
                . 'reaches %s::findByEmail before trusting a green here.',
                UserRepository::class,
            ),
        );
    }

    /**
     * @return iterable<string, array{fqcn: string, method: string, param: string, verdict: string}>
     */
    private function registryLines(): iterable
    {
        $path = \dirname(__DIR__, 4) . self::REGISTRY_RELATIVE_PATH;
        $this->assertFileExists($path, 'the person-address-parameter registry is gone');

        $contents = \file_get_contents($path);
        $this->assertIsString($contents);

        $unparseable = [];

        foreach (\explode("\n", $contents) as $line) {
            $trimmed = \trim($line);

            if ('' === $trimmed || \str_starts_with($trimmed, '#')) {
                continue;
            }

            if (1 !== \preg_match(self::SITE_PATTERN, $trimmed, $matches)) {
                $unparseable[] = $trimmed;

                continue;
            }

            $site = \sprintf('%s::%s($%s)', $matches['fqcn'], $matches['method'], $matches['param']);

            yield $site => [
                'fqcn' => $matches['fqcn'],
                'method' => $matches['method'],
                'param' => $matches['param'],
                'verdict' => $matches['verdict'],
            ];
        }

        if ([] !== $unparseable) {
            $this->fail(\sprintf(
                "Unparseable lines in .person-address-parameter-policy:\n  %s",
                \implode("\n  ", $unparseable),
            ));
        }
    }

    private function resolveParameter(string $fqcn, string $method, string $param): ?ReflectionParameter
    {
        try {
            $declared = \class_exists($fqcn) || \interface_exists($fqcn) || \trait_exists($fqcn);
        } catch (Throwable) {
            return null;
        }

        if (!$declared) {
            return null;
        }

        $class = new ReflectionClass($fqcn);

        if (!$this->isDeclaredUnderApiSource($class) || !$class->hasMethod($method)) {
            return null;
        }

        foreach ($class->getMethod($method)->getParameters() as $reflectionParameter) {
            if ($reflectionParameter->getName() === $param) {
                return $reflectionParameter;
            }
        }

        return null;
    }

    /**
     * Scoped to api/src, matching emailTypedSites() below — otherwise a registry line naming a class that
     * happens to resolve outside the tree (a test double, a vendor class) could pass staleness undetected.
     *
     * @param ReflectionClass<object> $class
     */
    private function isDeclaredUnderApiSource(ReflectionClass $class): bool
    {
        return \str_starts_with((string) $class->getFileName(), ApiSourceFiles::root() . '/');
    }

    /**
     * Every parameter or promoted property under `api/src` typed exactly `Email` (nullable included), keyed
     * in the same `<Fqcn>::<method>($<param>)` shape the registry uses. Compared by declaring FILE rather than
     * declaring class, matching {@see SensitiveRecipientAddressGateTest}: a trait-imported method would
     * otherwise be attributed to every using class and counted once per adapter.
     *
     * @return list<string>
     */
    private function emailTypedSites(): array
    {
        $root = ApiSourceFiles::root();
        $sites = [];

        foreach (ApiSourceFiles::phpFiles($root) as $file) {
            $relative = \substr($file->getPathname(), \strlen($root) + 1);
            $fqcn = 'Erpify\\' . \str_replace('/', '\\', \substr($relative, 0, -4));

            try {
                $declared = \class_exists($fqcn) || \interface_exists($fqcn) || \trait_exists($fqcn);
            } catch (Throwable $throwable) {
                $this->fail(\sprintf('"%s" could not be loaded: %s', $relative, $throwable->getMessage()));
            }

            if (!$declared) {
                continue;
            }

            \array_push($sites, ...$this->emailTypedSitesIn(new ReflectionClass($fqcn), $file->getPathname()));
        }

        return $sites;
    }

    /**
     * @param ReflectionClass<object> $class
     *
     * @return list<string>
     */
    private function emailTypedSitesIn(ReflectionClass $class, string $path): array
    {
        $sites = [];

        foreach ($class->getMethods() as $reflectionMethod) {
            if ($reflectionMethod->getFileName() !== $path) {
                continue;
            }

            foreach ($reflectionMethod->getParameters() as $reflectionParameter) {
                if (!$this->isEmailTyped($reflectionParameter)) {
                    continue;
                }

                $sites[] = \sprintf(
                    '%s::%s($%s)',
                    $reflectionMethod->getDeclaringClass()->getName(),
                    $reflectionMethod->getName(),
                    $reflectionParameter->getName(),
                );
            }
        }

        return $sites;
    }

    private function isEmailTyped(ReflectionParameter $parameter): bool
    {
        $type = $parameter->getType();

        if ($type instanceof ReflectionUnionType) {
            return \array_any(
                $type->getTypes(),
                fn (object $member): bool => $member instanceof ReflectionNamedType
                    && $this->namedTypeIsEmail($member, $parameter),
            );
        }

        return $type instanceof ReflectionNamedType && $this->namedTypeIsEmail($type, $parameter);
    }

    /**
     * `self` never resolves to `Email::class` via {@see ReflectionNamedType::getName()} — it stays the literal
     * string `"self"` — so a `self`-typed parameter (e.g. {@see Email::equals()}) needs its declaring class
     * resolved explicitly, or it is invisible to the completeness sweep despite being a real `Email` site.
     */
    private function namedTypeIsEmail(ReflectionNamedType $type, ReflectionParameter $parameter): bool
    {
        $name = 'self' === $type->getName() ? $parameter->getDeclaringClass()?->getName() : $type->getName();

        return Email::class === $name;
    }
}
