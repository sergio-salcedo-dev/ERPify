<?php

declare(strict_types=1);

namespace Erpify\Tests\Support;

/**
 * The rule engine of ADR invariant 6 over the Documents tree: neither an HTTP transport type nor a caller-supplied
 * location reaches past `Infrastructure`. deptrac cannot state it — `Shared.Application` may not depend on
 * `Vendor.Symfony` covers the type axis only in part (`SplFileInfo` is core PHP) and the value axis not at all —
 * so the source is read as tokens.
 *
 * Two axes:
 *  - **type**: a transport type named anywhere — imported, written fully qualified, or (for the core classes)
 *    written bare. A short name like `File` or `Request` is judged through the file's own `use` imports.
 *  - **value**: a variable whose name names a location (`…path`, `…url`, `…uri`, `…location`, `…filename`),
 *    unless the type written right before it is the one sealed value that is never a location
 *    ({@see ALLOWED_LOCATION_TYPES}).
 *
 * Blind spots, stated: a location held under a neutral name (`$target`), a type reached through `class_alias`,
 * a string-built class name, and a value smuggled inside an array shape. The regression test pins what it does
 * see, so a scan that stops matching goes red instead of silently covering nothing.
 *
 * @SuppressWarnings("PHPMD.CyclomaticComplexity") one branch per token shape the two axes read
 * @SuppressWarnings("PHPMD.NPathComplexity") the same
 */
final class DocumentsTransportBoundary
{
    /** @var list<string> fully-qualified transport types, lower-cased, no leading backslash */
    private const array FORBIDDEN_FQCNS = [
        'symfony\component\httpfoundation\file\uploadedfile',
        'symfony\component\httpfoundation\file\file',
        'symfony\component\httpfoundation\request',
        'psr\http\message\uploadedfileinterface',
        'psr\http\message\uriinterface',
        'psr\http\message\serverrequestinterface',
        'splfileinfo',
        'splfileobject',
    ];

    /** @var list<string> core classes that need no import to be named */
    private const array BARE_FORBIDDEN = ['splfileinfo', 'splfileobject'];

    private const string LOCATION_NAME = '/(?:path|url|uri|location|filename)s?$/i';

    /** @var list<string> the sealed filename value and its judged submission, never a location */
    private const array ALLOWED_LOCATION_TYPES = ['originalfilename', 'filenamesubmission'];

    /**
     * @return list<string> one sentence per violation, naming the label and the token
     */
    public static function violationsIn(string $source, string $label): array
    {
        $tokens = \token_get_all($source);
        $imports = self::importsOf($tokens);
        $violations = [];

        foreach ($imports as $fqcn) {
            if (\in_array($fqcn, self::FORBIDDEN_FQCNS, true)) {
                $violations[] = \sprintf('%s imports the transport type %s', $label, $fqcn);
            }
        }

        $previousType = null;

        foreach ($tokens as $token) {
            if (!\is_array($token)) {
                $previousType = null;

                continue;
            }

            [$id, $text] = $token;

            if (T_WHITESPACE === $id || T_COMMENT === $id || T_DOC_COMMENT === $id) {
                continue;
            }

            if (
                T_NAME_FULLY_QUALIFIED === $id && \in_array(
                    \strtolower(\ltrim($text, '\\')),
                    self::FORBIDDEN_FQCNS,
                    true,
                )
            ) {
                $violations[] = \sprintf('%s names the transport type %s', $label, $text);
            }

            if (T_STRING === $id && \in_array(\strtolower($text), self::BARE_FORBIDDEN, true)) {
                $violations[] = \sprintf('%s names the transport type %s', $label, $text);
            }

            if (T_VARIABLE === $id) {
                $name = \substr($text, 1);
                $allowed = null !== $previousType && \in_array($previousType, self::ALLOWED_LOCATION_TYPES, true);

                if (!$allowed && 1 === \preg_match(self::LOCATION_NAME, $name)) {
                    $violations[] = \sprintf('%s holds a location-named value $%s', $label, $name);
                }
            }

            $previousType = \in_array($id, [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)
                ? \strtolower(\substr((string) \strrchr('\\' . $text, '\\'), 1))
                : null;
        }

        return \array_values(\array_unique($violations));
    }

    /**
     * @param list<array{0: int, 1: string, 2: int}|string> $tokens
     *
     * @return list<string> the fully-qualified names the file imports, lower-cased
     */
    private static function importsOf(array $tokens): array
    {
        $imports = [];
        $afterUse = false;

        foreach ($tokens as $token) {
            if (\is_array($token) && T_USE === $token[0]) {
                $afterUse = true;

                continue;
            }

            if (!$afterUse) {
                continue;
            }

            if (';' === $token || '{' === $token || '(' === $token) {
                $afterUse = false;

                continue;
            }

            if (\is_array($token) && \in_array($token[0], [T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_STRING], true)) {
                $imports[] = \strtolower(\ltrim($token[1], '\\'));
                $afterUse = false;
            }
        }

        return $imports;
    }
}
