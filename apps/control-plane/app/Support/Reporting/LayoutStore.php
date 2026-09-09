<?php

namespace App\Support\Reporting;

use App\Models\TenantMembership;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use stdClass;

/**
 * Katalog layout satu laporan untuk satu tenant: layout bawaan dari release app
 * digabung dengan layout unggahan tenant, ditambah pilihan default per legal entity.
 *
 * Padanan halaman Report Layouts dan Report Selections Business Central, disatukan
 * karena keduanya menjawab pertanyaan yang sama: "layout mana yang dipakai kalau saya
 * mencetak sekarang?"
 */
final class LayoutStore
{
    private const TENANT_SCOPE = 'tenant';

    public function __construct(
        private readonly LayoutInspector $inspector,
        private readonly SumberLaporan $client,
    ) {}

    /**
     * Semua layout yang dapat dipilih pada konteks ini: bawaan, milik seluruh tenant,
     * dan milik legal entity aktif. Layout legal entity lain tidak ikut, karena ia memang
     * dibuat untuk kop perusahaan lain.
     *
     * @return list<array<string, mixed>>
     */
    public function list(stdClass $report, string $tenantId, ?string $legalEntityId): array
    {
        $defaultRef = $this->defaultRef($report, $tenantId, $legalEntityId);
        $builtin = array_map(fn (array $layout): array => [
            'ref' => LayoutRef::BUILTIN_PREFIX.$layout['key'],
            'name' => $layout['name'],
            'description' => $layout['description'] ?? null,
            'format' => $layout['format'],
            'outputs' => LayoutFile::outputFormatsFor($layout['format']),
            'source' => 'builtin',
            'legal_entity_id' => null,
            'file_size' => null,
            'created_at' => null,
            'is_default' => LayoutRef::BUILTIN_PREFIX.$layout['key'] === $defaultRef,
        ], $report->builtin_layouts);

        $uploaded = $this->uploadedQuery($report->code, $tenantId, $legalEntityId)
            ->orderBy('name')
            ->get()
            ->map(fn (object $row): array => [
                'ref' => $row->id,
                'name' => $row->name,
                'description' => $row->description,
                'format' => $row->format,
                'outputs' => LayoutFile::outputFormatsFor($row->format),
                'source' => 'uploaded',
                'legal_entity_id' => $row->legal_entity_id,
                'file_size' => (int) $row->file_size,
                'created_at' => $row->created_at,
                'is_default' => $row->id === $defaultRef,
            ])
            ->all();

        return [...$builtin, ...$uploaded];
    }

    /**
     * Layout default konteks ini: pilihan legal entity, lalu pilihan tenant, lalu layout
     * bawaan pertama. Pilihan yang menunjuk layout yang sudah dihapus diabaikan, bukan
     * dilempar, supaya tombol cetak tidak pernah mati karena admin menghapus satu layout.
     */
    public function defaultRef(stdClass $report, string $tenantId, ?string $legalEntityId): string
    {
        $candidates = DB::table('report_layout_defaults')
            ->where(['tenant_id' => $tenantId, 'report_code' => $report->code])
            ->get()
            ->keyBy('scope_key');

        foreach (array_filter([$legalEntityId, self::TENANT_SCOPE]) as $scope) {
            $candidate = $candidates[$scope] ?? null;
            if ($candidate && $this->exists($report, $tenantId, $legalEntityId, $candidate->layout_ref)) {
                return $candidate->layout_ref;
            }
        }

        $first = $report->builtin_layouts[0] ?? null;

        return $first ? LayoutRef::BUILTIN_PREFIX.$first['key'] : '';
    }

    public function exists(stdClass $report, string $tenantId, ?string $legalEntityId, string $ref): bool
    {
        if (LayoutRef::isBuiltin($ref)) {
            return $this->builtin($report, $ref) !== null;
        }

        return Str::isUlid($ref) && $this->uploadedQuery($report->code, $tenantId, $legalEntityId)->where('id', $ref)->exists();
    }

