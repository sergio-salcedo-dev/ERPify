<?php

declare(strict_types=1);

namespace Erpify\Shared\Domain\Logging;

/**
 * Severity levels for the domain {@see Logger} port. The backing values mirror the eight PSR-3 /
 * RFC 5424 level strings so the infrastructure adapter forwards them to a PSR-3 sink verbatim,
 * while the inner layers stay free of any `Psr\Log` import.
 */
enum LogLevel: string
{
    case Emergency = 'emergency';
    case Alert = 'alert';
    case Critical = 'critical';
    case Error = 'error';
    case Warning = 'warning';
    case Notice = 'notice';
    case Info = 'info';
    case Debug = 'debug';
}
