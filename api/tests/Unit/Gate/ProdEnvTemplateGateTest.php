<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Gate;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Every key the prod stack requires is declared by `.env.prod.example`, and a copied template fails the check.
 *
 * `compose.prod.yaml` aborts on any `${VAR:?…}` left unset, and `make prod.env.check` derives its required set
 * from those same interpolations. The template is the third copy and the only hand-written one: it is what an
 * operator copies to `.env.prod.local`, so a key it omits is discovered by the deploy failing, and a key it
 * ships with a plausible-looking value passes the check and runs the stack on that value. The template once
 * omitted `AUDIT_KEK` and shipped a fake but well-formed `NEXT_PUBLIC_SENTRY_DSN`; this gate refuses both.
 *
 * A required key may carry a usable default only when it is listed in {@see self::DEFAULTED}: those are
 * non-secret identities or tunables an operator is expected to keep. Adding one is a deliberate edit here.
 *
 * A green proves the template is complete and that its secrets are placeholders. It says nothing about an
 * operator's `.env.prod.local`, which is `prod.env.check`'s subject, nor about a variable compose reads with
 * a fallback (`${VAR:-…}`), which the stack does not require.
 *
 * @internal
 */
#[CoversNothing]
final class ProdEnvTemplateGateTest extends TestCase
{
    private const string PROD_COMPOSE = 'compose.prod.yaml';

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
     * Pinned so the derivation below cannot pass by finding nothing.
     */
    private const int MINIMUM_REQUIRED_KEYS = 13;

    #[Test]
    public function theTemplateDeclaresEveryKeyTheProdStackRequires(): void
    {
        $template = $this->templateValues();
        $missing = \array_values(\array_diff($this->requiredKeys(), \array_keys($template)));

        $this->assertSame(
            [],
            $missing,
            \sprintf(
                '%s requires these keys but %s does not declare them, so a copied template fails at deploy: %s',
                self::PROD_COMPOSE,
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
    public function everyDefaultedKeyIsStillRequired(): void
    {
        $stale = \array_values(\array_diff(self::DEFAULTED, $this->requiredKeys()));

        $this->assertSame(
            [],
            $stale,
            'DEFAULTED names keys compose.prod.yaml no longer requires: ' . \implode(', ', $stale),
        );
    }

    /**
     * Read the way `make/deploy.mk` reads it: comment lines and trailing comments are not interpolations.
     *
     * @return list<string>
     */
    private function requiredKeys(): array
    {
        $keys = [];

        foreach (\explode("\n", $this->read(self::PROD_COMPOSE)) as $line) {
            if (1 === \preg_match('/^\s*#/', $line)) {
                continue;
            }

            $code = (string) \preg_replace('/\s#.*$/', '', $line);
            \preg_match_all('/\$\{([A-Z0-9_]+):\?/', $code, $matches);

            foreach ($matches[1] as $key) {
                $keys[$key] = true;
            }
        }

        $keys = \array_keys($keys);
        \sort($keys);
        $this->assertGreaterThanOrEqual(
            self::MINIMUM_REQUIRED_KEYS,
            \count($keys),
            \sprintf(
                'Fewer required keys than expected were read from %s; the derivation is broken.',
                self::PROD_COMPOSE,
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
            if (1 === \preg_match('/^([A-Z0-9_]+)=(.*)$/', $line, $match)) {
                $values[$match[1]] = \trim($match[2]);
            }
        }

        return $values;
    }

    private function read(string $relativePath): string
    {
        $apiRoot = \dirname(__DIR__, 3);

        foreach ([\dirname($apiRoot), \dirname($apiRoot) . '/repo'] as $candidate) {
            if (\is_file($candidate . '/' . $relativePath)) {
                $contents = \file_get_contents($candidate . '/' . $relativePath);
                $this->assertIsString($contents);

                return $contents;
            }
        }

        $this->fail(\sprintf(
            'Cannot find "%s" from %s: the gate reads the repository root, which the php container sees only '
            . 'through the read-only `./` mount at /app/repo in compose.dev.yaml.',
            $relativePath,
            $apiRoot,
        ));
    }
}
