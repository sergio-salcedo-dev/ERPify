<?php

declare(strict_types=1);

namespace Erpify;

use Erpify\Shared\Event\Infrastructure\DependencyInjection\RegisterDomainEventsPass;
use Override;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\Compiler\PassConfig;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Kernel as BaseKernel;

class Kernel extends BaseKernel
{
    use MicroKernelTrait {
        configureContainer as private defaultConfigureContainer;
    }

    #[Override]
    protected function build(ContainerBuilder $container): void
    {
        // Builds the (eventName, eventVersion) ⇒ class map for the event store from a compile-time scan
        // of every concrete DomainEvent, failing the build on an eventName collision.
        $container->addCompilerPass(new RegisterDomainEventsPass());

        // The isolated Behat toolchain (tools/behat/vendor) pulls in symfony/translation, which the
        // app itself does not depend on — so the Behat kernel registers a TranslationDataCollector
        // and serialises it into every profile. The `print the web profiler link` debug step targets
        // the dev server's /_profiler page, but the dev process (main vendor only) cannot load that
        // class and renders it as __PHP_Incomplete_Class, 500-ing the page. Dropping the collector tag
        // keeps Behat-collected profiles renderable cross-kernel; it is the one divergent collector.
        if ('1' !== \getenv('BEHAT_RUNNING')) {
            return;
        }

        $container->addCompilerPass(
            new class implements CompilerPassInterface {
                #[Override]
                public function process(ContainerBuilder $container): void
                {
                    if ($container->hasDefinition('data_collector.translation')) {
                        $container->getDefinition('data_collector.translation')->clearTag('data_collector');
                    }
                }
            },
            PassConfig::TYPE_BEFORE_OPTIMIZATION,
            100,
        );
    }

    #[Override]
    public function getCacheDir(): string
    {
        // Segregate Behat's compiled container from PHPUnit's: both run with
        // env=test but Behat also loads tools/behat/vendor, and the compiled
        // container bakes absolute paths. Sharing the cache causes
        // `Cannot redeclare Psr\Container\ContainerInterface`.
        if ('1' === \getenv('BEHAT_RUNNING')) {
            return parent::getCacheDir() . '_behat';
        }

        return parent::getCacheDir();
    }

    /**
     * @SuppressWarnings("PHPMD.UnusedPrivateMethod")
     *
     * This method is called indirectly, invoked by MicroKernelTrait::registerContainerConfiguration
     * via the trait alias — PHPStan can't see that call site through the alias either.
     *
     * @phpstan-ignore method.unused (invoked via the trait alias)
     */
    private function configureContainer(ContainerConfigurator $container): void
    {
        $this->defaultConfigureContainer($container);

        // Behat-only service definitions (kept out of services_test.yaml so
        // PHPUnit never autoloads classes whose parents live in the isolated
        // tools/behat/vendor — see api/tools/behat/bootstrap.php).
        if ('1' === \getenv('BEHAT_RUNNING')) {
            $configDir = $this->getConfigDir();

            if (\is_file($configDir . '/services_behat.yaml')) {
                $container->import($configDir . '/services_behat.yaml');
            }
        }
    }
}
