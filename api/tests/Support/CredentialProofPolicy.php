<?php

declare(strict_types=1);

namespace Erpify\Tests\Support;

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
        $json = (string) \file_get_contents($this->apiRoot . '/.route-manifest.json');
        $manifest = \json_decode($json, true, 512, JSON_THROW_ON_ERROR);

        if (!\is_array($manifest)) {
            throw new RuntimeException('api/.route-manifest.json is not a JSON object.');
        }

        $paths = [];

        foreach ($manifest as $name => $route) {
            if (\is_string($name) && \is_array($route) && \is_string($route['path'] ?? null)) {
                $paths[$name] = $route['path'];
            }
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
     * The exact and prefix PATH patterns of `.public-access-exemptions` — the registry that gate holds equal
     * to `security.yaml`'s anonymous rules. A `route:` key is kept as-is and matches nothing here.
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
     * Every `#[Route]` name declared under `src/`, mapped to the class carrying it.
     *
     * @return array<string, list<class-string>>
     */
    public function controllersByRoute(): array
    {
        $byRoute = [];

        foreach (ApiSourceFiles::phpFiles($this->apiRoot . '/src') as $file) {
            $source = (string) \file_get_contents($file->getPathname());

            if (!\str_contains($source, '#[Route')) {
                continue;
            }

            $class = $this->classOf($file->getPathname());

            if (null === $class) {
                continue;
            }

            $reflection = new ReflectionClass($class);
            $attributes = $reflection->getAttributes(Route::class);

            foreach ($reflection->getMethods() as $method) {
                $attributes = [...$attributes, ...$method->getAttributes(Route::class)];
            }

            foreach ($attributes as $attribute) {
                $name = $attribute->newInstance()->name;

                if (null !== $name) {
                    $byRoute[$name][] = $class;
                }
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
