<?php

declare(strict_types=1);

namespace Modules\Apperp\ManagementAset\Reporting;

use App\Support\Modules\Contracts\PenyediaLaporanModul;
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
 * Core; penerjemahannya dikerjakan `SumberLaporan` di sisi pemanggil.
 */
final class PenyediaLaporan implements PenyediaLaporanModul
{
    public function __construct(private readonly ReportRegistry $registry) {}

    public function idModule(): string
    {
        return 'management-aset';
    }

    public function punya(string $kodeLaporan): bool
    {
        return $this->registry->has($kodeLaporan);
    }

    /**
     * @param  array<string, mixed>  $konteks
     * @return array{fields: list<array{key: string, label: string, table: ?string}>, parameters: list<string>}
     */
    public function definisi(string $kodeLaporan, array $konteks): array
    {
        $definition = $this->terizinkan($kodeLaporan, $konteks);

        return [
            'fields' => $definition->fields(),
            'parameters' => array_map('strval', array_keys($definition->parameterRules())),
        ];
    }

    /** @param array<string, mixed> $konteks */
    public function layoutBawaan(string $kodeLaporan, string $kunci, array $konteks): string
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
            $tervalidasi = validator($parameter, $definition->parameterRules())->validate();
        } catch (ValidationException $exception) {
            throw new RuntimeException(
                'Parameter laporan tidak diterima: '.implode(' ', $exception->validator->errors()->all()),
                previous: $exception,
            );
        }

        try {
            $data = $definition->data(ReportContext::fromArray($konteks), $tervalidasi);
        } catch (ReportDataException $exception) {
            // Data di luar scope atau tidak ada. Pesannya sama persis dengan yang dilihat
            // pengguna di layar, dan Core meneruskannya apa adanya ke baris ekspor.
            throw new RuntimeException($exception->getMessage(), previous: $exception);
        }

        return [
            'fields' => $data->fields,
            'tables' => $data->tables,
            'file_name' => $data->fileName,
        ];
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
            throw new RuntimeException('Anda tidak berhak membaca data laporan ini.');
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
