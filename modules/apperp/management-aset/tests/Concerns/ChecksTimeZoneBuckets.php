<?php

declare(strict_types=1);

namespace Modules\Apperp\ManagementAset\Tests\Concerns;

use Illuminate\Support\Facades\DB;

/**
 * Pengelompokan waktu mengikuti zona pengguna, bukan zona server: satu baris di batas bulan jatuh di bulan
 * yang berbeda menurut zona yang dipakai (`docs/todo/analitik/mesin-query.md`, *Waktu dan zona*). Dipakai
 * test dataset yang punya field waktu, bersama {@see ProbesAssetDatasets}.
 *
 * Dua jenis kolom waktu ada di module ini dan keduanya diperiksa: kolom `date` adalah tanggal kalender dan
 * tidak pernah bergeser, sedangkan kolom `timestamp` (tanpa zona) berisi UTC. 30 September 16.30 UTC masih
 * September bagi WIB dan UTC (23.30 dan 16.30), tetapi sudah 1 Oktober 00.30 bagi WITA dan 01.30 bagi WIT.
 *
 * Zona pengguna di sini zona entitas legalnya, yang diganti di tengah test.
 */
trait ChecksTimeZoneBuckets
{
    /** Kunci field waktu yang dikelompokkan. */
    abstract protected function timeField(): string;

    /**
     * Jenis kolom field waktu itu di tabelnya: `date` atau `timestamp`.
     *
     * @return 'date'|'timestamp'
     */
    abstract protected function timeKind(): string;

    /**
     * Menyisipkan satu baris di tenant A dengan nilai ini pada field waktunya (tanggal `2026-09-30`, atau
     * waktu UTC `2026-09-30 16:30:00`), lalu memulangkan saringan yang hanya menjangkau baris itu.
     *
     * @return array<string, string|list<string>>
     */
    abstract protected function insertBoundaryRow(string $value): array;

    public function test_month_and_day_buckets_follow_the_users_zone(): void
    {
        $date = $this->timeKind() === 'date';
        $filters = $this->insertBoundaryRow($date ? '2026-09-30' : '2026-09-30 16:30:00');
        $field = $this->timeField();

        $bucket = function (string $zone, string $granularity) use ($field, $filters): mixed {
            DB::table('legal_entities')->where('organization_id', $this->legalEntity)->update(['timezone' => $zone]);

            return $this->analyze($this->owner, [
                'dataset' => $this->datasetCode(), 'dimensions' => [['field' => $field, 'granularity' => $granularity]],
                'measures' => ['count'], 'filters' => $filters,
            ])->assertOk()->assertJsonPath('meta.timezone', $zone)->assertJsonPath('rows.0.count', 1)->json("rows.0.{$field}");
        };

        // Tanggal kalender tidak bergeser menurut zona; waktu UTC bergeser.
        $this->assertSame('2026-09-01', $bucket('UTC', 'month'));
        $this->assertSame('2026-09-01', $bucket('Asia/Jakarta', 'month'));
        $this->assertSame($date ? '2026-09-01' : '2026-10-01', $bucket('Asia/Makassar', 'month'));
        $this->assertSame($date ? '2026-09-01' : '2026-10-01', $bucket('Asia/Jayapura', 'month'));
        $this->assertSame('2026-09-30', $bucket('Asia/Jakarta', 'day'));
        $this->assertSame($date ? '2026-09-30' : '2026-10-01', $bucket('Asia/Makassar', 'day'));
    }
}
