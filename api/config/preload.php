<?php

declare(strict_types=1);

if (file_exists(__DIR__ . '/../var/cache/prod/App_KernelProdContainer.preload.php')) {
    opcache_compile_file(__DIR__ . '/../var/cache/prod/App_KernelProdContainer.preload.php');
}
