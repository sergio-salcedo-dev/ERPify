<?php

declare(strict_types=1);

namespace Erpify\Tests\Support;

use ReflectionAttribute;
use ReflectionClass;
use ReflectionIntersectionType;
use ReflectionNamedType;
use ReflectionType;
use ReflectionUnionType;
use RuntimeException;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Resolves what the credential-proof rules judge, from the tree: the route universe from the committed
 * router inventory (`.route-manifest.json`, which `php.lint.route-manifest` holds equal to the prod router),
 * the registry, the firewall's public patterns, and — per route — the controller that declares it.
 *
 * A route is mapped to its controller by REFLECTING the `#[Route]` attributes under `src/`, so a name spelled
 * through `self::ROUTE_NAME` resolves exactly as Symfony resolves it rather than by matching text.
 *
 * @internal test support
 */
final readonly class CredentialProofPolicy
{
    private function __construct(private string $apiRoot)
    {
    }

    public static function fromApiRoot(string $apiRoot): self
    {
        return new self($apiRoot);
    }

    /**
     * @return array<string, string> route name => path
     */
    public function routePaths(): array
    {
        $file = $this->apiRoot . '/.route-manifest.json';

        if (!\is_file($file)) {
            throw new RuntimeException(
                'api/.route-manifest.json is missing — regenerate it with `make sf.routes.manifest`.',
            );
        }

        $manifest = \json_decode((string) \file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);

        if (!\is_array($manifest)) {
            throw new RuntimeException('api/.route-manifest.json is not a JSON object.');
        }

        $paths = [];

        foreach ($manifest as $name => $route) {
            if (!\is_string($name) || !\is_array($route) || !\is_string($route['path'] ?? null)) {
                throw new RuntimeException(\sprintf(
                    'api/.route-manifest.json entry "%s" has no string path — regenerate it with'
                    . ' `make sf.routes.manifest`.',
                    $name,
                ));
            }

            $paths[$name] = $route['path'];
        }

        return $paths;
    }

    /**
     * @return array<string, array{class: string, reason: ?string}>
     */
    public function registry(): array
    {
        return CredentialProofRules::parse(AllowlistFile::entries($this->apiRoot . '/.credential-proof-policy'));
    }

    /**
     * The keys of `.public-access-exemptions` — the registry that gate holds equal to `security.yaml`'s
     * anonymous rules: exact and prefix PATH patterns, and `route:<name>` keys, kept as written.
     *
     * @return list<string>
     */
    public function publicPatterns(): array
    {
        $patterns = [];

        foreach (AllowlistFile::entries($this->apiRoot . '/.public-access-exemptions') as $line) {
            $patterns[] = \trim(\explode(' => ', $line, 2)[0]);
        }

        return $patterns;
    }

    /**
     * Every `#[Route]` name declared under `src/`, mapped to the action serving it, named as Symfony's
     * attribute loader names it: a method-level route's name is prefixed by its class-level route's name, and a
     * class-level route on a class without method-level routes is served by `__invoke`.
     *
     * @return array<string, list<array{class: class-string, method: string}>>
     */
    public function actionsByRoute(): array
    {
        $byRoute = [];

        foreach (ApiSourceFiles::phpFiles($this->apiRoot . '/src') as $file) {
            if (!\str_contains((string) \file_get_contents($file->getPathname()), 'Route')) {
                continue;
            }

            $class = $this->classOf($file->getPathname());

            if (null === $class) {
                continue;
            }

            foreach ($this->actionsOf($class) as $name => $method) {
                $byRoute[$name][] = ['class' => $class, 'method' => $method];
            }
        }

        return $byRoute;
    }

    /**
     * @param class-string $class
     *
     * @return array<string, list<string>> constructor parameter name => every class its type names
     */
    public static function collaborators(string $class): array
    {
        $collaborators = [];

        foreach ((new ReflectionClass($class))->getConstructor()?->getParameters() ?? [] as $parameter) {
            $collaborators[$parameter->getName()] = self::namesIn($parameter->getType());
        }

        return $collaborators;
    }

    /**
     * The controller's collaborators that live in an `Application` layer, with their source and their own
     * collaborators — the use cases the proof is expected to sit in.
     *
     * @param class-string $controller
     *
     * @return array<string, array{source: string, collaborators: array<string, list<string>>}>
     */
    public static function useCasesOf(string $controller): array
    {
        $useCases = [];

        foreach (self::collaborators($controller) as $parameter => $types) {
            foreach ($types as $type) {
                if (!\str_contains($type, '\Application\\') || !\class_exists($type)) {
                    continue;
                }

                $file = (new ReflectionClass($type))->getFileName();
                $useCases[$parameter] = [
                    'source' => false === $file ? '' : (string) \file_get_contents($file),
                    'collaborators' => self::collaborators($type),
                ];
            }
        }

        return $useCases;
    }

    /**
     * @param class-string $class
     */
    public static function sourceOf(string $class): string
    {
        $file = (new ReflectionClass($class))->getFileName();

        return false === $file ? '' : (string) \file_get_contents($file);
    }

    /**
     * @param class-string $class
     *
     * @return array<string, string> route name => method name
     */
    private function actionsOf(string $class): array
    {
        $reflection = new ReflectionClass($class);
        $classRoutes = \array_map(
            static fn (ReflectionAttribute $attribute): Route => $attribute->newInstance(),
            $reflection->getAttributes(Route::class),
        );
        $actions = [];

        foreach ($reflection->getMethods() as $reflectionMethod) {
            foreach ($reflectionMethod->getAttributes(Route::class) as $attribute) {
                $name = $attribute->newInstance()->name;

                if (null !== $name) {
                    $actions[($classRoutes[0]->name ?? '') . $name] = $reflectionMethod->getName();
                }
            }
        }

        if ([] !== $actions || !$reflection->hasMethod('__invoke')) {
            return $actions;
        }

        foreach ($classRoutes as $classRoute) {
            if (null !== $classRoute->name) {
                $actions[$classRoute->name] = '__invoke';
            }
        }

        return $actions;
    }

    /**
     * PSR-4: `Erpify\` maps to `src/`.
     *
     * @return class-string|null
     */
    private function classOf(string $path): ?string
    {
        $relative = \substr($path, \strlen($this->apiRoot . '/src/'), -\strlen('.php'));
        $class = 'Erpify\\' . \str_replace('/', '\\', $relative);

        return \class_exists($class) ? $class : null;
    }

    /**
     * @return list<string>
     */
    private static function namesIn(?ReflectionType $type): array
    {
        if ($type instanceof ReflectionNamedType) {
            return [$type->getName()];
        }

        if ($type instanceof ReflectionUnionType || $type instanceof ReflectionIntersectionType) {
            $names = [];

            foreach ($type->getTypes() as $reflectionType) {
                $names = [...$names, ...self::namesIn($reflectionType)];
            }

            return $names;
        }

        return [];
    }
}
