<?php

declare(strict_types=1);

namespace Erpify\Tests\Support;

use LogicException;
use Monolog\Level;
use Symfony\Component\Yaml\Yaml;

/**
 * Reads the level at which every declared `fingers_crossed` handler stops buffering, in every environment.
 *
 * A booted kernel answers for one environment only, and it is never the deployed one: a `prod` block lowered
 * on its own compiles nowhere this suite can look. So the deployment's own threshold is readable from the
 * FILES or from nothing, and this is the walk that reads them.
 *
 * **Three ways the walk fails open, all closed here rather than described.** A per-environment override does
 * not repeat `type:` — `config/packages/prod/monolog.yaml` carrying nothing but `action_level: warning`
 * merges onto the base handler and lowers the deployment, so a selector keyed on `type` skips exactly the
 * file that does the damage. Symfony imports `config/packages/<env>/*.{php,yaml}` under ANY basename, so a
 * glob pinned to `monolog.yaml` reads none of `logging.yaml`, `observability.yaml` or a PHP config; every
 * file that mentions Monolog at all is therefore parsed, and one written in PHP is refused rather than
 * skipped, because this walk cannot read it and a silent skip is indistinguishable from a clean file. And a
 * handler may declare more than one `fingers_crossed` per environment — the commented Sentry block in
 * `config/packages/monolog.yaml` is one — so every one of them is answered for, keyed by name, instead of
 * the first the parser happens to reach.
 *
 * `passthru_level` and `activation_strategy` are refused on any handler read here. Neither is a level this
 * class could answer with: the first ships the buffer to the sink with no activation at all, and the second
 * replaces the strategy `action_level` configures, leaving the number in the file governing nothing. Both
 * would let a caller read a threshold that is not the one the deployment runs, which is the single failure
 * this class exists to prevent.
 *
 * @internal test support
 */
