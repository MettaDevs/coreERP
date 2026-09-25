<?php

use Illuminate\Support\Facades\Route;
use Modules\Apperp\ManagementAset\Http\Controllers\master\AssetPostingGroupController;

// Posting group aset (TODO 8.1): satu baris per group dan tanggal berlaku, disimpan dengan
// PUT ke alamat pasangan itu sehingga pengulangan permintaan tidak menambah baris.
Route::get('posting-group-aset', [AssetPostingGroupController::class, 'index']);
Route::get('posting-group-aset/akun', [AssetPostingGroupController::class, 'accounts']);
Route::put('posting-group-aset/{groupAset}/{effectiveFrom}', [AssetPostingGroupController::class, 'upsert'])
    ->where('effectiveFrom', '\d{4}-\d{2}-\d{2}');
Route::delete('posting-group-aset/{groupAset}/{effectiveFrom}', [AssetPostingGroupController::class, 'archive'])
    ->where('effectiveFrom', '\d{4}-\d{2}-\d{2}');
