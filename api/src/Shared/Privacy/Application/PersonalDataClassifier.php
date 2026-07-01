<?php

declare(strict_types=1);

namespace Erpify\Shared\Privacy\Application;

/**
 * Reads the {@see \Erpify\Shared\Privacy\Domain\PersonalData} classification of an entity so a consumer
 * can decide, per column, what to encrypt and what to keep in clear.
 */
interface PersonalDataClassifier
{
    /**
     * @param object|class-string $entityOrClass
     *
     * @return list<string> the names of the properties marked personal data, sorted for determinism
     */
    public function personalFieldsOf(object|string $entityOrClass): array;
}
