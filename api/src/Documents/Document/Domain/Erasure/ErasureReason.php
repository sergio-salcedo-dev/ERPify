<?php

declare(strict_types=1);

namespace Erpify\Documents\Document\Domain\Erasure;

/**
 * Why an operator erases a document: a closed vocabulary, never free text, so a report can count them. It is the
 * HTTP route's own type and holds neither of {@see ErasureSystemReason}'s values, so a caller cannot pass itself off
 * as a restore or as the completion sweep.
 */
enum ErasureReason: string
{
    case DataSubjectRequest = 'data_subject_request';
    case LegalObligation = 'legal_obligation';
    case UploadedInError = 'uploaded_in_error';
    case RetentionExpired = 'retention_expired';
}
