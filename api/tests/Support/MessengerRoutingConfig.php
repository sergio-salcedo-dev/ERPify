<?php

declare(strict_types=1);

namespace Erpify\Tests\Support;

use Symfony\Component\Yaml\Yaml;

/**
 * Reads Messenger's routing and transport declarations the way Symfony assembles them, so a gate can ask what
 * is configured without also owning what the configuration MEANS. That second half is
 * {@see PersistentTransportPolicy}'s.
 *
 * The reading is the half that is easy to get quietly wrong: the container merges every
 * `config/packages/*.yaml`, `config/packages/<env>/*.yaml` and `config/services*.yaml` into one extension
 * config, a routing value has three accepted shapes, and PHP config is executed rather than parsed.
 *
 * @internal test support
 */
final readonly class MessengerRoutingConfig
{
    /** The only transport that never persists a message body, in every environment. */
    /** The transport whose name is trusted to mean "durable, and on the caller's own connection". */
    public const string PERSISTED_TRANSPORT = 'async';

    /** What `async` must be spelled as outside the test environment. */
    public const string TRANSPORT_ENV_PLACEHOLDER = '%env(MESSENGER_TRANSPORT_DSN)%';

    /** The substitution the test environment makes, pinned so a change to it has to be argued. */
    public const string NON_TRANSACTIONAL_TEST_DSN_PREFIX = 'in-memory://';

    public const string NON_PERSISTED_TRANSPORT = 'sync';

    public function __construct(private string $apiRoot)
    {
    }

    /**
     * Every `framework.messenger.routing` map Symfony would load. Not just `packages/messenger.yaml`: the
     * container merges `config/packages/*.yaml`, `config/packages/<env>/*.yaml` and `config/services*.yaml`
     * into one extension config, so an env-specific override file routes the event in exactly the
     * environment that matters. `when@*` blocks are read too — their transports are in-memory today, but a
     * gate that modelled that could only ever be weaker.
     *
     * @return array<string, list<string>>
     */
    public function configuredRoutes(): array
    {
        $routes = [];

        foreach ($this->configFiles() as $file) {
            $config = Yaml::parseFile($file, Yaml::PARSE_CUSTOM_TAGS);

            foreach (\is_array($config) ? $config : [] as $section) {
                foreach ($this->routingMapIn($section) as $key => $transports) {
                    // UNION, never assignment. Sections and files are read in document order, so the last
                    // `when@<env>` block declaring a key would otherwise shadow the production route — and
                    // since the test env maps everything to in-memory, that shadowing makes the gate WEAKER
                    // exactly where it must not be. Every declared transport counts, in every environment.
                    $routes[(string) $key] = \array_values(\array_unique([
                        ...$routes[(string) $key] ?? [],
                        ...$this->transportList($transports),
                    ]));
                }
            }
        }

        return $routes;
    }

    /**
     * Every YAML file whose contents reach `framework.messenger`.
     *
     * @return list<string>
     */
    public function configFiles(): array
    {
        $patterns = [
            $this->apiRoot . '/config/packages/*.yaml',
            $this->apiRoot . '/config/packages/*/*.yaml',
            $this->apiRoot . '/config/services*.yaml',
        ];

        $files = [];

        foreach ($patterns as $pattern) {
            foreach (\glob($pattern) ?: [] as $file) {
                $files[] = $file;
            }
        }

        return $files;
    }

    /**
     * Config written in PHP is outside this gate's reach — it is executed, not parsed. None exists that
     * touches Messenger today, so the honest control is a tripwire rather than a partial parser.
     *
     * @return list<string>
     */
    public function phpConfigFilesTouchingMessenger(): array
    {
        $hits = [];

        $pattern = $this->apiRoot . '/config/{packages/*.php,packages/*/*.php,services*.php}';

        foreach (\glob($pattern, GLOB_BRACE) ?: [] as $file) {
            if (\str_contains((string) \file_get_contents($file), 'messenger')) {
                $hits[] = \substr($file, \strlen($this->apiRoot) + 1);
            }
        }

        return $hits;
    }

    /**
     * `sync` is trusted by NAME everywhere else here, so the name has to be shown to still mean `sync://`.
     * Redefining it to a Doctrine DSN would otherwise turn the one sanctioned escape hatch into the leak.
     *
     * @return list<string>
     */
    public function misdeclaredNonPersistedTransports(): array
    {
        $violations = [];

        foreach ($this->configFiles() as $file) {
            $config = Yaml::parseFile($file, Yaml::PARSE_CUSTOM_TAGS);

            foreach (\is_array($config) ? $config : [] as $section) {
                $dsn = $this->transportsIn($section)[self::NON_PERSISTED_TRANSPORT] ?? null;
                $dsn = \is_array($dsn) ? ($dsn['dsn'] ?? null) : $dsn;

                if (null !== $dsn && (!\is_string($dsn) || !\str_starts_with($dsn, 'sync://'))) {
                    $violations[] = \sprintf(
                        '%s declares transport "%s" as %s',
                        $file,
                        self::NON_PERSISTED_TRANSPORT,
                        \json_encode($dsn),
                    );
                }
            }
        }

        return $violations;
    }

    /**
     * The dual of {@see misdeclaredNonPersistedTransports()}, and the half that was missing.
     *
     * `async` is trusted by NAME as the durable, transactional transport: the after-commit guarantee of
     * every event routed to it comes from that name resolving to a Doctrine DSN on the SAME connection the
     * use case is already writing through. Redefining it to anything else removes the guarantee while every
     * routing check stays green, which is the same shape the `sync` half exists to refuse.
     *
     * The test environment's own override is asserted rather than tolerated. It replaces `async` with an
     * in-memory transport, which does NOT join the transaction — measured, a rollback leaves the message
     * held — so no test in this suite can demonstrate the guarantee end to end. Pinning that substitution
     * means a later change to it turns this red and forces the claim to be revisited, instead of leaving a
     * comment that has quietly stopped being true.
     *
     * What it cannot do, and this belongs in the docblock rather than only in an ADR: three of the five
     * places that carry this DSN are `${MESSENGER_TRANSPORT_DSN:-…}` interpolations in the compose files,
     * so the deployment's environment wins. A green here proves nobody committed an accident to the
     * repository; it proves nothing about the value a deployment actually resolves.
     *
     * @return list<string>
     */
    public function misdeclaredPersistedTransport(): array
    {
        $violations = [];

        foreach ($this->configFiles() as $file) {
            $config = Yaml::parseFile($file, Yaml::PARSE_CUSTOM_TAGS);

            foreach (\is_array($config) ? $config : [] as $sectionName => $section) {
                $dsn = $this->transportsIn($section)[self::PERSISTED_TRANSPORT] ?? null;
                $dsn = \is_array($dsn) ? ($dsn['dsn'] ?? null) : $dsn;

                if (null === $dsn) {
                    continue;
                }

                $expected = \str_starts_with((string) $sectionName, 'when@test')
                    ? self::NON_TRANSACTIONAL_TEST_DSN_PREFIX
                    : null;

                if (null !== $expected) {
                    if (!\is_string($dsn) || !\str_starts_with($dsn, $expected)) {
                        $violations[] = \sprintf(
                            '%s [%s] declares "%s" as %s; the pinned test substitution is %s',
                            $file,
                            $sectionName,
                            self::PERSISTED_TRANSPORT,
                            \json_encode($dsn),
                            $expected,
                        );
                    }

                    continue;
                }

                if (!\is_string($dsn) || !$this->resolvesToADurableTransport($dsn)) {
                    $violations[] = \sprintf(
                        '%s [%s] declares "%s" as %s; it must resolve to a Doctrine DSN',
                        $file,
                        $sectionName,
                        self::PERSISTED_TRANSPORT,
                        \json_encode($dsn),
                    );
                }
            }
        }

        return $violations;
    }

    /**
     * Either the env placeholder the deployment fills, or a literal Doctrine DSN. Anything else — a broker,
     * an in-memory transport, a `sync://` — breaks the coupling to the caller's transaction.
     */
    private function resolvesToADurableTransport(string $dsn): bool
    {
        return self::TRANSPORT_ENV_PLACEHOLDER === $dsn || \str_starts_with($dsn, 'doctrine://');
    }

    /**
     * @return array<array-key, mixed>
     */
    private function transportsIn(mixed $section): array
    {
        $transports = $this->messengerIn($section)['transports'] ?? null;

        return \is_array($transports) ? $transports : [];
    }

    /**
     * The `messenger` config inside one top-level section, whether that section is `framework` itself or a
     * `when@<env>` block wrapping one.
     *
     * @return array<array-key, mixed>
     */
    private function messengerIn(mixed $section): array
    {
        if (!\is_array($section)) {
            return [];
        }

        $messenger = $section['messenger'] ?? null;

        if (!\is_array($messenger)) {
            $framework = $section['framework'] ?? null;
            $messenger = \is_array($framework) ? ($framework['messenger'] ?? null) : null;
        }

        return \is_array($messenger) ? $messenger : [];
    }

    /**
     * @return array<array-key, mixed>
     */
    private function routingMapIn(mixed $section): array
    {
        $routing = $this->messengerIn($section)['routing'] ?? null;

        return \is_array($routing) ? $routing : [];
    }

    /**
     * A routing value is one transport name, a list of them, or the nested `{senders: [...]}` form. That last
     * shape is deprecated in Symfony 8.1 and removed in 9.0, but `Configuration.php` still normalises it
     * today — so it routes, and a reader who drops it would leave the pre-8.1 form as a silent bypass.
     *
     * @return list<string>
     */
    public function transportList(mixed $transports): array
    {
        if (\is_array($transports) && \array_key_exists('senders', $transports)) {
            $transports = $transports['senders'];
        }

        $list = [];

        foreach (\is_array($transports) ? $transports : [$transports] as $transport) {
            if (\is_string($transport)) {
                $list[] = $transport;
            }
        }

        return $list;
    }
}
