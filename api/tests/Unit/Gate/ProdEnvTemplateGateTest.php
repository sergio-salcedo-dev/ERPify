<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Gate;

use Erpify\Tests\Support\RepositoryRoot;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Every key the prod stack requires is declared by `.env.prod.example`, and a copied template fails the check.
 *
 * Compose aborts on a required interpolation left unset — `${VAR:?…}` or `${VAR?…}` in either file the prod
 * stack loads — and `make prod.env.check` derives its required set from those same interpolations. The
 * template is the third copy and the only hand-written one: it is what an operator copies to
 * `.env.prod.local`, so a key it omits is discovered by the deploy failing, and a key it ships with a
 * plausible-looking value passes the check and runs the stack on that value.
 *
 * A required key may carry a usable default only when it is listed in {@see self::DEFAULTED}: those are
 * non-secret identities or tunables an operator is expected to keep. Adding one is a deliberate edit here.
 * Values are compared the way Compose reads them, with one pair of surrounding quotes removed, and a key the
 * template declares twice is refused because `prod.env.check` reads the first occurrence and Compose the last.
 *
 * A green proves the template is complete and that its secrets are placeholders. It says nothing about an
 * operator's `.env.prod.local`, which is `prod.env.check`'s subject; nothing about a variable compose reads
 * with a fallback (`${VAR:-…}`), which the stack does not require; and nothing about whether make's own
 * derivation in `make/deploy.mk` agrees with this one — it is a second reading of the same files, kept in
 * step by review, because the php container this suite runs in has no host `make`.
 *
 * @internal
 */
#[CoversNothing]
final class ProdEnvTemplateGateTest extends TestCase
{
    /**
     * The files the prod stack loads, in the order `make/config.mk` passes them.
     *
     * @var list<string>
     */
    private const array PROD_COMPOSE_FILES = ['compose.yaml', 'compose.prod.yaml'];

    private const string TEMPLATE = '.env.prod.example';

    private const string PLACEHOLDER_PREFIX = 'CHANGE_ME';

    /**
     * Required keys whose template value is a working default rather than a placeholder.
     *
     * @var list<string>
     */
    private const array DEFAULTED = [
        'NEXT_PUBLIC_API_BASE_URL',
        'POSTGRES_DB',
        'POSTGRES_USER',
        'SENTRY_TRACES_SAMPLE_RATE',
        'SERVER_NAME',
    ];

    /**
     * A floor rather than the exact count, so the derivation below cannot pass by finding nothing.
     */
    private const int MINIMUM_REQUIRED_KEYS = 10;

    #[Test]
    public function theTemplateDeclaresEveryKeyTheProdStackRequires(): void
    {
        $template = $this->templateValues();
        $missing = \array_values(\array_diff($this->requiredKeys(), \array_keys($template)));

        $this->assertSame(
            [],
            $missing,
            \sprintf(
                'The prod stack requires these keys but %s does not declare them, so a copied template fails '
                . 'at deploy: %s',
                self::TEMPLATE,
                \implode(', ', $missing),
            ),
        );
    }

    #[Test]
    public function everyRequiredKeyWithoutADefaultShipsAsAPlaceholder(): void
    {
        $template = $this->templateValues();
        $unsafe = [];

        foreach (\array_diff($this->requiredKeys(), self::DEFAULTED) as $key) {
            if (!\str_starts_with($template[$key] ?? '', self::PLACEHOLDER_PREFIX)) {
                $unsafe[] = $key;
            }
        }

        $this->assertSame(
            [],
            $unsafe,
            \sprintf(
                'These required keys ship in %s with a value that `make prod.env.check` accepts, so a copied '
                . 'template runs prod on it. Use a %s… placeholder, or list the key in DEFAULTED if the value '
                . 'is a default an operator should keep: %s',
                self::TEMPLATE,
                self::PLACEHOLDER_PREFIX,
                \implode(', ', $unsafe),
            ),
        );
    }

