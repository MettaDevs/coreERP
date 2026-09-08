<?php

namespace App\Http\Controllers\master;

use App\Http\Controllers\MasterDataController;
use App\Models\master\TingkatLayanan;
use App\Models\MasterData;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

class TingkatLayananController extends MasterDataController
{
    protected function resource(): string
    {
        return 'tingkat-layanan';
    }

    protected function model(): string
    {
        return TingkatLayanan::class;
    }

    protected function extraRules(string $tenantId, bool $creating): array
    {
        return ['urutan' => ['sometimes', 'integer', 'min:0', 'max:9999']];
    }

    protected function extraPayload(array $data): array
    {
        return array_key_exists('urutan', $data) ? ['urutan' => (int) $data['urutan']] : [];
    }

    protected function extraPresent(MasterData $record): array
    {
        return ['urutan' => (int) $record->urutan];
    }

    /**
     * Daftar diurutkan menurut urgensi, bukan kode. Tingkat layanan dibaca sebagai skala
     * dan urutan kode tidak selalu mengikuti urgensinya; `kode` tetap menjadi pemecah seri
     * agar hasilnya stabil ketika dua tingkat memakai urutan yang sama.
     */
    protected function prepareQuery(Builder $query, ?Request $request = null): Builder
    {
        return $query->orderBy('urutan');
    }
}
