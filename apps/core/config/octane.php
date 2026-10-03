<?php

use App\Platform\Environment\Listeners\ResetDatabaseConnections;
use Laravel\Octane\Contracts\OperationTerminated;
use Laravel\Octane\Events\RequestHandled;
use Laravel\Octane\Events\RequestReceived;
use Laravel\Octane\Events\RequestTerminated;
use Laravel\Octane\Events\TaskReceived;
use Laravel\Octane\Events\TaskTerminated;
use Laravel\Octane\Events\TickReceived;
use Laravel\Octane\Events\TickTerminated;
use Laravel\Octane\Events\WorkerErrorOccurred;
use Laravel\Octane\Events\WorkerStarting;
use Laravel\Octane\Events\WorkerStopping;
use Laravel\Octane\Listeners\CloseMonologHandlers;
use Laravel\Octane\Listeners\EnsureUploadedFilesAreValid;
use Laravel\Octane\Listeners\EnsureUploadedFilesCanBeMoved;
use Laravel\Octane\Listeners\FlushOnce;
use Laravel\Octane\Listeners\FlushTemporaryContainerInstances;
use Laravel\Octane\Listeners\ReportException;
use Laravel\Octane\Listeners\StopWorkerIfNecessary;
use Laravel\Octane\Octane;

/*
 * Peran web dilayani FrankenPHP dalam mode worker: Laravel di-boot sekali per worker lalu melayani
 * banyak permintaan, bukan di-boot ulang tiap permintaan seperti di Apache mod_php.
 *
 * Diukur 3 Oktober 2026 pada endpoint aset dengan 2 CPU: boot ulang memakan ±20 ms CPU per
 * permintaan sebelum kode apa pun berjalan, dan kapasitas naik dari 125 menjadi 350 pengguna
 * serentak. Harganya: apa pun yang disimpan di memori proses — properti statis, singleton, ikatan
 * container di aplikasi induk — hidup terus ke permintaan berikutnya, yang bisa milik tenant lain.
 * Octane menyalin container per permintaan dan membuang salinannya; yang tidak ikut terbuang
 * adalah yang disimpan di luar salinan itu. Daftar `flush` dan pendengar di bawah adalah tempat
 * membereskannya.
 */
return [

    'server' => env('OCTANE_SERVER', 'frankenphp'),

    // TLS diakhiri proxy di depan container (Traefik di SaaS, Caddy di server klien).
    'https' => env('OCTANE_HTTPS', false),

    'listeners' => [
        WorkerStarting::class => [
            EnsureUploadedFilesAreValid::class,
            EnsureUploadedFilesCanBeMoved::class,
        ],

        RequestReceived::class => [
            ...Octane::prepareApplicationForNextOperation(),
            ...Octane::prepareApplicationForNextRequest(),
        ],

        RequestHandled::class => [],

        RequestTerminated::class => [],

        TaskReceived::class => [
            ...Octane::prepareApplicationForNextOperation(),
        ],

        TaskTerminated::class => [],

        TickReceived::class => [
            ...Octane::prepareApplicationForNextOperation(),
        ],

        TickTerminated::class => [],

        OperationTerminated::class => [
            FlushOnce::class,
            FlushTemporaryContainerInstances::class,
            // Koneksi database hidup selama umur worker: variabel sesinya, koneksi environment, dan
            // transaksi yang tertinggal tidak boleh ikut ke permintaan berikutnya.
            ResetDatabaseConnections::class,
        ],

        WorkerErrorOccurred::class => [
            ReportException::class,
            StopWorkerIfNecessary::class,
        ],

        WorkerStopping::class => [
            CloseMonologHandlers::class,
        ],
    ],

    'warm' => [
        ...Octane::defaultServicesToWarm(),
    ],

    // Layanan yang di-resolve ulang setiap permintaan, bukan dipakai ulang dari boot.
    'flush' => [],

    'garbage' => 50,

    'max_execution_time' => 30,

    'state_file' => env('OCTANE_STATE_FILE', storage_path('logs/octane-server-state.json')),

];
