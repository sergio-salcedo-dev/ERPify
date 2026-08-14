<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Shared\Architecture;

use Erpify\Backoffice\BankAccount\Application\Command\CreateBankAccountCommand;
use Erpify\Backoffice\BankAccount\Application\Command\UpdateBankAccountCommand;
use Erpify\Backoffice\BankAccount\Domain\Entity\BankAccount;
use FilesystemIterator;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use SplFileInfo;
use Symfony\Component\Validator\Constraint;

/**
 * A validation message may describe the RULE it enforced, never the VALUE it rejected.
 *
 * A violation message is interpolated before anyone sees it, and its destinations are not the
 * caller's screen alone: `ValidationFailedException::getMessage()` renders the whole violation list,
 * and that string reaches the per-error log line and the Sentry event. Both are sinks no erasure path
 * reaches, so a placeholder carrying the rejected value writes a person's data into records that
 * outlive the erasure the application confirmed to them.
 *
 * Measured, not hypothetical: `Assert\Bic`'s default `ibanMessage` is
 * "…is not associated with IBAN {{ iban }}.", `BankAccount::$iban` is `#[PersonalData]`, and a
 * mismatched BIC put a real account number into `var/log` on a live request.
 *
 * The universe is every class under `src` that declares a constraint — not only the ones classified
 * `#[PersonalData]`. The command DTOs hold the same holder name, IBAN and alias straight off the wire
 * and declare no such attribute, and they are the layer that rejects first, so scoping the gate to the
 * classification would point the control away from the class it protects.
 *
 * What a green does NOT prove:
 * - that a message with no placeholder is safe — a constraint could hard-code something sensitive,
 *   which no automation can judge;
 * - that an allowlisted placeholder is safe in a constraint that chose to fill it with input;
 * - anything about constraints declared on METHODS, or nested inside a composite (`All`,
 *   `Sequentially`, `Collection`, `AtLeastOneOf`, `When`) — a composite exposes no message option of
 *   its own, so `#[Assert\All([new Assert\Bic()])]` carries the untouched default past this gate;
 * - anything about private properties declared on a PARENT class, which reflection does not surface
 *   here;
 * - that the message ever reaches a sink — reducing what the log and Sentry carry is
 *   `ExceptionResponder`'s job and has its own tests.
 *
 * @internal
 */
#[CoversNothing]
final class ConstraintMessageValueGateTest extends TestCase
{
    private const string RULE = 'A constraint message must not interpolate the value it rejected: '
        . 'violation messages are rendered into the per-error log line and the Sentry event, neither '
        . 'of which any erasure path reaches. Give the constraint a message that names the rule.';

    private const string NAMESPACE_PREFIX = 'Erpify\\';

    /**
     * Placeholders that describe the constraint's own CONFIGURATION and so cannot carry input: a
     * declared bound, charset, choice list or comparand. Measured against the tree — `src` authors
     * only `{{ limit }}` and `{{ choices }}` today — and deliberately an allowlist, because the
     * dangerous names are whatever a future constraint invents.
     */
    private const array STRUCTURAL_PLACEHOLDERS = [
        '{{ limit }}',
        '{{ charset }}',
        '{{ choices }}',
        '{{ compared_value }}',
    ];

    /**
     * A floor rather than an exact pin. The set is 27 classes and grows with every DTO, so pinning it
     * would produce a routine red that a reader learns to satisfy without reading — while a walk that
     * silently stopped reaching the tree is the failure this guards. The three named classes are the
     * ones the measured leak travelled through.
     */
    private const int MINIMUM_CLASSES_WALKED = 20;

