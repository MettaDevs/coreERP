<?php

declare(strict_types=1);

namespace App\Platform\Modules\Contracts;

/** Pemilik record menentukan target nyata dan baris asalnya; pemanggil tidak memilih ID target. */
interface AttachmentFlowTarget
{
    /** @return iterable<array{record_id: string, source_line_number: ?int}> */
    public function inheritedAttachmentRecords(string $tenantId, string $sourceType, string $sourceId): iterable;
}
