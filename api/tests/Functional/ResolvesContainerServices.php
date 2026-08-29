<?php

declare(strict_types=1);

namespace Erpify\Tests\Functional;

/**
 * Fetches a service out of the test container, typed, with the absence of a definition reported as a failed
 * assertion rather than as a `TypeError` five frames later.
 *
 * Every functional test that drives a collaborator directly — a lock-order probe, an erasure race, a CLI
 * bootstrap — needs the same two lines, and the type is the whole point of them: `TestContainer::get()`
 * answers `object|null`, so without the assertion a renamed or de-registered service surfaces as a null
 * dereference inside the case's own arrangement, where it reads like the behaviour under test failing. The
 * assertion names the id instead.
 *
 * `@template` is what makes the call site type-safe: the parameter is the class-string and the return is
 * that class, so PHPStan sees the concrete type without a `@var` at every caller. That is also why the id is
 * declared `class-string` rather than `string` — a service alias that is not a class name has no type to
 * return and belongs at a caller that says what it expects.
 *
 * @phpstan-require-extends \Symfony\Bundle\FrameworkBundle\Test\KernelTestCase
 */
trait ResolvesContainerServices
{
    /**
     * @template T of object
     *
     * @param class-string<T> $id
     *
     * @return T
     */
    private function service(string $id): object
    {
        $service = self::getContainer()->get($id);
        $this->assertInstanceOf($id, $service);

        return $service;
    }
}