    #[Test]
    public function everyDefaultedKeyIsStillRequiredAndShipsAWorkingValue(): void
    {
        $template = $this->templateValues();
        $stale = \array_values(\array_diff(self::DEFAULTED, $this->requiredKeys()));
        $unusable = \array_values(\array_filter(
            self::DEFAULTED,
            static fn (string $key): bool => '' === ($template[$key] ?? '')
                || \str_starts_with($template[$key], self::PLACEHOLDER_PREFIX),
        ));

        $this->assertSame(
            [],
            $stale,
            'DEFAULTED names keys the prod stack no longer requires: ' . \implode(', ', $stale),
        );
        $this->assertSame(
            [],
            $unusable,
            \sprintf(
                'DEFAULTED keys must ship a working value in %s, not an empty one or a placeholder: %s',
                self::TEMPLATE,
                \implode(', ', $unusable),
            ),
        );
    }

    #[Test]
    public function theTemplateDeclaresEachKeyOnce(): void
    {
        $counts = \array_count_values($this->templateKeyOccurrences());
        $duplicated = \array_keys(\array_filter($counts, static fn (int $count): bool => $count > 1));

        $this->assertSame(
            [],
            $duplicated,
            \sprintf(
                '%s declares these keys more than once; `prod.env.check` reads the first and Compose the last: %s',
                self::TEMPLATE,
                \implode(', ', $duplicated),
            ),
        );
    }

    /**
     * Read the way `make/deploy.mk` reads it: whole comment lines are skipped, both required forms count.
     *
     * @return list<string>
     */
    private function requiredKeys(): array
    {
        $keys = [];

        foreach (self::PROD_COMPOSE_FILES as $file) {
            foreach (\explode("\n", $this->read($file)) as $line) {
                if (1 === \preg_match('/^\s*#/', $line)) {
                    continue;
                }

                \preg_match_all('/\$\{([A-Z0-9_]+):?\?/', $line, $matches);

                foreach ($matches[1] as $key) {
                    $keys[$key] = true;
                }
            }
        }

        $keys = \array_keys($keys);
        \sort($keys);
        $this->assertGreaterThanOrEqual(
            self::MINIMUM_REQUIRED_KEYS,
            \count($keys),
            \sprintf(
                'Only %d required keys were read from %s. Either the derivation broke, or the stack legitimately '
                . 'requires fewer keys and MINIMUM_REQUIRED_KEYS should come down with it.',
                \count($keys),
                \implode(' + ', self::PROD_COMPOSE_FILES),
            ),
        );

        return $keys;
    }

    /**
     * @return array<string, string>
     */
    private function templateValues(): array
    {
        $values = [];

        foreach (\explode("\n", $this->read(self::TEMPLATE)) as $line) {
            if (1 === \preg_match('/^([A-Z0-9_]+)=(.*)$/', $line, $match) && !isset($values[$match[1]])) {
                $values[$match[1]] = $this->unquote(\trim($match[2]));
            }
        }

        return $values;
    }

    /**
     * @return list<string>
     */
    private function templateKeyOccurrences(): array
    {
        \preg_match_all('/^([A-Z0-9_]+)=/m', $this->read(self::TEMPLATE), $matches);

        return $matches[1];
    }

    private function unquote(string $value): string
    {
        if (1 === \preg_match('/^(["\'])(.*)\1$/', $value, $match)) {
            return $match[2];
        }

        return $value;
    }

    private function read(string $relativePath): string
    {
        $root = RepositoryRoot::path();
        $this->assertNotNull(
            $root,
            'Cannot find the repository root: the gate reads it through the read-only `./` mount at /app/repo '
            . 'in compose.dev.yaml.',
        );

        $contents = \file_get_contents($root . '/' . $relativePath);
        $this->assertIsString($contents, \sprintf('Cannot read "%s" at the repository root.', $relativePath));

        return $contents;
    }
}
