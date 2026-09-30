<?php

declare(strict_types=1);

namespace Modules\Apperp\ManagementAset\Reporting\Lists;

use App\Support\Modules\Contracts\ListExportSource;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\JoinClause;
use Modules\Apperp\ManagementAset\Models\transaksi\InventarisasiAset\Aset;
use Modules\Apperp\ManagementAset\Reporting\ReportAccessDeniedException;
use Modules\Apperp\ManagementAset\Reporting\ReportContext;
use Modules\Apperp\ManagementAset\Support\OrganizationScope;
use Modules\Apperp\ManagementAset\Support\StatusAset;

/**
 * Register aset seperti yang tampil di layar Inventarisasi aset, untuk ekspor daftar lewat antrean Core
 * (K-27). Pilot mekanisme ekspor daftar: kolom, filter, dan urutannya sama dengan `AsetListPage`.
 *
 * Hak dan cakupannya sama dengan `GET /aset`: permission `management-aset.aset.read`, lalu kebijakan data
 * `management-aset.asset-responsibility` lewat {@see OrganizationScope}. Baris dibaca per seribu supaya
 * memori worker tetap datar berapa pun jumlah asetnya.
 *
 * Nilai kolom sama dengan yang dibaca orang di layar: nama group, jenis, dan lokasi, bukan id-nya; label
 * status, bukan kodenya. Nilai perolehan dikirim sebagai angka bertipe uang supaya dapat dijumlah di Excel.
 */
final class AssetRegisterList implements ListExportSource
{
    private const PERMISSION = 'management-aset.aset.read';

    private const CHUNK = 1000;

    public function moduleId(): string
    {
        return 'management-aset';
    }

    public function listCode(): string
    {
        return 'aset';
    }

    public function name(): string
    {
        return 'Register aset';
    }

    public function permission(): string
    {
        return self::PERMISSION;
    }

    public function columns(): array
    {
        return [
            ['key' => 'kode', 'label' => 'Kode aset'],
            ['key' => 'nama', 'label' => 'Nama aset'],
            ['key' => 'serial', 'label' => 'Nomor seri'],
            ['key' => 'group', 'label' => 'Group aset'],
            ['key' => 'jenis', 'label' => 'Jenis aset'],
            ['key' => 'lokasi', 'label' => 'Lokasi'],
            ['key' => 'nilai', 'label' => 'Nilai perolehan', 'type' => 'money'],
            ['key' => 'status', 'label' => 'Status'],
        ];
    }

    public function filterRules(): array
    {
        // Sama dengan `GET /aset`: pencarian kode, nama, atau nomor seri.
        return ['q' => ['nullable', 'string', 'max:100']];
    }

    public function count(array $context, array $filters): int
    {
        return $this->query(ReportContext::fromArray($context), $filters)->count();
    }

