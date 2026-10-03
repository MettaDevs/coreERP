<?php

declare(strict_types=1);

namespace App\Platform\Analytics\Query;

use App\Platform\Analytics\Datasets\CompiledDataset;
use App\Platform\Analytics\Security\AnalyticsPrincipal;

/**
 * Memeriksa query terhadap dataset dan principal sebelum ada SQL apa pun: setiap kunci dikenal dataset,
 * tidak ada kunci yang dipilih dua kali, dan `limit` dalam batas principal.
 *
 * Saringan pada kolom yang tidak dikenal **ditolak, bukan diabaikan**: saringan yang diabaikan diam-diam
 * memulangkan angka yang lebih besar dari yang diminta pengguna.
 *
 * Isi kerangka berjalan (area 0). Area 2 menambah batas jumlah dari `config/analytics.php`, aturan
 * ember waktu, dan urutan; gerbang data pribadi area 4 dipanggil dari sini.
 */
final class QueryValidator
{
    /** @throws AnalyticsQueryException */
    public function validate(CompiledDataset $dataset, AnalyticsQuery $query, AnalyticsPrincipal $principal): void
    {
        $chosen = [];

        foreach ($query->dimensions as $i => $dimension) {
            if (! $dataset->hasField($dimension->field)) {
                throw AnalyticsQueryException::fieldUnknown("dimensions.{$i}", $dimension->field);
            }
            if (isset($chosen[$dimension->field])) {
                throw AnalyticsQueryException::invalidQuery("dimensions.{$i}", 'Kolom "'.$dimension->field.'" dipilih lebih dari sekali.');
            }
            $chosen[$dimension->field] = true;
        }

        foreach ($query->measures as $i => $measure) {
            if (! $dataset->hasMeasure($measure)) {
                throw AnalyticsQueryException::measureUnknown("measures.{$i}", $measure);
            }
            // Hasil memakai kunci dataset sebagai nama kolom, jadi satu kunci hanya boleh muncul sekali
            // di antara pengelompok dan nilai.
            if (isset($chosen[$measure])) {
                throw AnalyticsQueryException::invalidQuery("measures.{$i}", 'Nilai "'.$measure.'" dipilih lebih dari sekali.');
            }
            $chosen[$measure] = true;
        }

        foreach (array_keys($query->filters) as $key) {
            if (! $dataset->hasField($key)) {
                throw AnalyticsQueryException::fieldUnknown("filters.{$key}", $key);
            }
        }

        if ($query->limit !== null && $query->limit > $principal->rowLimit()) {
            throw AnalyticsQueryException::limitExceeded('limit', 'Batas baris paling banyak '.$principal->rowLimit().'.');
        }
    }
}
