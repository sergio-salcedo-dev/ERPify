<?php

declare(strict_types=1);

namespace Erpify\Tests\Support;

use Erpify\Shared\Event\Domain\DomainEvent;
use ReflectionAttribute;
use ReflectionClass;
use RuntimeException;
use Symfony\Component\Messenger\Attribute\AsMessage;

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
    /**
     * A classification is three-state and the distinction is load-bearing: `null` is non-person (never a
     * violation), this constant is a person with no sanctioned exception (a violation once routed), and any
     * other string is the ADR path of a declared exception.
     */
    public const string PERSON_NO_EXCEPTION = '';

    private const string NON_PERSON = 'non-person';

    private MessengerRoutingConfig $config;

    public function __construct(private string $apiRoot)
    {
        $this->config = new MessengerRoutingConfig($apiRoot);
    }

    public function config(): MessengerRoutingConfig
    {
        return $this->config;
    }

    public static function fromGateLocation(string $gateDirectory): self
    {
        return new self(\dirname($gateDirectory, 3));
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
     * `#[AsMessage(transport:)]` as Symfony reads it: over the class, its parents and its interfaces, matching
     * subclasses of the attribute, and MERGING every occurrence because the attribute is repeatable. PHP does
     * not inherit attributes, so reflecting only the concrete class — the obvious implementation — misses an
     * attribute on an abstract carrier or a marker interface, which is the idiomatic way to route a whole
     * aggregate at once.
     *
     * @return list<string>
     */
    public function attributeTransportsFor(string $fqcn): array
    {
        if (!\class_exists($fqcn)) {
            return [];
        }

        $transports = [];

        foreach ($this->selfParentsAndInterfaces($fqcn) as $class) {
            $reflection = new ReflectionClass($class);
            $attributes = $reflection->getAttributes(AsMessage::class, ReflectionAttribute::IS_INSTANCEOF);

            foreach ($attributes as $attribute) {
                $transports = [...$transports, ...$this->config->transportList($attribute->newInstance()->transport)];
            }
        }

        return \array_values(\array_unique($transports));
    }

    /**
     * The transports one event is actually sent through, ported from `SendersLocator::send()`: walk the type
     * list, skip wildcard entries once a concrete type has already matched (they are fallbacks, not extra
     * senders), and fall back to the attribute only when the map matched nothing at all.
     *
     * Resolving per EVENT rather than per routing key is what makes the answer faithful. Keyed the other way,
     * `{'*': async, SomeEvent: sync}` reads as a violation even though Symfony sends that event to `sync`
     * alone — a red build on correct config, which is how a gate teaches people to route around it.
     *
     * @param array<string, list<string>> $routes
     *
     * @return list<string>
     */
    public function sendersFor(string $fqcn, array $routes): array
    {
        $seen = [];

        foreach ($this->listTypes($fqcn) as $type) {
            if (\str_ends_with($type, '*') && [] !== $seen) {
                continue;
            }

            foreach ($routes[$type] ?? [] as $sender) {
                if (!\in_array($sender, $seen, true)) {
                    $seen[] = $sender;
                }
            }
        }

        return [] === $seen ? $this->attributeTransportsFor($fqcn) : $seen;
    }

    /**
     * The other direction of the `person :: <ADR>` form, and the one nothing was checking.
     *
     * That spelling does not mean "classified conservatively". It means "classified conservatively AND
     * queued anyway, with the ADR arguing why" — the registry's own words. So an event whose type carries
     * an ADR exception and reaches NO transport has an exception that argues for nothing: it is handled in
     * process instead, inside the publisher's own transaction, which is the effect the exception was
     * written to avoid.
     *
     * Measured before this existed: deleting one routing line left `php.lint.persistent-transport` and
     * `php.lint.event-bus` both at exit 0. The completeness check demands a registry line "routed or not"
     * and never looks at the route, so the classification stayed valid while its premise disappeared.
     *
     * Derived from the registry rather than from a list of class names: a type spelled `person` with no
     * `::` is deliberately unrouted and is not a subject here.
     *
     * @param array<string, list<string>> $routes routing key => transport names
     * @param array<string, string>       $events event FQCN => aggregateType
     *
     * @return list<string>
     */
    public function adrExceptedEventsReachingNoTransport(array $routes, array $events): array
    {
        $classification = $this->classification();
        $violations = [];

        foreach ($events as $fqcn => $aggregateType) {
            $exception = $classification[$aggregateType] ?? null;

            if (null === $exception || self::PERSON_NO_EXCEPTION === $exception) {
                continue;
            }

            if ([] !== $this->sendersFor($fqcn, $routes)) {
                continue;
            }

            $violations[] = \sprintf(
                '%s (%s) is classified `person :: %s` but reaches no transport, so it is handled in process',
                $fqcn,
                $aggregateType,
                $exception,
            );
        }

        return $violations;
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

        foreach ($events as $fqcn => $aggregateType) {
            // Non-person and ADR-excepted are allowed; unclassified is the completeness check's job.
            if (($classification[$aggregateType] ?? null) !== self::PERSON_NO_EXCEPTION) {
                continue;
            }

            $persisted = \array_values(\array_filter(
                $this->sendersFor($fqcn, $routes),
                static fn (string $t): bool => MessengerRoutingConfig::NON_PERSISTED_TRANSPORT !== $t,
            ));

            if ([] === $persisted) {
                continue;
            }

            $violations[] = \sprintf(
                '%s (%s) reaches persisted transport(s) %s',
                $fqcn,
                $aggregateType,
                \implode(', ', $persisted),
            );
        }

        return $violations;
    }

    /**
     * Ported from `HandlersLocator::listTypes()`, which `SendersLocator` walks: the class, its parents, its
     * interfaces, the namespace wildcards, and the bare `'*'`. Four of those five are not a concrete class,
     * which is why a gate that read routing keys as class names would step over them.
     *
     * @return list<string>
     */
    public function listTypes(string $fqcn): array
    {
        if (!\class_exists($fqcn)) {
            return [$fqcn, '*'];
        }

        $types = $this->selfParentsAndInterfaces($fqcn) + $this->listWildcards($fqcn) + ['*' => '*'];

        return \array_values($types);
    }

    /**
     * @return array<class-string, class-string>
     */
    private function selfParentsAndInterfaces(string $fqcn): array
    {
        if (!\class_exists($fqcn)) {
            return [];
        }

        /** @var array<class-string, class-string> $types */
        $types = [$fqcn => $fqcn]
            + (\class_parents($fqcn) ?: [])
            + (\class_implements($fqcn) ?: []);

        return $types;
    }

    /**
     * @return array<string, string>
     */
    private function listWildcards(string $type): array
    {
        $type .= '\*';
        $wildcards = [];

        while ($i = \strrpos($type, '\\', -3)) {
            $type = \substr_replace($type, '\*', $i);
            $wildcards[$type] = $type;
        }

        return $wildcards;
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
}
