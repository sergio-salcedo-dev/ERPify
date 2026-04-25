<?php

declare(strict_types=1);

namespace Erpify;

use Override;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\Config\Loader\LoaderInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Kernel as BaseKernel;

class Kernel extends BaseKernel
{
    use MicroKernelTrait {
        configureContainer as private defaultConfigureContainer;
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
     */
    private function configureContainer(ContainerConfigurator $container, LoaderInterface $loader, ContainerBuilder $builder): void
    {
        $this->defaultConfigureContainer($container, $loader, $builder);

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
