<?php

declare(strict_types=1);

namespace App\Platform\Analytics\Query;

use App\Platform\Analytics\Datasets\CompiledDataset;
use DateTimeZone;
use Illuminate\Contracts\Database\Query\Expression;
use Illuminate\Database\Grammar;
use LogicException;

/**
 * Ember waktu satu field waktu menurut zona pengguna: tanggal awal hari, minggu (mulai Senin), bulan,
 * kuartal, atau tahun tempat nilai itu jatuh. Hasilnya selalu `date`, jadi periode dikirim sebagai
 * tanggal awal ember (`2026-10-01`) dan layar yang menulis "Okt 2026".
 *
 * SQL-nya berbeda untuk tiga jenis kolom waktu di repo ini:
 *
 * - `date` tidak dikonversi: tanggal perolehan adalah tanggal kalender, bukan saat.
 * - `timestamp` (tanpa zona) berisi waktu UTC — bawaan `$table->timestamps()` dengan zona aplikasi UTC —
 *   jadi dibaca sebagai UTC dulu, lalu dipindah ke zona pengguna.
 * - `timestamptz` langsung dipindah ke zona pengguna. Bukan `to_char()`, yang memakai zona sesi (UTC):
 *   awal Oktober di Makassar adalah 30 September 16.00 UTC, dan `to_char` menulisnya September.
 *
 * Zona ditulis sebagai literal, bukan binding: ekspresi yang sama dapat muncul dua kali (kolom hasil dan
 * kunci urutan kosong-di-akhir), dan dua binding menjadi dua parameter berbeda bagi PostgreSQL, yang lalu
 * tidak mengenalinya sebagai ekspresi yang dikelompokkan. Karena literal, zonanya wajib salah satu nama di
 * `DateTimeZone::listIdentifiers()` — bukan isian bebas.
 * `date_trunc('week', …)` PostgreSQL memakai minggu ISO, yang mulai Senin.
 *
 * `$shift` (area 13) menggeser saat itu maju sebelum diember, misalnya `12 months`, untuk query pembanding
 * perbandingan periode: baris tahun lalu jatuh di ember tahun ini, sehingga dapat digabung menurut nilai ember
 * yang sama. Pergeseran bulan yang jatuh di tanggal yang tidak ada berhenti di akhir bulan (aturan interval
 * PostgreSQL). Seperti zona, ia literal yang bentuknya diperiksa, bukan binding.
 */
final readonly class TimeBucketExpression implements Expression
{
    /**
     * @param  string  $column  berkualifikasi
     * @param  'date'|'timestamp'|'timestamptz'  $type  dari {@see CompiledDataset::timeType()}
     */
    public function __construct(
        private TimeGranularity $granularity,
        private string $column,
        private string $type,
        private string $timezone,
        private ?string $shift = null,
    ) {
        if (! in_array($timezone, DateTimeZone::listIdentifiers(), true)) {
            throw new LogicException("Zona waktu `{$timezone}` bukan nama zona yang dikenal.");
        }
        if ($shift !== null && preg_match('/^[1-9]\d{0,3} (months|days)$/', $shift) !== 1) {
            throw new LogicException("Pergeseran `{$shift}` bukan jumlah bulan atau hari.");
        }
    }

    public function getValue(Grammar $grammar): string
    {
        $column = $grammar->wrap($this->column);
        $zone = "'{$this->timezone}'";

        $moment = match ($this->type) {
            'date' => "{$column}::timestamp",
            'timestamp' => "({$column} at time zone 'UTC') at time zone {$zone}",
            'timestamptz' => "{$column} at time zone {$zone}",
        };
        if ($this->shift !== null) {
            $moment = "({$moment} + interval '{$this->shift}')";
        }

        return "date_trunc('{$this->granularity->value}', {$moment})::date";
    }
}
