<?php

declare(strict_types=1);

namespace Erpify\Tests\Support;

use LogicException;
use Monolog\Handler\FingersCrossed\ErrorLevelActivationStrategy;
use Monolog\Handler\FingersCrossedHandler;
use Monolog\Level;
use Psr\Container\ContainerInterface;
use ReflectionProperty;
use Symfony\Bridge\Monolog\Handler\FingersCrossed\HttpCodeActivationStrategy;

/**
 * Reads the level at which the `main` handler the container COMPILED stops buffering and flushes, so a gate
 * can compare it against what the application logs without also owning the reflection that reaches it.
 *
 * The unwrapping is the half that is easy to get quietly wrong. With `excluded_http_codes` declared,
 * MonologBundle wraps the level strategy in `HttpCodeActivationStrategy`, which defers to that inner
 * strategy BEFORE it ever consults its exclusion list — so the threshold lives one layer in. It is unwrapped
 * here rather than required, because whether the exclusion is declared at all is a different invariant and
 * another gate's red; requiring the wrapper would make this one report the exclusion's absence under a
 * message about levels.
 *
 * A `passthruLevel` is refused instead of read past. `FingersCrossedHandler` writes every record at or above
 * it straight to the nested handler while still buffering, so the activation level stops being what decides
 * whether the sink sees the buffer — and a caller comparing against the level this class returns would be
 * comparing against a number that no longer governs.
 *
 * Every branch that cannot answer throws instead of returning a default: a handler that does not buffer, or
 * a strategy that activates on something other than a level, means the property is unobservable here, and a
 * caller that reads a fabricated number cannot tell that from a measurement.
 *
 * @internal test support
 */
final readonly class DeployedBufferActionLevel
{
    private const string HANDLER_ID = 'monolog.handler.main';

    public function __construct(private ContainerInterface $container)
    {
    }

    public function level(): Level
    {
        $handler = $this->container->get(self::HANDLER_ID);

        if (!$handler instanceof FingersCrossedHandler) {
            throw new LogicException('the deployed `main` handler does not buffer, so no level gates the sink');
        }

        if (null !== (new ReflectionProperty($handler, 'passthruLevel'))->getValue($handler)) {
            throw new LogicException('the deployed handler passes records through, so no level gates the sink');
        }

        $strategy = (new ReflectionProperty($handler, 'activationStrategy'))->getValue($handler);

        if ($strategy instanceof HttpCodeActivationStrategy) {
            $strategy = (new ReflectionProperty($strategy, 'inner'))->getValue($strategy);
        }

        if (!$strategy instanceof ErrorLevelActivationStrategy) {
            throw new LogicException('the deployed handler activates on something other than a level');
        }

        $level = (new ReflectionProperty($strategy, 'actionLevel'))->getValue($strategy);

        if (!$level instanceof Level) {
            throw new LogicException('the deployed strategy holds no level to activate on');
        }

        return $level;
    }
}
