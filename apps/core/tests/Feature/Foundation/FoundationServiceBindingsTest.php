<?php

declare(strict_types=1);

namespace Tests\Feature\Foundation;

use App\Foundation\Currency\ModuleServices\CurrencyRoundingCore;
use App\Foundation\FinancePosting\ModuleServices\AccountDirectoryCore;
use App\Foundation\FinancePosting\ModuleServices\FinancePostingSettingsCore;
use App\Foundation\FinancePosting\ModuleServices\PostingFeedCore;
use App\Foundation\FinancePosting\Support\PostingAccountResolverRegistry;
use App\Foundation\FiscalCalendar\ModuleServices\FiscalCalendarDirectoryCore;
use App\Foundation\NumberSequence\ModuleServices\NumberSequenceIssuerCore;
use App\Foundation\UnitOfMeasure\ModuleServices\UnitOfMeasureDirectoryCore;
use App\Foundation\Vendor\ModuleServices\VendorDirectoryCore;
use App\Foundation\Workflow\ModuleServices\WorkflowEngineCore;
use App\Foundation\Workflow\Support\ParameterWorkflow;
use App\Platform\Modules\Contracts\AccountDirectory;
use App\Platform\Modules\Contracts\CurrencyRounding;
use App\Platform\Modules\Contracts\FinancePostingSettings;
use App\Platform\Modules\Contracts\FiscalCalendarDirectory;
use App\Platform\Modules\Contracts\NumberSequenceIssuer;
use App\Platform\Modules\Contracts\PostingAccountResolvers;
use App\Platform\Modules\Contracts\PostingFeed;
use App\Platform\Modules\Contracts\UnitOfMeasureDirectory;
use App\Platform\Modules\Contracts\VendorDirectory;
use App\Platform\Modules\Contracts\WorkflowEngine;
use Tests\TestCase;

/**
 * Fitur Foundation mendaftarkan ikatannya sendiri lewat penyedia layanan fiturnya, dan umur
 * ikatannya tidak boleh berubah karena pindah tempat.
 *
 * Umur yang salah tidak pernah gagal dengan suara. Facade yang tiba-tiba singleton membawa
 * ingatan satu permintaan ke permintaan berikutnya; daftar isian yang tiba-tiba `bind` menerima
 * pendaftaran module ke salinan yang langsung dibuang, dan Core melihat daftar kosong.
 */
class FoundationServiceBindingsTest extends TestCase
{
    /** @var array<class-string, class-string> */
    private const FACADES = [
        CurrencyRounding::class => CurrencyRoundingCore::class,
        AccountDirectory::class => AccountDirectoryCore::class,
        FinancePostingSettings::class => FinancePostingSettingsCore::class,
        PostingFeed::class => PostingFeedCore::class,
        FiscalCalendarDirectory::class => FiscalCalendarDirectoryCore::class,
        NumberSequenceIssuer::class => NumberSequenceIssuerCore::class,
        UnitOfMeasureDirectory::class => UnitOfMeasureDirectoryCore::class,
        VendorDirectory::class => VendorDirectoryCore::class,
        WorkflowEngine::class => WorkflowEngineCore::class,
    ];

    public function test_foundation_facades_resolve_a_fresh_instance_each_time(): void
    {
        foreach (self::FACADES as $contract => $implementation) {
            $first = $this->app->make($contract);

            $this->assertInstanceOf($implementation, $first, $contract);
            $this->assertNotSame($first, $this->app->make($contract), $contract.' seharusnya bind, bukan singleton.');
        }
    }

    public function test_posting_account_resolvers_is_one_instance_per_process(): void
    {
        $registry = $this->app->make(PostingAccountResolvers::class);

        $this->assertInstanceOf(PostingAccountResolverRegistry::class, $registry);
        $this->assertSame($registry, $this->app->make(PostingAccountResolvers::class));
        $this->assertSame($registry, $this->app->make(PostingAccountResolverRegistry::class));
    }

    public function test_parameter_workflow_is_scoped_to_one_request(): void
    {
        $first = $this->app->make(ParameterWorkflow::class);

        $this->assertSame($first, $this->app->make(ParameterWorkflow::class));

        $this->app->forgetScopedInstances();

        $this->assertNotSame($first, $this->app->make(ParameterWorkflow::class));
    }
}
