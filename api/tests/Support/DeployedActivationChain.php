<?php

declare(strict_types=1);

namespace Erpify\Tests\Support;

use LogicException;
use Monolog\Handler\FingersCrossed\ActivationStrategyInterface;
use Monolog\Handler\FingersCrossedHandler;
use Psr\Container\ContainerInterface;
use ReflectionObject;
use Symfony\Bridge\Monolog\Handler\FingersCrossed\HttpCodeActivationStrategy;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * What the deployed `main` handler consults, read out of the container it is compiled into — so a gate can ask
 * which statuses stay out of the sink without also owning the reflection that finds them.
 *
 * **What is pinned is a property, not a shape.** The deployed handler must consult an excluded-code list this
 * class can read as unconditional; WHERE that list sits is pinned nowhere, so the chain is walked by following
 * VALUES — a decorator names its inner strategy whatever it likes — and only the absence of such a list from
 * the whole chain is refused. Deleting `excluded_http_codes` is what that absence looks like: MonologBundle
 * then builds a bare `ErrorLevelActivationStrategy` and every 404 flushes the buffer. A decorator around the
 * configured strategy is accepted, and so is any rearrangement that keeps one readable list reachable.
 *
 * The carrier's class is still `HttpCodeActivationStrategy` — the list is its private `exclusions` — and a
 * strategy of another class excluding codes of its own is refused **because nothing here can read it**, which
 * is what the refusal says. Every refusal below is phrased as a limit of this reader or as an absence it
 * measured, never as a claim about what the deployment excludes.
 *
 * Blind spots of the walk: a strategy reachable only through a non-strategy object, one nested more than one
 * array level deep or behind a `Traversable` that is not an array, one built at call time inside
 * `isHandlerActivated()`, and one held in a static property, in a virtual (unbacked) hooked property, or by an
 * object that is still lazy. Hiding the SOLE carrier reports the list absent, which is a red. Hiding a SECOND
 * carrier is the direction that fails toward green — the ambiguity refusal below counts what the walk found,
 * so a list it cannot see reads as one list rather than two. MonologBundle builds the carrier as the OUTERMOST
 * object, where nothing hides it, which is why this is recorded rather than closed.
 *
 * @internal test support
 */