    #[Test]
    public function noConstraintMessageInterpolatesTheValueItRejected(): void
    {
        $classes = $this->classesDeclaringConstraints();

        $this->assertGreaterThanOrEqual(
            self::MINIMUM_CLASSES_WALKED,
            \count($classes),
            self::RULE . PHP_EOL . 'The walk reached fewer classes than the tree holds, so a green '
                . 'here would prove nothing. Fix the walk before touching this number.',
        );

        foreach ([BankAccount::class, CreateBankAccountCommand::class, UpdateBankAccountCommand::class] as $required) {
            $this->assertContains($required, $classes, self::RULE . PHP_EOL . \sprintf(
                'The walk no longer reaches %s, which is one of the classes the leak travelled through.',
                $required,
            ));
        }

        $offenders = [];

        foreach ($classes as $class) {
            foreach ($this->messagesDeclaredOn($class) as $where => $message) {
                foreach ($this->placeholdersIn($message) as $placeholder) {
                    if (!\in_array($placeholder, self::STRUCTURAL_PLACEHOLDERS, true)) {
                        $offenders[] = \sprintf('%s carries %s in "%s"', $where, $placeholder, $message);
                    }
                }
            }
        }

        $this->assertSame([], $offenders, self::RULE . PHP_EOL . \implode(PHP_EOL, $offenders));
    }

    /**
     * The gate's own falsifiability: the predicate has to be able to say NO. `Assert\Bic`'s untouched
     * default is the message this gate exists to refuse, so it is the fixture.
     */
    #[Test]
    public function thePlaceholderPredicateCanRefuse(): void
    {
        $default = 'This Business Identifier Code (BIC) is not associated with IBAN {{ iban }}.';

        $this->assertSame(['{{ iban }}'], $this->placeholdersIn($default));
        $this->assertSame(['{{ value }}'], $this->placeholdersIn('The value {{ value }} is taken.'));
        $this->assertSame([], $this->placeholdersIn('The BIC does not match the IBAN country.'));
        $this->assertSame(
            ['{{ limit }}'],
            $this->placeholdersIn('This value is too long. It should have {{ limit }} characters or less.'),
        );
    }

    /**
     * @return list<string>
     */
    private function placeholdersIn(string $message): array
    {
        \preg_match_all('/\{\{\s*[A-Za-z_]\w*\s*\}\}/', $message, $matches);

        return \array_values(\array_unique($matches[0]));
    }

    /**
     * Every message-bearing option of every constraint declared on the class itself or on one of its
     * properties. The class level is not optional: `#[UniqueEntity]` sits there and its validator sets
     * `{{ value }}` to the offending input, so a property-only scan would miss the one constraint on
     * `BankAccount` that can name an IBAN.
     *
     * @param class-string $class
     *
     * @return array<string, string>
     */
    private function messagesDeclaredOn(string $class): array
    {
        $reflection = new ReflectionClass($class);
        $sites = [['(class)', $reflection->getAttributes()]];

        foreach ($reflection->getProperties() as $reflectionProperty) {
            $sites[] = ['$' . $reflectionProperty->getName(), $reflectionProperty->getAttributes()];
        }

        $messages = [];

        foreach ($sites as [$site, $attributes]) {
            foreach ($attributes as $attribute) {
                if (!\is_subclass_of($attribute->getName(), Constraint::class)) {
                    continue;
                }

                foreach (\get_object_vars($attribute->newInstance()) as $option => $value) {
                    if (!\is_string($option)) {
                        continue;
                    }

                    if (!\is_string($value)) {
                        continue;
                    }

                    if (!\str_contains(\strtolower($option), 'message')) {
                        continue;
                    }

                    $messages[\sprintf('%s::%s %s::$%s', $class, $site, $attribute->getName(), $option)] = $value;
                }
            }
        }

        return $messages;
    }

    /**
     * @return list<class-string>
     */
    private function classesDeclaringConstraints(): array
    {
        $sourceRoot = $this->apiRoot() . '/src';
        $prefix = \strlen($sourceRoot) + 1;
        $found = [];

        $tree = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($sourceRoot, FilesystemIterator::SKIP_DOTS),
        );

        foreach ($tree as $file) {
            if (!$file instanceof SplFileInfo) {
                continue;
            }

            if (!$file->isFile()) {
                continue;
            }

            if ('php' !== $file->getExtension()) {
                continue;
            }

            $relative = \substr($file->getPathname(), $prefix);
            $candidate = self::NAMESPACE_PREFIX . \str_replace('/', '\\', \substr($relative, 0, -4));

            if (!\class_exists($candidate)) {
                continue;
            }

            if ([] !== $this->messagesDeclaredOn($candidate)) {
                $found[] = $candidate;
            }
        }

        \sort($found);

        return $found;
    }

    private function apiRoot(): string
    {
        return \dirname(__DIR__, 4);
    }
}