    /**
     * Berkas layout siap dibaca renderer. Layout bawaan diambil dari app sekali per versi
     * release lalu disimpan di disk laporan, supaya worker tidak memanggil app untuk
     * berkas yang sama berulang kali. Pemanggil wajib memanggil {@see LayoutFile::cleanup()}.
     */
    public function resolve(stdClass $report, string $tenantId, ?string $legalEntityId, string $ref, TenantMembership $membership, ?string $orgUnitId): LayoutFile
    {
        $disk = $this->disk();
        if (LayoutRef::isBuiltin($ref)) {
            $layout = $this->builtin($report, $ref)
                ?? throw new RuntimeException("Layout bawaan `{$ref}` tidak ada pada laporan {$report->code}.");
            // Kunci cache memuat digest image API release yang terpasang, bukan hanya versi:
            // pada stack lokal image dibangun ulang tanpa menaikkan versi, dan layout bawaan
            // lama tidak boleh tertinggal di cache Core.
            $cached = "reporting/builtin/{$report->app_id}/{$report->app_version}-{$this->releaseKey($report)}/{$report->code}-{$layout['key']}.{$layout['format']}";
            if (! $disk->exists($cached)) {
                $disk->put($cached, $this->client->builtinLayout($report, $layout['key'], $membership, $legalEntityId, $orgUnitId));
            }

            return new LayoutFile($ref, $layout['name'], $layout['format'], $this->temporaryCopy($cached, $layout['format']), temporary: true);
        }

        $row = $this->uploadedQuery($report->code, $tenantId, $legalEntityId)->where('id', $ref)->first()
            ?? throw new RuntimeException('Layout yang dipilih sudah tidak ada.');

        return new LayoutFile($row->id, $row->name, $row->format, $this->temporaryCopy($row->file_path, $row->format), temporary: true);
    }

    /** Nama layout untuk ditampilkan pada riwayat ekspor. */
    public function name(stdClass $report, string $tenantId, ?string $legalEntityId, string $ref): string
    {
        if (LayoutRef::isBuiltin($ref)) {
            return $this->builtin($report, $ref)['name'] ?? $ref;
        }

        return (string) ($this->uploadedQuery($report->code, $tenantId, $legalEntityId)->where('id', $ref)->value('name') ?? $ref);
    }

    public function format(stdClass $report, string $tenantId, ?string $legalEntityId, string $ref): ?string
    {
        if (LayoutRef::isBuiltin($ref)) {
            return $this->builtin($report, $ref)['format'] ?? null;
        }

        return $this->uploadedQuery($report->code, $tenantId, $legalEntityId)->where('id', $ref)->value('format');
    }

    /**
     * Menyimpan layout unggahan dan melaporkan placeholder yang tidak dikenal dataset.
     *
     * @param  list<string>  $knownKeys
     * @return array{layout: array<string, mixed>, unknown_placeholders: list<string>}
     */
    public function store(stdClass $report, string $tenantId, ?string $legalEntityId, ?int $userId, UploadedFile $file, string $name, ?string $description, array $knownKeys): array
    {
        $format = $this->inspector->format($file->getRealPath(), $file->getClientOriginalName());
        $unknown = $this->inspector->unknownPlaceholders($file->getRealPath(), $format, $knownKeys);

        $id = (string) Str::ulid();
        $path = "reporting/layouts/{$tenantId}/{$id}.{$format}";
        $this->disk()->put($path, file_get_contents($file->getRealPath()));
        $row = [
            'id' => $id,
            'tenant_id' => $tenantId,
            'legal_entity_id' => $legalEntityId,
            'report_code' => $report->code,
            'name' => $name,
            'description' => $description,
            'format' => $format,
            'file_path' => $path,
            'file_size' => $file->getSize(),
            'created_by_user_id' => $userId,
            'created_at' => now(),
            'updated_at' => now(),
        ];
        DB::table('report_layouts')->insert($row);

        return ['layout' => $row, 'unknown_placeholders' => $unknown];
    }

