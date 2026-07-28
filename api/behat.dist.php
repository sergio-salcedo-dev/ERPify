<?php

declare(strict_types=1);

use Behat\Config\Config;
use Behat\Config\Extension;
use Behat\Config\Filter\TagFilter;
use Behat\Config\GherkinOptions;
use Behat\Config\Profile;
use Behat\Config\Suite;
use Behat\Gherkin\GherkinCompatibilityMode;
use Erpify\Kernel;
use Erpify\Tests\Behat\Context\DoctrineContext;
use Erpify\Tests\Behat\Context\EntityManagerContext;
use Erpify\Tests\Behat\Context\EventStoreContext;
use Erpify\Tests\Behat\Context\FixturesContext;
use Erpify\Tests\Behat\Context\HttpRequestContext;
use Erpify\Tests\Behat\Context\HttpResponseContext;
use Erpify\Tests\Behat\Context\Json\JsonErrorContext;
use Erpify\Tests\Behat\Context\Json\JsonNodeContext;
use Erpify\Tests\Behat\Context\Json\JsonPathContext;
use Erpify\Tests\Behat\Context\Json\JsonSchemaContext;
use Erpify\Tests\Behat\Context\LoggerContext;
use Erpify\Tests\Behat\Context\MercureContext;
use Erpify\Tests\Behat\Context\MessengerConsumerContext;
use Erpify\Tests\Behat\Context\NotificationContext;
use Erpify\Tests\Behat\Context\OutboxContext;
use Erpify\Tests\Behat\Context\RateLimitContext;
use Erpify\Tests\Behat\Context\SecurityContext;
use Erpify\Tests\Behat\Context\SqlQueryContext;
use Erpify\Tests\Behat\Context\SymfonyCommandContext;
use FriendsOfBehat\SymfonyExtension\ServiceContainer\SymfonyExtension;

// Contexts are registered here rather than in services_test.yaml so the PHPUnit
// container never wires them: both suites run under APP_ENV=test, and Behat's
// contexts pull in services (fixtures loader, Mercure probe, outbox reader) that
// PHPUnit has no use for. FoB's SymfonyExtension turns each entry below into an
// autowired service in the Behat test container.
//
// %paths.base% is this file's directory (api/).
return (new Config())->withProfile(
    (new Profile('default'))
        // @wip scenarios are skipped by default (legacy Mink-era steps pending
        // migration to SymfonyExtension/KernelBrowser). Override with --tags=@wip
        // at invocation to run them.
        //
        // LEGACY is pinned rather than inherited: the parser default is GHERKIN_32,
        // which changes eight parsing behaviours at once (tag `@` prefix retention,
        // table-cell parsing, docstring delimiter unescaping, description padding,
        // step-keyword spacing, language-tag whitespace and validation). Only the
        // first breaks loudly here — SecurityContext matches the bare tag name, so
        // `@anonymous` scenarios silently authenticate and answer 403 instead of 401
        // — but the other seven can change what an already-green assertion means.
        // Every feature under features/ was authored and validated against LEGACY.
        // Upstream marks GHERKIN_32 incomplete and LEGACY as eventually removed, so
        // adopting cucumber parity is its own migration, not a side effect of a
        // dependency bump.
        //
        // Whoever runs that migration: gherkin's FileCache keys on the .feature path
        // and mtime, not on the compatibility mode, so flipping this line alone
        // serves ASTs parsed under the old mode. Touch the features or clear the
        // cache, otherwise the first run reports the mode change as a no-op.
        ->withGherkinOptions(
            (new GherkinOptions())
                ->withCompatibilityMode(GherkinCompatibilityMode::LEGACY)
                ->withFilter(new TagFilter('~@wip')),
        )
        ->withSuite(
            (new Suite('default'))
                ->withPaths(
                    '%paths.base%/features/backoffice',
                    '%paths.base%/features/frontoffice',
                    '%paths.base%/features/shared',
                )
                // Registration order = BeforeScenario hook order. FixturesContext
                // must run BEFORE DoctrineContext so the per-suite fixture load /
                // per-feature restore happens first and the stats reset wipes it —
                // otherwise the first scenario of a run measures fixture queries.
                ->withContexts(FixturesContext::class)
                // After FixturesContext (needs the fixture user + a restored DB) and before DoctrineContext,
                // so the login lookup is wiped by the per-scenario stats reset and never counts against a budget.
                ->withContexts(
                    SecurityContext::class,
                    DoctrineContext::class,
                    EntityManagerContext::class,
                )
                ->addContext(HttpRequestContext::class, ['baseUrl' => '/api/v1'])
                ->addContext(HttpResponseContext::class, ['serverPort' => 80])
                ->withContexts(
                    JsonErrorContext::class,
                    JsonNodeContext::class,
                    JsonPathContext::class,
                    JsonSchemaContext::class,
                    RateLimitContext::class,
                    SqlQueryContext::class,
                    OutboxContext::class,
                    EventStoreContext::class,
                    MessengerConsumerContext::class,
                    SymfonyCommandContext::class,
                    NotificationContext::class,
                    MercureContext::class,
                    LoggerContext::class,
                ),
        )
        ->withExtension(new Extension(SymfonyExtension::class, [
            'kernel' => [
                'class' => Kernel::class,
                'environment' => 'test',
                'debug' => true,
            ],
            'bootstrap' => '%paths.base%/tests/Behat/bootstrap.php',
        ])),
);