final readonly class DeclaredFingersCrossedHandlers
{
    private const string HANDLER_TYPE = 'fingers_crossed';

    /** MonologBundle's own default, and one a client error REACHES — an omitted key is the violation. */
    private const string DEFAULT_ACTION_LEVEL = 'WARNING';

    /** The environment a handler declared outside any `when@` block belongs to: all of them. */
    private const string ENVIRONMENT_AGNOSTIC = 'all';

    public function __construct(private string $configDir)
    {
    }

    /**
     * @return array<string, Level> keyed `"<environment>/<handler>"`
     */
    public function actionLevels(): array
    {
        $declarations = [];

        // Base files first, per-environment overrides second, because that is the order Symfony merges them
        // in: when both declare the handler, the override is the one the deployment runs.
        foreach ($this->mentioningMonolog($this->configDir . '/*') as $file) {
            $declarations = [...$declarations, ...$this->actionLevelsIn(self::ENVIRONMENT_AGNOSTIC, $file)];
        }

        foreach ($this->mentioningMonolog($this->configDir . '/*/*') as $file) {
            $declarations = [...$declarations, ...$this->actionLevelsIn(\basename(\dirname($file)), $file)];
        }

        return $declarations;
    }

    /**
     * @return list<string>
     */
    private function mentioningMonolog(string $pattern): array
    {
        $paths = \glob($pattern . '.{php,yaml,yml}', GLOB_BRACE);

        if (false === $paths) {
            throw new LogicException(\sprintf('the configuration under "%s" could not be listed', $pattern));
        }

        $files = [];

        foreach ($paths as $path) {
            $contents = \file_get_contents($path);

            if (false === $contents || !\str_contains($contents, 'monolog')) {
                continue;
            }

            if ('php' === \pathinfo($path, PATHINFO_EXTENSION)) {
                throw new LogicException(\sprintf(
                    '"%s" configures Monolog in PHP, which this walk cannot read',
                    $path,
                ));
            }

            $files[] = $path;
        }

        return $files;
    }

    /**
     * A file under `config/packages/<env>/` belongs to that environment; one at the top level belongs to
     * whichever `when@` block holds it, or to every environment when it holds none.
     *
     * @return array<string, Level>
     */
    private function actionLevelsIn(string $environment, string $file): array
    {
        $parsed = Yaml::parseFile($file, Yaml::PARSE_CONSTANT | Yaml::PARSE_CUSTOM_TAGS);

        if (!\is_array($parsed)) {
            return [];
        }

        $declarations = [];

        foreach ($parsed as $key => $section) {
            $named = 1 === \preg_match('/^when@(\S+)$/', (string) $key, $matches) ? $matches[1] : null;

            if (null === $named && 'monolog' !== $key) {
                continue;
            }

            $declarations = [...$declarations, ...$this->actionLevelsOf(
                $named ?? $environment,
                null === $named ? [$key => $section] : $section,
            )];
        }

        return $declarations;
    }

    /**
     * @return array<string, Level>
     */
    private function actionLevelsOf(string $environment, mixed $section): array
    {
        $handlers = \is_array($section) && \is_array($section['monolog'] ?? null)
            ? ($section['monolog']['handlers'] ?? null)
            : null;

        if (!\is_array($handlers)) {
            return [];
        }

        $declarations = [];

        foreach ($handlers as $name => $handler) {
            if (!\is_array($handler) || !$this->buffers($handler)) {
                continue;
            }

            $described = \sprintf('%s/%s', $environment, (string) $name);
            $this->refuseUnreadableActivation($described, $handler);

            $declarations[$described] = $this->levelOf(
                $handler['action_level'] ?? self::DEFAULT_ACTION_LEVEL,
                \sprintf('the action level "%s" declares', $described),
            );
        }

        return $declarations;
    }

    /**
     * A declaration with no `type` is an override merging onto a handler that has one, and it is the shape a
     * per-environment file lowering `action_level` takes. Reading it needs no guess about what it merges
     * onto: a key this walk answers for is one it either types as buffered or leaves untyped while touching
     * the buffering options.
     *
     * @param array<mixed, mixed> $handler
     */
    private function buffers(array $handler): bool
    {
        $type = $handler['type'] ?? null;

        if (null !== $type) {
            return self::HANDLER_TYPE === $type;
        }

        return \array_key_exists('action_level', $handler)
            || \array_key_exists('passthru_level', $handler)
            || \array_key_exists('activation_strategy', $handler);
    }

    /**
     * @param array<mixed, mixed> $handler
     */
    private function refuseUnreadableActivation(string $described, array $handler): void
    {
        if (\array_key_exists('passthru_level', $handler)) {
            throw new LogicException(\sprintf(
                '"%s" declares passthru_level, so buffered records reach the sink with no activation at all',
                $described,
            ));
        }

        if (\array_key_exists('activation_strategy', $handler)) {
            throw new LogicException(\sprintf(
                '"%s" activates on a service, so the action level it declares governs nothing',
                $described,
            ));
        }
    }

    /**
     * Coerced the way Monolog coerces: by VALUE through the backed enum, and by NAME case-insensitively over
     * `Level::cases()` — which is what `Logger::toMonologLevel()` amounts to, since it upper-cases the first
     * byte and lower-cases the rest before resolving the case. Monolog's own entry points are typed on a
     * literal union a value read out of a YAML file cannot satisfy, so the enum answers directly. Anything
     * else — an interpolated `%env(...)%` above all — is refused rather than guessed at.
     */
    private function levelOf(mixed $declared, string $describedAs): Level
    {
        if ($declared instanceof Level) {
            return $declared;
        }

        if (\is_int($declared) || (\is_string($declared) && \is_numeric($declared))) {
            return Level::tryFrom((int) $declared)
                ?? throw new LogicException(\sprintf('%s is no level Monolog defines', $describedAs));
        }

        if (\is_string($declared)) {
            foreach (Level::cases() as $case) {
                if (0 === \strcasecmp($case->name, $declared)) {
                    return $case;
                }
            }
        }

        throw new LogicException(\sprintf(
            '%s is no level Monolog recognises: %s',
            $describedAs,
            \get_debug_type($declared),
        ));
    }
}
