<?php

declare(strict_types=1);

use ControlPlane\Http\Controllers\Agent\AgentApi;
use ControlPlane\Http\Controllers\Releases\RegisterRelease;
use ControlPlane\Http\Middleware\VerifyAgentSignature;
use Illuminate\Support\Facades\Route;

/*
 * Permukaan yang dipanggil dari luar peramban operator. Kontraknya `contracts/openapi-agent.yaml`,
 * dan `contracts/check-contract-coverage.py` menolak route yang tidak ada di sana.
 *
 * Tanpa sesi dan tanpa CSRF: pemanggilnya agen dan alur rilis, bukan peramban. Yang menjaga agen
 * tanda tangan per permintaan; yang menjaga alur rilis token ditambah tanda tangan berkasnya.
 */
Route::prefix('agent/v1')->group(function (): void {
    // Satu-satunya endpoint agen tanpa tanda tangan, jadi batasnya ketat: menebak token pendaftaran
    // tidak boleh murah.
    Route::post('enroll', [AgentApi::class, 'enroll'])->middleware('throttle:10,1');

    Route::middleware([VerifyAgentSignature::class, 'throttle:120,1'])->group(function (): void {
        Route::post('report', [AgentApi::class, 'report']);
        Route::post('operations/claim', [AgentApi::class, 'claim']);
        Route::post('operations/{operation}/steps', [AgentApi::class, 'step']);
        Route::get('releases/{edition}/{release}/files/{file}', [AgentApi::class, 'releaseFile'])
            ->where('file', '[A-Za-z0-9.]+');
        Route::post('registry-credential', [AgentApi::class, 'registryCredential']);
        Route::post('key', [AgentApi::class, 'rotateKey']);
    });
});

Route::post('releases/v1', RegisterRelease::class)->middleware('throttle:30,1');
