<?php

declare(strict_types=1);

namespace Erpify;

use Erpify\Shared\Event\Infrastructure\DependencyInjection\RegisterDomainEventsPass;
use Override;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
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
    }

    #[Override]
    public function getCacheDir(): string
    {
        // Segregate Behat's compiled container from PHPUnit's: both run under
        // env=test, but only Behat imports services_behat.yaml, so the two
        // compile to different containers. Symfony keys the container cache on
        // env + debug alone, so a shared directory has each runner overwrite the
        // other's compiled container and rebuild on every alternating run.
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

        // Behat-only service definitions, kept out of services_test.yaml so the
        // PHPUnit container never wires the step contexts and the services they
        // pull in. BEHAT_RUNNING is set by api/tests/Behat/bootstrap.php.
        if ('1' === \getenv('BEHAT_RUNNING')) {
            $configDir = $this->getConfigDir();

            if (\is_file($configDir . '/services_behat.yaml')) {
                $container->import($configDir . '/services_behat.yaml');
            }
        }
    }
}
