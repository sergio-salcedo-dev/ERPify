<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Gate;

use Erpify\Tests\Support\Dig;
use Erpify\Tests\Support\RepositoryRoot;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * An evidence PUT spools its whole plaintext original to `upload_tmp_dir`, so where that directory lives is a
 * confidentiality control held by four files nothing else connects: the ini that names it, the tmpfs compose
 * mounts there, the prod swap limit that keeps those pages off swap, and the entrypoint that refuses to start
 * FrankenPHP over anything but a tmpfs — PHP itself falls back to /tmp on the writable layer with only a notice.
 * Deleting any one of the four leaves every other gate green.
 *
 * @internal
 */
#[CoversNothing]
final class UploadSpoolConfinementGateTest extends TestCase
{
    #[Test]
    public function theSpoolIsATmpfsKeptOffSwapAndCheckedAtBoot(): void
    {
        $spool = $this->iniSpoolDirectory();
        $root = RepositoryRoot::path() ?? $this->fail(
            'The root compose files are unreachable: restore the read-only `./` bind mount at /app/repo.',
        );

        $php = Dig::array($this->compose($root . '/compose.yaml'), 'services', 'php');
        $tmpfs = $php['tmpfs'] ?? [];
        $this->assertIsArray($tmpfs);
        $this->assertNotEmpty(
            \array_filter(
                $tmpfs,
                static fn (mixed $mount): bool => \is_string($mount)
                    && ($mount === $spool || \str_starts_with($mount, $spool . ':')),
            ),
            \sprintf('compose.yaml mounts no tmpfs at upload_tmp_dir (%s) for php', $spool),
        );

        $prod = Dig::array($this->compose($root . '/compose.prod.yaml'), 'services', 'php');
        $this->assertSame(
            Dig::value($prod, 'deploy', 'resources', 'limits', 'memory'),
            $prod['memswap_limit'] ?? null,
            'compose.prod.yaml lets the php container swap: its tmpfs pages, plaintext originals, could reach disk',
        );

        $entrypoint = (string) \file_get_contents(\dirname(__DIR__, 3) . '/frankenphp/docker-entrypoint.sh');
        $this->assertStringContainsString('ini_get("upload_tmp_dir")', $entrypoint);
        $this->assertMatchesRegularExpression(
            '/stat -f -c \'%T\' "\$SPOOL_DIR"\)" != \'tmpfs\' \]; then\n[^\n]*\n\s*exit 1/',
            $entrypoint,
            'the entrypoint no longer refuses to start over a spool directory that is not a tmpfs',
        );
    }

    private function iniSpoolDirectory(): string
    {
        $ini = \parse_ini_file(\dirname(__DIR__, 3) . '/frankenphp/conf.d/10-app.ini');
        $this->assertIsArray($ini);
        $spool = $ini['upload_tmp_dir'] ?? null;
        $this->assertIsString($spool, 'upload_tmp_dir is no longer set by 10-app.ini');
        $this->assertStringStartsWith('/', $spool);

        return $spool;
    }

    /**
     * @return array<array-key, mixed>
     */
    private function compose(string $path): array
    {
        $compose = Yaml::parseFile($path);
        $this->assertIsArray($compose);

        return $compose;
    }
}