final readonly class DeployedActivationChain
{
    public function __construct(private ContainerInterface $container)
    {
    }

    /**
     * The strategy the deployed handler consults — the outermost object, a decorator included, because that is
     * what decides activation. Its concrete class is not pinned.
     */
    public function outermostStrategy(): ActivationStrategyInterface
    {
        $chain = $this->strategyChain();

        // Reading the list is the guard, and it runs on this path too: a measurement of behaviour alone would
        // otherwise drive a chain whose exclusion nothing here can interpret, and answer as if it had.
        $this->excludedCodesOf($this->excludingStrategyIn($chain));

        return $chain[0];
    }

    /**
     * The codes the deployed chain excludes unconditionally. A URL-scoped exclusion holds only for the paths it
     * names, so it is not the unconditional property a caller would read it as, and it is refused here rather
     * than silently counted.
     *
     * @return list<int>
     */
    public function unconditionallyExcludedCodes(): array
    {
        return $this->excludedCodesOf($this->excludingStrategyIn($this->strategyChain()));
    }

    /**
     * The request stacks the deployed chain consults. The CARRIER's own stack is required and comes first,
     * because that is the instance its exclusion reads: entering another one under the same service id makes
     * an assertion pass by never reaching the exclusion at all. The rest of the chain's are entered too — a
     * decorator may gate on one of its own — and the instances are the container's, shared with whatever runs
     * next in this kernel.
     *
     * @return non-empty-list<RequestStack>
     */
    public function requestStacks(): array
    {
        $chain = $this->strategyChain();

        // The carrier is read before any stack, so a chain that excludes nothing is refused in those terms
        // rather than as a missing request stack — the accurate diagnosis of the same deletion.
        $stacks = $this->requestStacksHeldBy($this->excludingStrategyIn($chain));

        if ([] === $stacks) {
            throw new LogicException(
                'the strategy carrying the excluded-code list holds no request stack, so the exclusion it '
                . 'declares is never evaluated at all',
            );
        }

        $seen = [];

        foreach ($stacks as $stack) {
            $seen[\spl_object_id($stack)] = true;
        }

        foreach ($chain as $strategy) {
            foreach ($this->requestStacksHeldBy($strategy) as $candidate) {
                $id = \spl_object_id($candidate);

                if (isset($seen[$id])) {
                    continue;
                }

                $seen[$id] = true;
                $stacks[] = $candidate;
            }
        }

        return $stacks;
    }

    /**
     * Every activation strategy the deployed handler reaches, outermost first. A decorator names its inner
     * strategy whatever it likes, so the walk follows values rather than a property name.
     *
     * @return non-empty-list<ActivationStrategyInterface>
     */
    private function strategyChain(): array
    {
        $handler = $this->deployedHandler();
        $outermost = $this->valueHeldBy(
            $handler,
            'activationStrategy',
            'the deployed handler no longer names an activation strategy, so nothing here can read what gates it',
        );

        if (!$outermost instanceof ActivationStrategyInterface) {
            throw new LogicException('the deployed handler consults no activation strategy, so nothing gates it');
        }

        $chain = [$outermost];
        $seen = [\spl_object_id($outermost) => true];

        for ($i = 0; isset($chain[$i]); ++$i) {
            foreach ($this->objectsHeldBy($chain[$i]) as $candidate) {
                if (!$candidate instanceof ActivationStrategyInterface) {
                    continue;
                }

                $id = \spl_object_id($candidate);

                if (isset($seen[$id])) {
                    continue;
                }

                $seen[$id] = true;
                $chain[] = $candidate;
            }
        }

        return $chain;
    }

    /**
     * @param non-empty-list<ActivationStrategyInterface> $chain
     */
    private function excludingStrategyIn(array $chain): HttpCodeActivationStrategy
    {
        $carriers = [];

        foreach ($chain as $strategy) {
            if ($strategy instanceof HttpCodeActivationStrategy) {
                $carriers[] = $strategy;
            }
        }

        if ([] === $carriers) {
            throw new LogicException(
                'no strategy the deployed handler consults carries an excluded-code list this class can read, '
                . 'so nothing here can name the statuses that stay out of the sink',
            );
        }

        // The walk models object references, not delegation order, so with two lists reachable "the first one"
        // says nothing about which one decides. Ambiguity is refused rather than resolved by position.
        if (1 !== \count($carriers)) {
            throw new LogicException(
                'the deployed chain carries more than one excluded-code list, so no single one names what stays '
                . 'out of the sink',
            );
        }

        return $carriers[0];
    }

    /**
     * @return list<int>
     */
    private function excludedCodesOf(HttpCodeActivationStrategy $strategy): array
    {
        $exclusions = $this->valueHeldBy(
            $strategy,
            'exclusions',
            'the deployed strategy no longer names its excluded-code list, so nothing here can read it',
        );

        if (!\is_array($exclusions)) {
            throw new LogicException('the deployed strategy holds no exclusion list');
        }

        $codes = [];

        foreach ($exclusions as $exclusion) {
            if (!\is_array($exclusion) || !\is_int($exclusion['code'] ?? null)) {
                throw new LogicException('an exclusion declares no integer code');
            }

            // Key presence, not `?? []`: an exclusion declaring `urls` as null would collapse to the empty list
            // and read as unconditional, while the deployed strategy fatals on `count(null)`.
            if ([] !== ($exclusion['urls'] ?? null)) {
                throw new LogicException(\sprintf(
                    'the exclusion for %d does not declare an empty URL list, so it is not the unconditional '
                    . 'exclusion a caller reads it as',
                    $exclusion['code'],
                ));
            }

            $codes[] = $exclusion['code'];
        }

        return $codes;
    }

    /**
     * @return list<RequestStack>
     */
    private function requestStacksHeldBy(object $strategy): array
    {
        $stacks = [];

        foreach ($this->objectsHeldBy($strategy) as $candidate) {
            if ($candidate instanceof RequestStack) {
                $stacks[] = $candidate;
            }
        }

        return $stacks;
    }

    /**
     * Every object the given one holds, directly or one array level deep — a decorator's inner strategy has no
     * conventional name, so nothing here can ask for one by name.
     *
     * `get_mangled_object_vars()` is the read, and its semantics are the ones this wants, measured rather than
     * assumed: it answers with the object's own property table, so an inherited private property and the one
     * shadowing it both appear; statics and uninitialised typed properties are absent; a hooked property
     * answers with its BACKING value and a virtual one is absent, which is the raw read a walk needs, since a
     * get hook is user code and one minting a fresh object per read would keep the walk from closing; and a
     * lazy object answers with nothing while staying uninitialised, which is the blind spot the class note
     * records rather than a side effect this causes.
     *
     * @return list<object>
     */
    private function objectsHeldBy(object $object): array
    {
        $held = [];

        foreach (\get_mangled_object_vars($object) as $value) {
            foreach (\is_array($value) ? $value : [$value] as $candidate) {
                if (\is_object($candidate)) {
                    $held[] = $candidate;
                }
            }
        }

        return $held;
    }

    /**
     * Reflects one named property, refusing its absence in terms of the invariant rather than letting a raw
     * `ReflectionException` name a vendor property a reader has no reason to know.
     *
     * Unlike the walk above this DOES run a get hook and DOES initialise a lazy object — `getProperty()` is
     * one of the operations that trigger initialisation. Neither vendor class read here has a hook or is
     * lazy, so it costs nothing today; it is stated because the walk's raw-read rationale does not extend to
     * this reader.
     */
    private function valueHeldBy(object $object, string $property, string $absence): mixed
    {
        $class = new ReflectionObject($object);

        if (!$class->hasProperty($property)) {
            throw new LogicException($absence);
        }

        return $class->getProperty($property)->getValue($object);
    }

    private function deployedHandler(): FingersCrossedHandler
    {
        $handler = $this->container->get('monolog.handler.main');

        if (!$handler instanceof FingersCrossedHandler) {
            throw new LogicException(
                'the deployed `main` handler is not a `FingersCrossedHandler`, so no activation strategy gates '
                . 'what reaches the sink',
            );
        }

        // `passthru_level` is the one deployed option a replica cannot model: `flushBuffer()` ships every
        // buffered record at or above it to the nested handler on close, activation or none. A gate driving a
        // replica would then report an excluded status as kept out while the deployment writes it anyway —
        // the only shape found that fails toward green — so it is refused rather than mirrored, because
        // mirroring would move the leak into a measurement instead of closing it.
        if (
            null !== $this->valueHeldBy(
                $handler,
                'passthruLevel',
                'the deployed handler no longer names a passthru level, so nothing here can tell whether records '
                . 'reach the sink without activating it',
            )
        ) {
            throw new LogicException(
                'the deployed handler declares a passthru level, so records reach the sink without activating '
                . 'it and nothing here measures what stays out',
            );
        }

        return $handler;
    }
}
