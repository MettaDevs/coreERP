<?php

use App\Foundation\Currency\CurrencyServiceProvider;
use App\Foundation\FinancePosting\FinancePostingServiceProvider;
use App\Foundation\FiscalCalendar\FiscalCalendarServiceProvider;
use App\Foundation\NumberSequence\NumberSequenceServiceProvider;
use App\Foundation\UnitOfMeasure\UnitOfMeasureServiceProvider;
use App\Foundation\Vendor\VendorServiceProvider;
use App\Foundation\Workflow\WorkflowServiceProvider;
use App\Providers\AppServiceProvider;
use App\Providers\FortifyServiceProvider;
use App\Providers\ModuleServiceProvider;

return [
    AppServiceProvider::class,
    FortifyServiceProvider::class,
    // Fitur Foundation mendaftarkan ikatannya sendiri, sebelum penyedia layanan module dimuat,
    // supaya module yang memakai facade-nya saat register menemukan pelaksananya.
    CurrencyServiceProvider::class,
    FinancePostingServiceProvider::class,
    FiscalCalendarServiceProvider::class,
    NumberSequenceServiceProvider::class,
    UnitOfMeasureServiceProvider::class,
    VendorServiceProvider::class,
    WorkflowServiceProvider::class,
    ModuleServiceProvider::class,
];
