<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Dipakai seeder, migration data, dan perintah impor tanpa dependency model App.
 * Dataset bundel berasal dari wilayah.sql Kepmendagri 300.2.2-2138/2025;
 * pembaruan sumber harus menyertakan validasi kode induk dan provenance dataset.
 *
 * @return Closure(string, bool, bool, ?string): array<string, int>
 */
return static function (string $source, bool $dryRun = false, bool $force = true, ?string $onlyLevel = null): array {
    // Impor ini hanya dipanggil oleh migration/seeder/CLI, bukan permintaan web.
    ini_set('memory_limit', '512M');
    $datasets = [];
    foreach (['province', 'regency', 'district', 'village'] as $level) {
        $file = $source.'/indonesia_'.($level === 'regency' ? 'regencies' : $level.'s').'.json';
        if (! is_file($file)) {
            throw new RuntimeException('Berkas dataset wilayah tidak ditemukan: '.$file);
        }
        $datasets[$level] = json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
    }
    if (! DB::table('country_regions')->where('code', 'ID')->exists()) {
        throw new RuntimeException('Daftar negara belum memuat Indonesia. Jalankan migration data negara terlebih dahulu.');
    }
    $postalCodes = [];
    $postalFile = $source.'/wilayah_kodepos.sql';
    if (is_file($postalFile)) {
        // Baca pasangan data, jangan mengeksekusi SQL dari dataset eksternal.
        preg_match_all("/\('([0-9.]+)',\s*'([0-9]{5})'\)/", (string) file_get_contents($postalFile), $matches, PREG_SET_ORDER);
        foreach ($matches as $match) {
            $postalCodes[str_replace('.', '', $match[1])] = $match[2];
        }
        unset($matches);
    }

    return DB::transaction(static function () use ($datasets, $postalCodes, $dryRun, $force, $onlyLevel): array {
        // Semua pembaca penulis wilayah memakai ID dari tabel ref_* terstruktur.
        // Jangan mengganti PK atau memaksa ID registry generik ke baris yang sudah dipakai alamat.
        $maps = ['province' => [], 'regency' => [], 'district' => [], 'village' => []];
        $stats = ['new' => 0, 'updated' => 0, 'unchanged' => 0];
        $now = now();
        foreach ([
            ['province', 'ref_provinces', 'country_code', null, 2],
            ['regency', 'ref_regencies', 'province_id', 'province', 4],
            ['district', 'ref_districts', 'regency_id', 'regency', 6],
            ['village', 'ref_villages', 'district_id', 'district', 10],
        ] as [$level, $table, $parentField, $parentLevel, $digits]) {
            if ($onlyLevel !== null && isset($stats[$onlyLevel])) {
                break;
            }
            $query = DB::table($table)->whereNull('tenant_id');
            if ($parentLevel === null) {
                $query->where('country_code', 'ID');
            } else {
                $query->whereIn($parentField, array_values($maps[$parentLevel]));
            }
            $existing = [];
            foreach ($query->get() as $row) {
                $code = str_replace('.', '', $row->code);
                if (isset($existing[$code])) {
                    throw new RuntimeException('Kode wilayah ganda pada '.$table.': '.$code);
                }
                $existing[$code] = $row;
            }
            $maps[$level] = [];
            $batch = [];
            $seen = [];
            foreach ($datasets[$level] as $sourceCode => $value) {
                $code = str_replace('.', '', $level === 'village' ? $value['code'] : (string) $sourceCode);
                $name = $level === 'village' ? $value['name'] : $value;
                if (! preg_match('/^[0-9]{'.$digits.'}$/D', $code) || ! is_string($name) || trim($name) === '' || isset($seen[$code])) {
                    throw new RuntimeException('Kode atau nama wilayah tidak valid: '.$level.' '.$code);
                }
                $seen[$code] = true;
                $parent = $parentLevel === null ? 'ID' : ($maps[$parentLevel][substr($code, 0, $digits === 10 ? 6 : $digits - 2)] ?? null);
                if ($parent === null) {
                    throw new RuntimeException('Wilayah tidak memiliki induk yang sesuai: '.$code);
                }
                $old = $existing[$code] ?? null;
                $id = $old->id ?? (string) Str::ulid();
                if ($onlyLevel !== null && $onlyLevel !== $level && $old === null) {
                    throw new RuntimeException('Impor induk wilayah terlebih dahulu sebelum memilih satu level.');
                }
                $maps[$level][$code] = $id;
                if ($onlyLevel !== null && $onlyLevel !== $level) {
                    continue;
                }
                if ($old !== null && (! $force || ($old->name === $name && $old->{$parentField} === $parent && $old->code === $code
                    && ($level !== 'village' || ($old->type === $value['type'] && (! isset($postalCodes[$code]) || $old->postal_code === $postalCodes[$code])))))) {
                    $stats['unchanged']++;

                    continue;
                }
                $stats[$old === null ? 'new' : 'updated']++;
                $row = ['id' => $id, 'tenant_id' => null, 'code' => $code, 'name' => $name, $parentField => $parent, 'active' => $old->active ?? true, 'created_at' => $old->created_at ?? $now, 'updated_at' => $now];
                if ($level === 'village') {
                    $row['type'] = $value['type'];
                    $row['postal_code'] = $postalCodes[$code] ?? $old?->postal_code;
                }
                $batch[] = $row;
            }
            if (! $dryRun) {
                foreach (array_chunk($batch, 500) as $chunk) {
                    DB::table($table)->upsert($chunk, ['id'], ['code', 'name', $parentField, 'updated_at', ...($level === 'village' ? ['type', 'postal_code'] : [])]);
                }
            }
            $stats[$level] = count($maps[$level]);
        }

        if ($postalCodes !== [] && ($onlyLevel === null || $onlyLevel === 'village')) {
            $existingPostal = DB::table('ref_postal_codes')->whereNull('tenant_id')->where('country_code', 'ID')->get()->keyBy(fn ($row) => $row->village_id.'|'.$row->postal_code);
            $batch = [];
            $count = 0;
            foreach ($maps['village'] as $code => $villageId) {
                $postal = $postalCodes[$code] ?? null;
                if ($postal === null) {
                    continue;
                }
                $count++;
                $old = $existingPostal[$villageId.'|'.$postal] ?? null;
                $provinceId = $maps['province'][substr((string) $code, 0, 2)];
                $regencyId = $maps['regency'][substr((string) $code, 0, 4)];
                $districtId = $maps['district'][substr((string) $code, 0, 6)];
                if ($old !== null && $old->province_id === $provinceId && $old->regency_id === $regencyId && $old->district_id === $districtId) {
                    continue;
                }
                $batch[] = ['id' => $old->id ?? (string) Str::ulid(), 'tenant_id' => null, 'country_code' => 'ID', 'postal_code' => $postal,
                    'province_id' => $provinceId, 'regency_id' => $regencyId, 'district_id' => $districtId, 'village_id' => $villageId,
                    'source' => 'cahyadsn/wilayah_kodepos', 'active' => $old->active ?? true, 'created_at' => $old->created_at ?? $now, 'updated_at' => $now];
            }
            if (! $dryRun) {
                foreach (array_chunk($batch, 500) as $chunk) {
                    DB::table('ref_postal_codes')->upsert($chunk, ['id'], ['province_id', 'regency_id', 'district_id', 'updated_at']);
                }
            }
            $stats['postal_code'] = $count;
        }

        return $stats;
    });
};
