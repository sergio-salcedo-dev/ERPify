<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Gate;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * The declaration half of the fingers-crossed gate: what the configuration says, in every environment.
 *
 * Only the `test` declaration is reachable from a booted kernel, so every behavioural assertion about the
 * exclusion holds while the `prod` block is deleted or narrowed and production flushes its buffer on every
 * 404. Reading the files closes that, and it is the one property of the deployed configuration a test in this
 * environment can observe at all — which is why this half boots no kernel and lives beside the other source
 * reading gates rather than with the runtime one.
 *
 * @internal
 */
#[CoversNothing]
final class MonologExclusionDeclarationGateTest extends TestCase
{
    /**
     * Compared as PARSED values across every file Symfony merges: a textual match reds on a block-sequence
     * rewrite that changes nothing, and passes over an empty list — which `MonologExtension` treats as no
     * exclusion at all, dropping back to `ErrorLevelActivationStrategy` so every 404 activates.
     */
    #[Test]
    public function everyEnvironmentWithAFingersCrossedHandlerExcludesTheSameCodes(): void
    {
        $declarations = $this->exclusionsDeclaredPerEnvironment();

        $this->assertGreaterThan(
            1,
            \count($declarations),
            'fewer environments declare a fingers_crossed handler than the deployment has, so one lost its gate',
        );

        foreach ($declarations as $environment => $codes) {
            $this->assertNotEmpty(
                $codes,
                \sprintf('"%s" declares a fingers_crossed handler with no exclusion', $environment),
            );
        }

        $distinct = [];

        foreach ($declarations as $declaration) {
            if (!\in_array($declaration, $distinct, true)) {
                $distinct[] = $declaration;
            }
        }

        $this->assertCount(
            1,
            $distinct,
            'the environments disagree on which codes stay out of the unrotated sink',
        );
    }

    /**
     * A processor tagged `handler: main` lands on the gate handler itself, which `FingersCrossedHandler::handle()`
     * runs BEFORE consulting the strategy — no sweep over channel loggers can see it. Refusing the tag is what
     * turns that blind spot from a sentence in a docblock into something a change has to argue with.
     */
    #[Test]
    public function noProcessorIsScopedToAHandler(): void
    {
        $tagged = 0;

        foreach ($this->serviceDefinitions() as $id => $definition) {
            foreach ($this->monologProcessorTags($definition) as $tag) {
                ++$tagged;
                $this->assertArrayNotHasKey(
                    'handler',
                    $tag,
                    \sprintf('"%s" is enrolled on a handler, where no sweep over channel loggers can reach it', $id),
                );
            }
        }

        $this->assertGreaterThan(0, $tagged, 'no processor was found, so this asserts nothing');
    }

    /**
     * @return array<string, list<int>>
     */
    private function exclusionsDeclaredPerEnvironment(): array
    {
        $configDir = \dirname(__DIR__, 3) . '/config/packages';
        $base = Yaml::parseFile($configDir . '/monolog.yaml');
        $this->assertIsArray($base);

        $declarations = [];

        foreach ($base as $key => $section) {
            if (1 === \preg_match('/^when@(\w+)$/', (string) $key, $matches)) {
                $declarations = \array_merge($declarations, $this->exclusionsOf($matches[1], $section));
            }
        }

        // The override is parsed after the base file and wins, because that is the order Symfony merges them
        // in: a per-environment file is what decides the deployment when both declare the handler.
        foreach (\glob($configDir . '/*/monolog.yaml') ?: [] as $override) {
            $declarations = \array_merge(
                $declarations,
                $this->exclusionsOf(\basename(\dirname($override)), Yaml::parseFile($override)),
            );
        }

        return $declarations;
    }

    /**
     * @return array<string, list<int>>
     */
    private function exclusionsOf(string $environment, mixed $section): array
    {
        if (!\is_array($section)) {
            return [];
        }

        $monolog = $section['monolog'] ?? null;
        $handlers = \is_array($monolog) ? ($monolog['handlers'] ?? null) : null;

        if (!\is_array($handlers)) {
            return [];
        }

        foreach ($handlers as $handler) {
            if (!\is_array($handler) || 'fingers_crossed' !== ($handler['type'] ?? null)) {
                continue;
            }

            return [$environment => $this->codesOf($environment, $handler['excluded_http_codes'] ?? [])];
        }

        return [];
    }

    /**
     * @return list<int>
     */
    private function codesOf(string $environment, mixed $declared): array
    {
        $this->assertIsArray($declared);

        $codes = [];

        foreach ($declared as $code) {
            // An integer and not a placeholder: two environments interpolating the same `%parameter%` are
            // textually equal while their runtime values differ.
            $this->assertIsInt(
                $code,
                \sprintf('"%s" declares a non-integer code, so equal spellings need not agree', $environment),
            );
            $codes[] = $code;
        }

        return $codes;
    }

    /**
     * @return array<string, array<mixed, mixed>>
     */
    private function serviceDefinitions(): array
    {
        // `PARSE_CUSTOM_TAGS`, because the file carries `!tagged_iterator` and the parser refuses it otherwise.
        $parsed = Yaml::parseFile(\dirname(__DIR__, 3) . '/config/services.yaml', Yaml::PARSE_CUSTOM_TAGS);
        $this->assertIsArray($parsed);
        $services = $parsed['services'] ?? [];
        $this->assertIsArray($services);

        $definitions = [];

        foreach ($services as $id => $definition) {
            if (\is_string($id) && !\str_starts_with($id, '_') && \is_array($definition)) {
                $definitions[$id] = $definition;
            }
        }

        return $definitions;
    }

    /**
     * @param array<mixed, mixed> $definition
     *
     * @return list<array<mixed, mixed>>
     */
    private function monologProcessorTags(array $definition): array
    {
        $tags = [];
        $declared = $definition['tags'] ?? [];

        if (!\is_array($declared)) {
            return [];
        }

        foreach ($declared as $tag) {
            $name = \is_array($tag) ? ($tag['name'] ?? null) : $tag;

            if ('monolog.processor' === $name) {
                $tags[] = \is_array($tag) ? $tag : ['name' => $tag];
            }
        }

        return $tags;
    }
}