    public function rows(array $context, array $filters, ?array $sort): iterable
    {
        $query = $this->query(ReportContext::fromArray($context), $filters)
            ->leftJoin('aset_m_group_aset as grp', fn (JoinClause $join) => $join->on('grp.id', '=', 'aset_tr_aset.group_aset_id')->on('grp.tenant_id', '=', 'aset_tr_aset.tenant_id'))
            ->leftJoin('aset_m_jenis_aset as jenis', fn (JoinClause $join) => $join->on('jenis.id', '=', 'aset_tr_aset.jenis_aset_id')->on('jenis.tenant_id', '=', 'aset_tr_aset.tenant_id'))
            ->leftJoin('aset_m_lokasi_aset as lokasi', fn (JoinClause $join) => $join->on('lokasi.id', '=', 'aset_tr_aset.lokasi_aset_id')->on('lokasi.tenant_id', '=', 'aset_tr_aset.tenant_id'))
            ->select([
                'aset_tr_aset.id', 'aset_tr_aset.kode', 'aset_tr_aset.nama', 'aset_tr_aset.serial_number',
                'aset_tr_aset.acquisition_value', 'aset_tr_aset.lifecycle_state',
                'grp.nama as group_nama', 'jenis.nama as jenis_nama', 'lokasi.nama as lokasi_nama',
            ]);

        $this->order($query, $sort);

        // Per seribu baris dengan urutan yang tetap (id sebagai penentu terakhir), jadi memori datar dan tidak
        // ada baris yang terlewat atau terulang di antara dua potongan.
        foreach ($query->toBase()->lazy(self::CHUNK) as $row) {
            yield [
                'kode' => (string) $row->kode,
                'nama' => (string) $row->nama,
                'serial' => $row->serial_number !== null ? (string) $row->serial_number : null,
                'group' => $row->group_nama !== null ? (string) $row->group_nama : null,
                'jenis' => $row->jenis_nama !== null ? (string) $row->jenis_nama : null,
                'lokasi' => $row->lokasi_nama !== null ? (string) $row->lokasi_nama : null,
                'nilai' => $row->acquisition_value !== null ? (string) $row->acquisition_value : null,
                'status' => StatusAset::LABELS[(string) $row->lifecycle_state] ?? (string) $row->lifecycle_state,
            ];
        }
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return Builder<Aset>
     */
    private function query(ReportContext $context, array $filters): Builder
    {
        if (! $context->can(self::PERMISSION)) {
            throw new ReportAccessDeniedException('Anda tidak berhak melihat register aset.');
        }

        /** @var Builder<Aset> $query */
        $query = app(OrganizationScope::class)->asetQuery(Aset::query(), $context->request());
        $q = trim(is_string($filters['q'] ?? null) ? $filters['q'] : '');
        if ($q !== '') {
            $like = '%'.mb_strtolower($q).'%';
            $query->where(fn (Builder $builder) => $builder
                ->whereRaw('LOWER(aset_tr_aset.kode) LIKE ?', [$like])
                ->orWhereRaw('LOWER(aset_tr_aset.nama) LIKE ?', [$like])
                ->orWhereRaw('LOWER(aset_tr_aset.serial_number) LIKE ?', [$like]));
        }

        return $query;
    }

    /**
     * Urutan yang sama dengan layar: teks tanpa membedakan huruf besar dan kosong di depan, nilai sebagai
     * angka, status menurut labelnya. Tanpa urutan pilihan, yang terbaru di atas seperti `GET /aset`.
     *
     * @param  Builder<Aset>  $query
     * @param  array{column: string, direction: string}|null  $sort
     */
    private function order(Builder $query, ?array $sort): void
    {
        $direction = ($sort['direction'] ?? 'asc') === 'desc' ? 'desc' : 'asc';

        match ($sort['column'] ?? null) {
            'kode' => $query->orderByRaw('LOWER(aset_tr_aset.kode) '.$direction),
            'nama' => $query->orderByRaw('LOWER(aset_tr_aset.nama) '.$direction),
            'serial' => $query->orderByRaw("LOWER(COALESCE(aset_tr_aset.serial_number, '')) ".$direction),
            'group' => $query->orderByRaw("LOWER(COALESCE(grp.nama, '')) ".$direction),
            'jenis' => $query->orderByRaw("LOWER(COALESCE(jenis.nama, '')) ".$direction),
            'lokasi' => $query->orderByRaw("LOWER(COALESCE(lokasi.nama, '')) ".$direction),
            'nilai' => $query->orderBy('aset_tr_aset.acquisition_value', $direction),
            // Urutan kode status menurut labelnya; satu larik PostgreSQL, bukan CASE yang disusun dari teks.
            'status' => $query->orderByRaw('array_position(?::text[], aset_tr_aset.lifecycle_state) '.$direction, [$this->statusOrder()]),
            default => $query->orderByDesc('aset_tr_aset.created_at'),
        };
        $query->orderBy('aset_tr_aset.id');
    }

    /** Kode status diurutkan menurut labelnya, dalam bentuk larik PostgreSQL: `{decommissioned,received,...}`. */
    private function statusOrder(): string
    {
        $labels = StatusAset::LABELS;
        uasort($labels, strcasecmp(...));

        return '{'.implode(',', array_keys($labels)).'}';
    }
}
