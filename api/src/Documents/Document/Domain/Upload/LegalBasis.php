<?php

declare(strict_types=1);

namespace Erpify\Documents\Document\Domain\Upload;

/**
 * The lawful basis a document is processed under, one of the six of GDPR Article 6(1).
 */
enum LegalBasis: string
{
    case Consent = 'consent';
    case Contract = 'contract';
    case LegalObligation = 'legal-obligation';
    case VitalInterests = 'vital-interests';
    case PublicTask = 'public-task';
    case LegitimateInterests = 'legitimate-interests';
}
