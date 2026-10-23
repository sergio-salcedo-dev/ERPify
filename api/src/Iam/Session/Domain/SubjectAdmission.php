<?php

declare(strict_types=1);

namespace Erpify\Iam\Session\Domain;

/**
 * What the identity a session is about to be minted for answers once its row is held: admissible, or the wall
 * it now stands behind. The session context names the walls in its own vocabulary, so it never reads the
 * identity's status enum, and the caller that translates them into a response decides how much of the reason
 * the client is told.
 */
enum SubjectAdmission
{
    case Admissible;
    case Suspended;
    case Deactivated;
    case Gone;
}
