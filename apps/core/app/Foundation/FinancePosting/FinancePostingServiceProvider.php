<?php

declare(strict_types=1);

namespace App\Foundation\FinancePosting;

use App\Foundation\FinancePosting\ModuleServices\AccountDirectoryCore;
use App\Foundation\FinancePosting\ModuleServices\FinancePostingSettingsCore;
use App\Foundation\FinancePosting\ModuleServices\PostingFeedCore;
use App\Foundation\FinancePosting\Support\PostingAccountResolverRegistry;
use App\Platform\Modules\Contracts\AccountDirectory;
use App\Platform\Modules\Contracts\FinancePostingSettings;
use App\Platform\Modules\Contracts\PostingAccountResolvers;
use App\Platform\Modules\Contracts\PostingFeed;
use Illuminate\Support\ServiceProvider;

/**
 * Ikatan milik fitur ini, didaftarkan oleh fitur ini sendiri.
 *
 * Platform hanya menyediakan antarmukanya di `App\Platform\Modules\Contracts` dan tidak pernah
 * menyebut pelaksananya. Daftar seluruh antarmuka yang boleh dipanggil module tetap di `CoreServices`.
 */
final class FinancePostingServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(FinancePostingSettings::class, FinancePostingSettingsCore::class);
        $this->app->bind(AccountDirectory::class, AccountDirectoryCore::class);
        $this->app->bind(PostingFeed::class, PostingFeedCore::class);

        // Daftar isian yang diisi module, jadi satu benda untuk seluruh proses. Diikat dengan `bind`,
        // pendaftaran dari penyedia layanan module masuk ke salinan yang langsung dibuang, dan posting
        // yang dibentuk ulang tidak pernah menemukan akunnya. Alias dipasang ke arah kelas pelaksana,
        // supaya Core yang membaca dan module yang mengisi memegang benda yang sama.
        $this->app->singleton(PostingAccountResolverRegistry::class);
        $this->app->alias(PostingAccountResolverRegistry::class, PostingAccountResolvers::class);
    }
}
