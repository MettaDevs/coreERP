<?php

declare(strict_types=1);

namespace App\Foundation\Workflow;

use App\Foundation\Workflow\ModuleServices\WorkflowEngineCore;
use App\Foundation\Workflow\Support\ParameterWorkflow;
use App\Platform\Modules\Contracts\WorkflowEngine;
use Illuminate\Support\ServiceProvider;

/**
 * Ikatan milik fitur ini, didaftarkan oleh fitur ini sendiri.
 *
 * Platform hanya menyediakan antarmukanya di `App\Platform\Modules\Contracts` dan tidak pernah
 * menyebut pelaksananya. Daftar seluruh antarmuka yang boleh dipanggil module tetap di `CoreServices`.
 */
final class WorkflowServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(WorkflowEngine::class, WorkflowEngineCore::class);

        // Scoped, bukan singleton: jawabannya tidak berubah di tengah satu permintaan, dan sebuah
        // workflow bercabang akan menanyakannya berkali-kali. Ingatannya dibuang di antara permintaan.
        $this->app->scoped(ParameterWorkflow::class);
    }
}
