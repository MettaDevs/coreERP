<?php

declare(strict_types=1);

namespace Modules\Apperp\ManagementAset\Reporting;

use App\Platform\Modules\Contracts\ModuleReportProvider;
use Illuminate\Validation\ValidationException;
use Modules\Apperp\ManagementAset\Reporting\Layouts\BuiltinLayout;
use RuntimeException;

/**
 * Laporan module ini seperti yang dibaca mesin laporan Core, di dalam proses yang sama.
 *
 * Ini pengganti `LaporanInternalController`. Isinya nyaris sama — dan itu memang yang
 * diharapkan: yang berubah bukan aturannya melainkan siapa yang memanggilnya. Dulu Core
 * mengirim permintaan HTTP bertoken ke alamat module dan module membongkar token itu
 * kembali menjadi konteks; sekarang konteksnya datang sebagai argumen.
 *
 * Pemeriksaan izin tetap ada dan bukan sisa dari cara lama. Core memeriksa "pengguna ini
 * boleh menjalankan laporan ini"; yang diperiksa di sini adalah "pengguna ini boleh membaca
 * data yang dilaporkan" — permission bisnis yang sama dengan yang menjaga layarnya. Orang
 * yang tidak boleh membuka work order tetap tidak dapat mencetaknya.
 *
 * Kegagalan dilempar sebagai `RuntimeException` dengan pesan siap-baca. Module tidak boleh
 * menyebut kelas Core di luar kontrak, jadi ia tidak bisa melempar kegagalan laporan milik
 * Core; penerjemahannya dikerjakan `ReportSource` di sisi pemanggil.
 */
final class PenyediaLaporan implements ModuleReportProvider
{
    public function __construct(private readonly ReportRegistry $registry) {}

    public function moduleId(): string
    {
        return 'management-aset';
    }

    public function has(string $kodeLaporan): bool
    {
        return $this->registry->has($kodeLaporan);
    }

    public function catalog(): array
    {
        return array_map(fn (ReportDefinition $definition): array => [
            'code' => $this->moduleId().'.'.$definition->code(),
            'name' => $definition->name(),
            'description' => $definition->description(),
            'permission' => $definition->permission(),
            // Sama dengan yang dipulangkan `definition()`: nama parameter adalah kunci aturannya.
            'parameters' => $this->parameterNames($definition),
            'builtin_layouts' => array_map(static fn (BuiltinLayout $layout): array => [
                'key' => $layout->key,
                'name' => $layout->name,
                'description' => $layout->description,
                'format' => $layout->format,
            ], $definition->builtinLayouts()),
        ], $this->registry->all());
    }

    /**
     * @param  array<string, mixed>  $konteks
     * @return array{fields: list<array{key: string, label: string, table: ?string, type?: string}>, parameters: list<string>, data_items: list<array{key: string, caption: string, default_fields: list<string>, fields: list<array{key: string, caption: string, type: string, options?: list<array{value: string, label: string}>, lookup?: string}>}>}
     */
    public function definition(string $kodeLaporan, array $konteks): array
    {
        $definition = $this->terizinkan($kodeLaporan, $konteks);

        $items = $definition->dataItems();

        return [
            'fields' => $items === [] ? $definition->fields() : [
                ...$definition->fields(),
                ['key' => AdditionalFilters::HEADER_FIELD, 'label' => 'Filter tambahan', 'table' => null],
            ],
            'parameters' => $this->parameterNames($definition),
            'data_items' => AdditionalFilters::catalog($items),
        ];
    }

    /** @param array<string, mixed> $konteks */
    public function defaultLayout(string $kodeLaporan, string $kunci, array $konteks): string
    {
        $definition = $this->terizinkan($kodeLaporan, $konteks);

        foreach ($definition->builtinLayouts() as $layout) {
            if ($layout->key !== $kunci) {
                continue;
            }

            return $this->isiBerkas($definition, $layout);
        }

        throw new RuntimeException("Layout bawaan `{$kunci}` tidak dikenal laporan `{$kodeLaporan}`.");
    }

