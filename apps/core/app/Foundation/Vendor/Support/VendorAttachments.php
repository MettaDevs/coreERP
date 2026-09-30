<?php

declare(strict_types=1);

namespace App\Foundation\Vendor\Support;

use App\Foundation\Vendor\Models\Vendor;
use App\Platform\Environment\Support\CurrentWorkspace;
use App\Support\Access\CoreSecurityCatalog;
use App\Support\Modules\Contracts\AttachmentRecordType;
use App\Support\Modules\Contracts\DataClass;

/**
 * Lampiran vendor (kontrak, dokumen pajak), record milik Core. Haknya sama dengan layar Vendor: dibuka dengan
 * `core.vendor.read`, dilampiri dengan `core.vendor.update`.
 *
 * Klasifikasinya data pribadi, bukan isi bisnis: vendor bisa perorangan, dan dokumen pajak orang adalah data
 * pribadi. Aturan yang sama membuat `vendors.tax_number` diklasifikasi data pribadi.
 */
final class VendorAttachments implements AttachmentRecordType
{
    public function recordType(): string
    {
        return 'vendors';
    }

    public function moduleId(): ?string
    {
        return null;
    }

    public function dataClass(): DataClass
    {
        return DataClass::EndUserIdentifiableInformation;
    }

    public function canRead(string $tenantId, string $recordId): bool
    {
        return $this->allowed(CoreSecurityCatalog::VENDOR_READ) && $this->exists($tenantId, $recordId);
    }

    public function canChange(string $tenantId, string $recordId): bool
    {
        return $this->allowed(CoreSecurityCatalog::VENDOR_UPDATE) && $this->exists($tenantId, $recordId);
    }

    public function hasLine(string $tenantId, string $recordId, int $lineNumber): bool
    {
        return false;
    }

    private function allowed(string $permission): bool
    {
        // Dibaca saat ditanya, bukan disimpan saat boot: keanggotaan aktif milik permintaan yang sedang berjalan.
        return app(CurrentWorkspace::class)->membership(request())?->hasCorePermission($permission) ?? false;
    }

    private function exists(string $tenantId, string $recordId): bool
    {
        return Vendor::query()->where('tenant_id', $tenantId)->whereKey($recordId)->exists();
    }
}