    /**
     * @param  list<string>  $knownKeys
     * @return array{layout: object, unknown_placeholders: list<string>}
     */
    public function update(stdClass $report, string $tenantId, ?string $legalEntityId, string $id, ?string $name, ?string $description, bool $descriptionGiven, ?UploadedFile $file, array $knownKeys): array
    {
        $row = $this->uploadedQuery($report->code, $tenantId, $legalEntityId)->where('id', $id)->first();
        abort_if($row === null, 404);

        $changes = ['updated_at' => now()];
        if ($name !== null) {
            $changes['name'] = $name;
        }
        if ($descriptionGiven) {
            $changes['description'] = $description;
        }
        $unknown = [];
        if ($file !== null) {
            $format = $this->inspector->format($file->getRealPath(), $file->getClientOriginalName());
            if ($format !== $row->format) {
                throw ValidationException::withMessages(['file' => ["Layout ini berformat {$row->format}; unggah berkas {$row->format} atau buat layout baru."]]);
            }
            $unknown = $this->inspector->unknownPlaceholders($file->getRealPath(), $format, $knownKeys);
            $this->disk()->put($row->file_path, file_get_contents($file->getRealPath()));
            $changes['file_size'] = $file->getSize();
        }
        DB::table('report_layouts')->where(['tenant_id' => $tenantId, 'id' => $id])->update($changes);

        return [
            'layout' => $this->uploadedQuery($report->code, $tenantId, $legalEntityId)->where('id', $id)->first(),
            'unknown_placeholders' => $unknown,
        ];
    }

    public function delete(stdClass $report, string $tenantId, ?string $legalEntityId, string $id): void
    {
        $row = $this->uploadedQuery($report->code, $tenantId, $legalEntityId)->where('id', $id)->first();
        abort_if($row === null, 404);

        DB::transaction(function () use ($tenantId, $report, $row): void {
            DB::table('report_layouts')->where(['tenant_id' => $tenantId, 'id' => $row->id])->delete();
            DB::table('report_layout_defaults')
                ->where(['tenant_id' => $tenantId, 'report_code' => $report->code, 'layout_ref' => $row->id])
                ->delete();
        });
        $this->disk()->delete($row->file_path);
    }

    /** `ref` kosong menghapus pilihan pada lingkup itu sehingga kembali ke bawaan. */
    public function setDefault(stdClass $report, string $tenantId, ?string $contextLegalEntityId, ?string $ref, ?string $scopeLegalEntityId): void
    {
        $scopeKey = $scopeLegalEntityId ?? self::TENANT_SCOPE;
        $where = ['tenant_id' => $tenantId, 'report_code' => $report->code, 'scope_key' => $scopeKey];
        if ($ref === null || $ref === '') {
            DB::table('report_layout_defaults')->where($where)->delete();

            return;
        }
        if (! $this->exists($report, $tenantId, $contextLegalEntityId, $ref)) {
            throw ValidationException::withMessages(['layout_ref' => ['Layout yang dipilih tidak ada.']]);
        }
        DB::table('report_layout_defaults')->updateOrInsert($where, [
            'id' => (string) Str::ulid(),
            'legal_entity_id' => $scopeLegalEntityId,
            'layout_ref' => $ref,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /** Sidik jari release yang sedang melayani app: berubah setiap image API diganti. */
    private function releaseKey(stdClass $report): string
    {
        $image = DB::table('app_placements as placements')
            ->join('app_releases as releases', function ($join): void {
                $join->on('releases.app_id', '=', 'placements.app_id')
                    ->on('releases.version', '=', 'placements.release_version');
            })
            ->where('placements.app_id', $report->app_id)
            ->where('placements.runtime_status', 'ready')
            ->orderByDesc('placements.updated_at')
            ->value('releases.api_image');

        return substr(sha1((string) $image), 0, 12);
    }

    /** @return array{key:string,name:string,description:?string,format:string}|null */
    private function builtin(stdClass $report, string $ref): ?array
    {
        foreach ($report->builtin_layouts as $layout) {
            if (LayoutRef::BUILTIN_PREFIX.$layout['key'] === $ref) {
                return $layout;
            }
        }

        return null;
    }

    private function temporaryCopy(string $path, string $format): string
    {
        $temp = tempnam(sys_get_temp_dir(), 'layout-');
        if ($temp === false) {
            throw new RuntimeException('Direktori sementara tidak dapat ditulis.');
        }
        $temp .= '.'.$format;
        file_put_contents($temp, $this->disk()->get($path));

        return $temp;
    }

    private function uploadedQuery(string $reportCode, string $tenantId, ?string $legalEntityId): mixed
    {
        return DB::table('report_layouts')
            ->where(['tenant_id' => $tenantId, 'report_code' => $reportCode])
            ->where(function ($query) use ($legalEntityId): void {
                $query->whereNull('legal_entity_id');
                if ($legalEntityId !== null) {
                    $query->orWhere('legal_entity_id', $legalEntityId);
                }
            });
    }

    private function disk(): Filesystem
    {
        return Storage::disk((string) config('reporting.disk'));
    }
}
