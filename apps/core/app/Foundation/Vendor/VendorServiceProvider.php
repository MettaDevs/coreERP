<?php

declare(strict_types=1);

namespace App\Foundation\Vendor;

use App\Foundation\Vendor\ModuleServices\VendorDirectoryCore;
use App\Foundation\Vendor\Support\VendorAttachments;
use App\Foundation\Vendor\Support\VendorLabels;
use App\Platform\Modules\Contracts\Analytics\SharedDimensions;
use App\Platform\Modules\Contracts\AttachmentRecordTypes;
use App\Platform\Modules\Contracts\VendorDirectory;
use Illuminate\Support\ServiceProvider;

/**
 * Ikatan milik fitur ini, didaftarkan oleh fitur ini sendiri.
 *
 * Platform hanya menyediakan antarmukanya di `App\Platform\Modules\Contracts` dan tidak pernah
 * menyebut pelaksananya. Daftar seluruh antarmuka yang boleh dipanggil module tetap di `CoreServices`.
 */
final class VendorServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(VendorDirectory::class, VendorDirectoryCore::class);
    }

    public function boot(): void
    {
        // Lampiran dokumen (gap 7): vendor adalah record milik Core yang boleh diberi lampiran. Module
        // mendaftarkan record miliknya dari penyedia layanannya sendiri.
        $this->app->make(AttachmentRecordTypes::class)->register(new VendorAttachments);
        // Engine analitik: vendor adalah dimensi bersama yang ditunjuk dataset module; labelnya dari sini,
        // karena Platform tidak boleh menyebut fitur Foundation.
        $this->app->make(SharedDimensions::class)->register(new VendorLabels);
    }
}
