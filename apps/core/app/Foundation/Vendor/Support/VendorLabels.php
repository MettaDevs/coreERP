<?php

declare(strict_types=1);

namespace App\Foundation\Vendor\Support;

use App\Foundation\Vendor\Models\Vendor;
use App\Platform\Modules\Contracts\Analytics\SharedDimension;
use App\Platform\Modules\Contracts\Analytics\SharedDimensionResolver;
use App\Platform\Modules\Contracts\DataClass;

/**
 * Nama vendor untuk dimensi bersama analitik, dari party-nya di buku alamat, termasuk vendor yang
 * sudah nonaktif: dokumen lama tetap harus menampilkan vendornya.
 *
 * Party vendor dapat berupa orang, dan namanya berkelas `EndUserIdentifiableInformation` di buku alamat;
 * label ini membawa kelas yang sama, sehingga hanya tampil bagi yang berhak membaca data pribadi.
 */
final class VendorLabels implements SharedDimensionResolver
{
    public function dimension(): SharedDimension
    {
        return SharedDimension::Vendor;
    }

    public function labelClassification(): DataClass
    {
        return DataClass::EndUserIdentifiableInformation;
    }

    public function labels(string $tenantId, array $ids): array
    {
        $labels = [];
        foreach (Vendor::query()->with('party')->where('tenant_id', $tenantId)->whereIn('id', $ids)->get() as $vendor) {
            $labels[$vendor->id] = (string) $vendor->party->getAttribute('name');
        }

        return $labels;
    }
}
