<?php

declare(strict_types=1);

$bundles = [
    Symfony\Bundle\FrameworkBundle\FrameworkBundle::class => ['all' => true],
    Doctrine\Bundle\DoctrineBundle\DoctrineBundle::class => ['all' => true],
    Doctrine\Bundle\MigrationsBundle\DoctrineMigrationsBundle::class => ['all' => true],
    Doctrine\Bundle\FixturesBundle\DoctrineFixturesBundle::class => ['dev' => true, 'test' => true],
    Nelmio\Alice\Bridge\Symfony\NelmioAliceBundle::class => ['dev' => true, 'test' => true],
    Fidry\AliceDataFixtures\Bridge\Symfony\FidryAliceDataFixturesBundle::class => ['dev' => true, 'test' => true],
    Hautelook\AliceBundle\HautelookAliceBundle::class => ['dev' => true, 'test' => true],
    Nelmio\CorsBundle\NelmioCorsBundle::class => ['all' => true],
    Symfony\Bundle\MercureBundle\MercureBundle::class => ['all' => true],
    League\FlysystemBundle\FlysystemBundle::class => ['all' => true],
    Symfony\Bundle\MonologBundle\MonologBundle::class => ['all' => true],
    Sentry\SentryBundle\SentryBundle::class => ['dev' => true, 'prod' => true],
    // Twig and the profiler are also enabled under `test` so the Behat `print the web profiler
    // link` debug step can collect a profile and resolve the `_profiler` URL (WebProfilerBundle
    // pulls in Twig for its templates). Collection stays opt-in per scenario — see web_profiler.yaml.
    Symfony\Bundle\TwigBundle\TwigBundle::class => ['dev' => true, 'test' => true],
    Symfony\Bundle\WebProfilerBundle\WebProfilerBundle::class => ['dev' => true, 'test' => true],
    Symfony\Bundle\DebugBundle\DebugBundle::class => ['dev' => true],
];

// friends-of-behat/symfony-extension ships only in the isolated tools/behat/vendor
// tree (Behat 3.31 pins Symfony DI/Config to ^7). Register the bundle only when
// that tree has been loaded (i.e. via tools/behat/run.php) so bin/console and
// PHPUnit under the main autoload keep working.
if (class_exists(FriendsOfBehat\SymfonyExtension\Bundle\FriendsOfBehatSymfonyExtensionBundle::class)) {
    $bundles[FriendsOfBehat\SymfonyExtension\Bundle\FriendsOfBehatSymfonyExtensionBundle::class] = ['test' => true];
}

return $bundles;
