<?php

declare(strict_types=1);

namespace Erpify\Iam\Identity\Application;

use RuntimeException;

/**
 * A tick of {@see ResweepErasedSubjects} could not sweep every scheduled subject. Raised after the others were
 * swept, so one subject's failure never holds back the rest; raising at all is what makes the tick visible —
 * Messenger logs it at `critical` and the error tracker captures it.
 *
 * It counts failures and names their classes, never their messages, and it does not chain the cause. A failed
 * pass is a statement about one subject, and a driver message quoting a value would carry that subject's real
 * id into the log and the error tracker — the sinks no erasure reaches, which is the leak the re-sweep exists
 * to close. The failing subject keeps its row, so the next tick retries it and the detective source reports
 * it once it outlives its window.
 */
final class ErasureResweepIncomplete extends RuntimeException
{
    /**
     * @param list<class-string> $causes the class of each failure, in the order the subjects were swept
     */
    public static function after(array $causes, int $attempted): self
    {
        return new self(\sprintf(
            'The erasure re-sweep failed for %d of %d scheduled subjects (%s); each keeps its row and is retried '
            . 'on the next tick.',
            \count($causes),
            $attempted,
            \implode(', ', \array_values(\array_unique($causes))),
        ));
    }
}
