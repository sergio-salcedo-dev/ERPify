<?php

declare(strict_types=1);

namespace Erpify\Tests\Support;

use RuntimeException;

/**
 * The imperative half of the permission sweep: which permissions `src` checks by calling the authorization
 * checker mid-method, rather than by declaring `#[IsGranted]` on a class or a route.
 *
 * The attribute sweep cannot see these at all — it reads attributes through reflection — so before this class
 * existed a permission checked imperatively and missing from the catalog passed every gate. That is not merely
 * an untested string: `/me` enumerates the catalog, so a permission the catalog does not publish is one the
 * console can never see, and every UI gate on it stays shut while the API enforces it happily.
 *
 * **Unresolvable arguments raise rather than degrade**, and that is the whole design. A sweep that skipped what
 * it could not read would under-report in exactly the situation it exists for, staying green while a permission
 * escaped the catalog — the same silence the gate was built to end. So a call site must pass either a quoted
 * literal or a `self::`/`static::` constant this class can resolve in the same file; anything else fails the
 * build with a message saying how to write it. Both call sites in the tree already use the constant form, so
 * the rule costs nothing today and is what keeps the sweep honest tomorrow.
 *
 * Blind spots, stated so a green build is read for what it proves: a constant reached from ANOTHER class
 * (`Holder::PERMISSION`) is not resolved — it raises, which is safe but will need this class extended the day a
 * call site legitimately wants it; a permission assembled at runtime cannot be read from source by any means and
 * likewise raises; and a check reached through a wrapper this regex does not name (anything other than
 * `isGranted` / `denyAccessUnlessGranted`) is invisible. The last one is the real residual: it fails open.
 *
 * @internal test support
 */
final class ImperativePermissionChecks
{
    /**
     * The first argument of every imperative authorization call, captured verbatim for resolution below.
     * Bounded at the first `,` or `)` so a subject-carrying call yields the attribute and not the subject.
     */
    private const string CALL = '/(?:isGranted|denyAccessUnlessGranted)\s*\(\s*([^,)]+)/';

    private const string LITERAL = "/^'([^']+)'$/";

    private const string CLASS_CONSTANT = '/^(?:self|static)::(\w+)$/';

    /**
     * @param array<string, string> $sources path relative to `api/src` => file contents
     *
     * @return array<string, string> permission => the file whose call site names it
     */
    public static function in(array $sources): array
    {
        $permissions = [];

        foreach ($sources as $file => $source) {
            $code = self::codeWithoutComments($source);

            \preg_match_all(self::CALL, $code, $calls, PREG_SET_ORDER);

            foreach ($calls as [, $argument]) {
                $permissions[self::resolve(\trim($argument), $code, $file)] = $file;
            }
        }

        return $permissions;
    }

    private static function resolve(string $argument, string $code, string $file): string
    {
        if (1 === \preg_match(self::LITERAL, $argument, $literal)) {
            return $literal[1];
        }

        if (1 === \preg_match(self::CLASS_CONSTANT, $argument, $constant)) {
            return self::declaredConstant($constant[1], $code, $file, $argument);
        }

        throw new RuntimeException(\sprintf(
            '%s checks a permission as `%s`, which this sweep cannot resolve to a literal — so the permission '
            . 'would stay out of the set compared against PermissionCatalog and could go missing from `/me` '
            . 'with every gate green. Pass a quoted literal, or a `self::` constant declared in the same file.',
            $file,
            $argument,
        ));
    }

    private static function declaredConstant(string $name, string $code, string $file, string $argument): string
    {
        $declaration = \sprintf("/const\\s+(?:string\\s+)?%s\\s*=\\s*'([^']+)'/", \preg_quote($name, '/'));

        if (1 !== \preg_match($declaration, $code, $literal) || !isset($literal[1])) {
            throw new RuntimeException(\sprintf(
                '%s checks a permission as `%s`, but no `const %s = \'…\'` is declared in that file, so the '
                . 'sweep cannot tell which permission it means. Declare the constant beside the call, or pass '
                . 'the literal.',
                $file,
                $argument,
                $name,
            ));
        }

        return $literal[1];
    }

    private static function codeWithoutComments(string $source): string
    {
        $code = '';

        foreach (\token_get_all($source) as $token) {
            if (\is_array($token) && \in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            $code .= \is_array($token) ? $token[1] : $token;
        }

        return $code;
    }
}
