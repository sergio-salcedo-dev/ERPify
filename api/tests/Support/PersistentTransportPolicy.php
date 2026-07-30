<?php

declare(strict_types=1);

namespace Erpify\Tests\Support;

use Erpify\Shared\Event\Domain\DomainEvent;
use ReflectionClass;
use RuntimeException;
use Symfony\Component\Messenger\Attribute\AsMessage;
use Symfony\Component\Yaml\Yaml;

/**
 * Resolution engine behind the persistent-transport gate: it reads the declared classification of every
 * domain-event aggregate type, reads Messenger's configured routing, and reports which person aggregates
 * reach a persisted transport.
 *
 * Split from the gate test so the rules are exercisable independently of the assertions, the way
 * {@see AllowlistFile} and {@see ApiSourceFiles} already are — and so a fixture can drive the resolution
 * against every routing shape Messenger accepts without a dirty entry ever existing in the real config.
 *
 * @internal test support
 */
final readonly class PersistentTransportPolicy
{
    /** The only transport that never persists a message body, in every environment. */
    public const string NON_PERSISTED_TRANSPORT = 'sync';

    /**
     * A classification is three-state and the distinction is load-bearing: `null` is non-person (never a
     * violation), this constant is a person with no sanctioned exception (a violation once routed), and any
     * other string is the ADR path of a declared exception.
     */
    public const string PERSON_NO_EXCEPTION = '';

    private const string NON_PERSON = 'non-person';

    public function __construct(private string $apiRoot)
    {
    }

    public static function fromGateLocation(string $gateDirectory): self
    {
        return new self(\dirname($gateDirectory, 4));
    }

    public function apiRoot(): string
    {
        return $this->apiRoot;
    }

    /**
     * @return array<string, string|null> aggregateType => ADR path, {@see PERSON_NO_EXCEPTION}, or null
     */
    public function classification(): array
    {
        $registry = [];

        foreach (AllowlistFile::entries($this->apiRoot . '/.persistent-transport-policy') as $line) {
            $parts = \array_map(trim(...), \explode('=>', $line, 2));

            if (2 !== \count($parts)) {
                throw new RuntimeException(
                    'Malformed registry line (expected `AggregateType => classification`): ' . $line,
                );
            }

            [$aggregateType, $value] = $parts;

            // Last-wins would let a duplicate line silently downgrade a person type, and the policy check
            // skips non-person types — so the shadowed line would take the protection with it.
            if (\array_key_exists($aggregateType, $registry)) {
                throw new RuntimeException(\sprintf(
                    'Duplicate registry line for "%s": the later classification silently shadows the earlier.',
                    $aggregateType,
                ));
            }

            $registry[$aggregateType] = $this->classificationOf($aggregateType, $value);
        }

        return $registry;
    }

    /**
     * Every concrete {@see DomainEvent} in `src`, mapped to the aggregate type it declares. Reflection rather
     * than a regex: `aggregateType()` is inherited as often as it is written, and only the class itself can
     * say what it resolves to.
     *
     * @return array<class-string<DomainEvent>, string>
     */
    public function eventsInSource(): array
    {
        $events = [];

        foreach (ApiSourceFiles::phpFiles($this->apiRoot . '/src') as $file) {
            $relative = \substr($file->getPathname(), \strlen($this->apiRoot . '/src') + 1);
            $fqcn = 'Erpify\\' . \str_replace('/', '\\', \substr($relative, 0, -4));

            if (!\class_exists($fqcn)) {
                continue;
            }

            $reflection = new ReflectionClass($fqcn);

            if ($reflection->isAbstract()) {
                continue;
            }

            if (!$reflection->isSubclassOf(DomainEvent::class)) {
                continue;
            }

            /** @var class-string<DomainEvent> $fqcn */
            $events[$fqcn] = $fqcn::aggregateType();
        }

        return $events;
    }

    /**
     * Every `framework.messenger.routing` map in the config, plus each `#[AsMessage(transport:)]` folded in
     * as an entry keyed by its own class — which is how `SendersLocator` treats it when no map entry matched.
     * `when@*` blocks are included: their transports are in-memory today, but a gate that modelled that could
     * only ever be weaker.
     *
     * @return array<string, list<string>>
     */
    public function configuredRoutes(): array
    {
        $config = Yaml::parseFile($this->apiRoot . '/config/packages/messenger.yaml');
        $routes = [];

        foreach (\is_array($config) ? $config : [] as $section) {
            foreach ($this->routingMapIn($section) as $key => $transports) {
                $routes[(string) $key] = $this->transportList($transports);
            }
        }

        return [...$routes, ...$this->attributeRoutes($this->eventsInSource())];
    }

    /**
     * @param array<class-string<DomainEvent>, string> $events
     *
     * @return array<string, list<string>>
     */
    public function attributeRoutes(array $events): array
    {
        $routes = [];

        foreach (\array_keys($events) as $fqcn) {
            foreach ((new ReflectionClass($fqcn))->getAttributes(AsMessage::class) as $attribute) {
                $transports = $this->transportList($attribute->newInstance()->transport);

                if ([] !== $transports) {
                    $routes[$fqcn] = $transports;
                }
            }
        }

        return $routes;
    }

    /**
     * @param array<string, list<string>> $routes routing key => transport names
     * @param array<string, string>       $events event FQCN => aggregateType
     *
     * @return list<string>
     */
    public function violations(array $routes, array $events): array
    {
        $classification = $this->classification();
        $violations = [];

        foreach ($routes as $key => $transports) {
            $persisted = \array_values(\array_filter(
                $transports,
                static fn (string $transport): bool => self::NON_PERSISTED_TRANSPORT !== $transport,
            ));

            if ([] === $persisted) {
                continue;
            }

            foreach ($this->eventsReachableFrom((string) $key, $events) as $fqcn => $aggregateType) {
                // Non-person and ADR-excepted are allowed; unclassified is the completeness check's job.
                if (($classification[$aggregateType] ?? null) !== self::PERSON_NO_EXCEPTION) {
                    continue;
                }

                $violations[] = \sprintf(
                    '%s (%s) reaches transport(s) %s through routing key "%s"',
                    $fqcn,
                    $aggregateType,
                    \implode(', ', $persisted),
                    $key,
                );
            }
        }

        return $violations;
    }

    /**
     * Resolves one routing key to the events Messenger would actually send through it, mirroring
     * `HandlersLocator::listTypes()`: the class itself, its parents, its interfaces, namespace wildcards and
     * the bare `'*'`. None of the last four is a concrete class, which is why a gate that read routing keys
     * as class names would step over every one of them.
     *
     * @param array<string, string> $events
     *
     * @return array<string, string>
     */
    public function eventsReachableFrom(string $key, array $events): array
    {
        if ('*' === $key) {
            return $events;
        }

        if (\str_ends_with($key, '\*')) {
            $prefix = \substr($key, 0, -1);

            return \array_filter(
                $events,
                static fn (string $fqcn): bool => \str_starts_with($fqcn, $prefix),
                ARRAY_FILTER_USE_KEY,
            );
        }

        return \array_filter(
            $events,
            static fn (string $fqcn): bool => $fqcn === $key || \is_subclass_of($fqcn, $key),
            ARRAY_FILTER_USE_KEY,
        );
    }

    /**
     * Anything unrecognised is rejected rather than read as non-person: a capitalisation slip must not
     * quietly become "safe to queue", which is the failure mode the registry exists against.
     */
    private function classificationOf(string $aggregateType, string $value): ?string
    {
        if (self::NON_PERSON === $value) {
            return null;
        }

        if (1 !== \preg_match('/^person(?:\s*::\s*(\S.*))?$/', $value, $person)) {
            throw new RuntimeException(\sprintf(
                'Unrecognised classification for "%s": "%s". Write exactly `%s`, `person`, or '
                . '`person :: <path of the ADR>`.',
                $aggregateType,
                $value,
                self::NON_PERSON,
            ));
        }

        return isset($person[1]) ? \trim($person[1]) : self::PERSON_NO_EXCEPTION;
    }

    /**
     * The `messenger.routing` map inside one top-level config section, whether that section is `framework`
     * itself or a `when@<env>` block wrapping one.
     *
     * @return array<array-key, mixed>
     */
    private function routingMapIn(mixed $section): array
    {
        if (!\is_array($section)) {
            return [];
        }

        $messenger = $section['messenger'] ?? null;

        if (!\is_array($messenger)) {
            $framework = $section['framework'] ?? null;
            $messenger = \is_array($framework) ? ($framework['messenger'] ?? null) : null;
        }

        if (!\is_array($messenger)) {
            return [];
        }

        $routing = $messenger['routing'] ?? null;

        return \is_array($routing) ? $routing : [];
    }

    /**
     * A routing value is one transport name or a list of them.
     *
     * @return list<string>
     */
    private function transportList(mixed $transports): array
    {
        $list = [];

        foreach (\is_array($transports) ? $transports : [$transports] as $transport) {
            if (\is_string($transport)) {
                $list[] = $transport;
            }
        }

        return $list;
    }
}
