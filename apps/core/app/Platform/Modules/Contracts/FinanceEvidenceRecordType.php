<?php

declare(strict_types=1);

namespace App\Platform\Modules\Contracts;

/** Hak baca bukti yang sudah dirujuk oleh posting finance milik klien integrasi. */
interface FinanceEvidenceRecordType extends AttachmentRecordType
{
    public function belongsToFinanceEntity(string $tenantId, string $legalEntityId, string $recordId): bool;
}