    /**
     * @param  array<string, mixed>  $konteks
     * @param  array<string, mixed>  $parameter
     * @return array{fields: array<string, mixed>, tables: array<string, mixed>, file_name: string}
     */
    public function dataset(string $kodeLaporan, array $konteks, array $parameter): array
    {
        $definition = $this->terizinkan($kodeLaporan, $konteks);

        try {
            /** @var array<string, mixed> $tervalidasi */
            $tervalidasi = validator($this->asLists($parameter, $definition), $this->rules($definition))->validate();
        } catch (ValidationException $exception) {
            throw new RuntimeException(
                'Parameter laporan tidak diterima: '.implode(' ', $exception->validator->errors()->all()),
                previous: $exception,
            );
        }

        $context = ReportContext::fromArray($konteks);
        $items = $definition->dataItems();
        // Kolom yang tidak dikenal ditolak sebelum data dibaca; ekspresi yang salah ditolak saat diterapkan.
        // Keduanya `RuntimeException` dengan pesan siap-baca, seperti parameter yang tidak diterima.
        AdditionalFilters::assertKnown($items, $tervalidasi);

        try {
            $data = $definition->data($context, $tervalidasi);
        } catch (ReportDataException $exception) {
            // Data di luar scope atau tidak ada. Pesannya sama persis dengan yang dilihat
            // pengguna di layar, dan Core meneruskannya apa adanya ke baris ekspor.
            throw new RuntimeException($exception->getMessage(), previous: $exception);
        }

        return [
            'fields' => $items === [] ? $data->fields : [
                ...$data->fields,
                AdditionalFilters::HEADER_FIELD => AdditionalFilters::describe($items, $tervalidasi, $context),
            ],
            'tables' => $data->tables,
            'file_name' => $data->fileName,
        ];
    }

    /**
     * Nama parameter laporan: kunci aturannya, tanpa aturan per butir daftar (`group_aset_id.*`).
     *
     * @return list<string>
     */
    private function parameterNames(ReportDefinition $definition): array
    {
        return array_values(array_filter(
            array_map('strval', array_keys($this->rules($definition))),
            static fn (string $name): bool => ! str_contains($name, '.'),
        ));
    }

    /**
     * Aturan parameter laporan, ditambah `filters` untuk laporan yang punya data item.
     *
     * @return array<string, list<mixed>>
     */
    private function rules(ReportDefinition $definition): array
    {
        return $definition->dataItems() === []
            ? $definition->parameterRules()
            : [...$definition->parameterRules(), ...AdditionalFilters::rules()];
    }

    /**
     * Filter pilihan banyak menerima satu nilai juga. Opsi yang tersimpan sebelum filternya menjadi daftar,
     * dan tautan lama yang menulis `?group_aset_id=...`, tetap berlaku sebagai daftar berisi satu nilai.
     *
     * @param  array<string, mixed>  $parameter
     * @return array<string, mixed>
     */
    private function asLists(array $parameter, ReportDefinition $definition): array
    {
        foreach ($definition->parameterRules() as $name => $rules) {
            if (! in_array('array', $rules, true) || ! is_string($parameter[$name] ?? null)) {
                continue;
            }
            if ($parameter[$name] === '') {
                unset($parameter[$name]);
            } else {
                $parameter[$name] = [$parameter[$name]];
            }
        }

        return $parameter;
    }

    /** @param array<string, mixed> $konteks */
    private function terizinkan(string $kodeLaporan, array $konteks): ReportDefinition
    {
        if (! $this->registry->has($kodeLaporan)) {
            throw new RuntimeException("Laporan `{$kodeLaporan}` tidak dikenal module Management Aset.");
        }

        $definition = $this->registry->get($kodeLaporan);
        $izin = is_array($konteks['permissions'] ?? null) ? $konteks['permissions'] : [];

        if (! in_array($definition->permission(), $izin, true)) {
            throw new ReportAccessDeniedException('Anda tidak berhak membaca data laporan ini.');
        }

        return $definition;
    }

    private function isiBerkas(ReportDefinition $definition, BuiltinLayout $layout): string
    {
        $jalur = $layout->path($definition->code());

        if (! is_file($jalur)) {
            throw new RuntimeException("Berkas layout bawaan `{$layout->key}` tidak ada pada module ini.");
        }

        $isi = file_get_contents($jalur);

        if ($isi === false) {
            throw new RuntimeException("Berkas layout bawaan `{$layout->key}` tidak dapat dibaca.");
        }

        return $isi;
    }
}
