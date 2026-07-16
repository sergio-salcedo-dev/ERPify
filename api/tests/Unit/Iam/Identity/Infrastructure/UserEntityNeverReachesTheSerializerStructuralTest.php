<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Iam\Identity\Infrastructure;

use Erpify\Iam\Identity\Application\Resource\UserDetailResource;
use Erpify\Iam\Identity\Application\Resource\UserListResource;
use Erpify\Iam\Identity\Domain\Entity\User;
use Erpify\Iam\Identity\Infrastructure\Controller\UserGetController;
use Erpify\Iam\Identity\Infrastructure\Controller\UserSearchController;
use Erpify\Iam\Identity\Infrastructure\Http\UserResourceMapper;
use Erpify\Shared\Search\Domain\Page;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionParameter;
use ReflectionProperty;

/**
 * Structural gate for the security invariant "the {@see User} entity never reaches the serializer". Unlike
 * the golden HTTP test, this resists FORMAT changes: it pins the shape of the read path by TYPE, not by a
 * response snapshot. Two guarantees, each by reflection:
 *   - every public mapping method of {@see UserResourceMapper} returns a per-view Resource DTO (or a
 *     {@see Page} of them), never the {@see User} entity — so the object handed to the responder is a DTO;
 *   - the read controllers depend on the mapper plus a responder and on NO serializer/normalizer, so the
 *     only path an identity takes to the wire is through the mapper (there is no bypass that could pass the
 *     entity directly), and the Resource DTOs expose none of the credential fields.
 *
 * @internal
 */
#[CoversNothing]
final class UserEntityNeverReachesTheSerializerStructuralTest extends TestCase
{
    private const array MAPPING_METHOD_RETURNS = [
        'toListResource' => UserListResource::class,
        'toDetailResource' => UserDetailResource::class,
        'toListPage' => Page::class,
    ];

    private const array CREDENTIAL_FIELDS = ['passwordHash', 'failedAttempts', 'lockedUntil', 'permissions'];

    public function testMapperPublicMethodsReturnResourceDtosNeverTheEntity(): void
    {
        foreach (self::MAPPING_METHOD_RETURNS as $method => $expectedReturn) {
            $returnType = (new ReflectionMethod(UserResourceMapper::class, $method))->getReturnType();
            $this->assertInstanceOf(ReflectionNamedType::class, $returnType);
            $this->assertSame($expectedReturn, $returnType->getName(), \sprintf(
                '%s must return %s, never the User entity.',
                $method,
                $expectedReturn,
            ));
            $this->assertNotSame(User::class, $returnType->getName());
        }
    }

    /**
     * @param class-string $resourceClass
     */
    #[DataProvider('provideResourceDtosExposeExactlyTheWireFieldsAndNoCredentialCases')]
    public function testResourceDtosExposeExactlyTheWireFieldsAndNoCredential(string $resourceClass): void
    {
        $properties = \array_map(
            static fn (ReflectionProperty $property): string => $property->getName(),
            (new ReflectionClass($resourceClass))->getProperties(),
        );

        $this->assertSame(['id', 'email', 'status', 'roles', 'createdAt', 'updatedAt'], $properties);

        foreach (self::CREDENTIAL_FIELDS as $credentialField) {
            $this->assertNotContains($credentialField, $properties);
        }
    }

    /**
     * @return iterable<string, array{class-string}>
     */
    public static function provideResourceDtosExposeExactlyTheWireFieldsAndNoCredentialCases(): iterable
    {
        yield 'list' => [UserListResource::class];
        yield 'detail' => [UserDetailResource::class];
    }

    /**
     * @param class-string $controllerClass
     */
    #[DataProvider('provideReadControllersDependOnTheMapperAndNoSerializerCases')]
    public function testReadControllersDependOnTheMapperAndNoSerializer(string $controllerClass): void
    {
        $constructor = (new ReflectionClass($controllerClass))->getConstructor();
        $this->assertInstanceOf(ReflectionMethod::class, $constructor);

        $dependencies = \array_map(
            $this->typeName(...),
            $constructor->getParameters(),
        );

        // The mapper is a mandatory collaborator: the entity is turned into a DTO before the responder.
        $this->assertContains(UserResourceMapper::class, $dependencies);

        // No serializer / normalizer dependency means there is no path that could serialize the entity
        // directly, bypassing the mapper.
        foreach ($dependencies as $dependency) {
            $this->assertIsNotSerializer($dependency);
        }
    }

    /**
     * @return iterable<string, array{class-string}>
     */
    public static function provideReadControllersDependOnTheMapperAndNoSerializerCases(): iterable
    {
        yield 'search' => [UserSearchController::class];
        yield 'get' => [UserGetController::class];
    }

    private function assertIsNotSerializer(?string $dependency): void
    {
        if (null === $dependency) {
            return;
        }

        $this->assertStringNotContainsStringIgnoringCase('serializer', $dependency);
        $this->assertStringNotContainsStringIgnoringCase('normalizer', $dependency);
    }

    private function typeName(ReflectionParameter $parameter): ?string
    {
        $type = $parameter->getType();

        return $type instanceof ReflectionNamedType ? $type->getName() : null;
    }
}
