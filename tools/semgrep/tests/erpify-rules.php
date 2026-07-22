<?php

declare(strict_types=1);

/**
 * Rule fixtures for ../rules/erpify-rules.yaml, exercised by `make semgrep.test`.
 *
 * The positive annotations below mark lines a rule MUST flag, the negative ones
 * lines it MUST NOT — so a rule that stops matching, or starts over-matching,
 * fails the suite. The vulnerable branches are deliberate and never executed.
 *
 * This file sits outside api/ on purpose: rector, php-cs-fixer and phpmd all
 * scan api/tools/, and a rewrite of the concatenations below would silently
 * invalidate the fixtures.
 */

namespace Erpify\Tools\Semgrep\Fixtures;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Process\Process;

final class TaintFixtures
{
    public function dqlInterpolated(EntityManagerInterface $entityManager, Request $request): mixed
    {
        $name = $request->query->get('name');

        // ruleid: erpify-symfony-request-to-dql
        return $entityManager->createQuery("SELECT b FROM Bank b WHERE b.name = '" . $name . "'")->getResult();
    }

    public function dqlParameterised(EntityManagerInterface $entityManager, Request $request): mixed
    {
        // ok: erpify-symfony-request-to-dql
        return $entityManager->createQuery('SELECT b FROM Bank b WHERE b.name = :name')
            ->setParameter('name', $request->query->get('name'))
            ->getResult();
    }

    public function shellCommandline(Request $request): void
    {
        // ruleid: erpify-symfony-request-to-shell
        $process = Process::fromShellCommandline('ls ' . $request->query->get('dir'));
        $process->run();
    }

    public function shellArrayForm(Request $request): void
    {
        // ok: erpify-symfony-request-to-shell
        $process = new Process(['ls', $request->query->get('dir')]);
        $process->run();
    }

    public function redirectFromRequest(Request $request): RedirectResponse
    {
        // ruleid: erpify-symfony-request-to-redirect
        return new RedirectResponse($request->query->get('next'));
    }

    public function redirectToConstant(): RedirectResponse
    {
        // ok: erpify-symfony-request-to-redirect
        return new RedirectResponse('/backoffice');
    }
}
