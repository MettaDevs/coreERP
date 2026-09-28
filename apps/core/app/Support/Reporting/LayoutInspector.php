<?php

namespace App\Support\Reporting;

use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpWord\TemplateProcessor;
use Throwable;
use ZipArchive;

/**
 * Memeriksa berkas layout unggahan sebelum disimpan.
 *
 * Tenant mengunggah berkas Office yang kemudian dibuka engine render bersama, jadi
 * yang diperiksa bukan hanya ekstensi: isi zip harus benar-benar dokumen Word/Excel,
 * dan dokumen bermakro ditolak karena tidak ada alasan sebuah layout menjalankan kode.
 */
final class LayoutInspector
{
    private const MACRO_PATTERN = '/\$\{([A-Za-z0-9_.]+)\}/';

    /** Mengembalikan `docx` atau `xlsx`; melempar ValidationException bila berkas tidak layak. */
    public function format(string $path, string $originalName): string
    {
        $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        if (! in_array($extension, ['docx', 'xlsx'], true)) {
            $this->reject('Layout harus berkas Word (.docx) atau Excel (.xlsx).');
        }

        $zip = new ZipArchive;
        if ($zip->open($path) !== true) {
            $this->reject('Berkas layout tidak dapat dibuka. Simpan ulang dari Word atau Excel lalu unggah lagi.');
        }
        try {
            $marker = $extension === 'docx' ? 'word/document.xml' : 'xl/workbook.xml';
            if ($zip->locateName($marker) === false) {
                $this->reject('Berkas ini bukan dokumen '.($extension === 'docx' ? 'Word' : 'Excel').' yang sah.');
            }
            for ($i = 0; $i < $zip->numFiles; $i++) {
                if (str_ends_with(strtolower((string) $zip->getNameIndex($i)), 'vbaproject.bin')) {
                    $this->reject('Layout bermakro tidak diterima. Simpan sebagai .'.$extension.' biasa tanpa makro.');
                }
            }
        } finally {
            $zip->close();
        }

        return $extension;
    }

    /**
     * Placeholder pada layout yang tidak ada di dataset. Bukan penolakan — layout tetap
     * disimpan — tetapi dilaporkan supaya salah ketik ketahuan sebelum dokumen dicetak
     * kosong di bagian itu.
     *
     * Satu keadaan tetap ditolak: berkas yang sah sebagai zip Office tetapi isinya tidak
     * terbaca. Daftar kosong yang lahir dari kegagalan membaca berbunyi sama persis dengan
     * layout yang memang bersih, dan perbedaan itu tidak dapat ditemukan lagi setelahnya.
     *
     * @param  list<string>  $knownKeys
     * @return list<string>
     */
    public function unknownPlaceholders(string $path, string $format, array $knownKeys): array
    {
        $found = $format === 'docx' ? $this->docxPlaceholders($path) : $this->xlsxPlaceholders($path);

        return array_values(array_diff(array_unique($found), $knownKeys));
    }

    /** @return list<string> */
    private function docxPlaceholders(string $path): array
    {
        try {
            $variables = (new TemplateProcessor($path))->getVariables();
        } catch (Throwable) {
            /*
             * Bukan "tidak ada placeholder yang asing".
             *
             * `format()` sudah membuktikan berkasnya zip Word yang sah beberapa baris sebelumnya,
             * jadi gagal di sini berarti isinya tidak terbaca. Daftar kosong dari `catch` tidak
             * dapat dibedakan dari layout yang memang bersih: berkasnya lolos tanpa peringatan,
             * lalu tercetak kosong tepat di bagian yang seharusnya terisi, dan yang tersisa untuk
             * ditelusuri hanyalah dokumen jadi yang salah.
             *
             * Penolakan sampai ke orang yang baru saja mengunggahnya dan masih memegang berkas
             * aslinya — satu-satunya saat perbaikannya murah.
             */
            $this->reject('Isi berkas Word ini tidak terbaca meskipun berkasnya sah. Simpan ulang dari Word lalu unggah lagi.');
        }

        return array_map(fn (string $variable): string => preg_replace('/#\d+$/', '', $variable) ?? $variable, $variables);
    }

    /** @return list<string> */
    private function xlsxPlaceholders(string $path): array
    {
        try {
            $spreadsheet = IOFactory::load($path);
        } catch (Throwable) {
            // Sama seperti `docxPlaceholders()`: workbook yang sah tetapi tidak terbaca bukan
            // workbook tanpa placeholder asing, dan hanya penolakan yang membedakan keduanya.
            $this->reject('Isi berkas Excel ini tidak terbaca meskipun berkasnya sah. Simpan ulang dari Excel lalu unggah lagi.');
        }

        $found = [];
        foreach ($spreadsheet->getAllSheets() as $sheet) {
            foreach ($sheet->getRowIterator() as $row) {
                foreach ($row->getCellIterator() as $cell) {
                    $value = $cell->getValue();
                    if (is_string($value) && preg_match_all(self::MACRO_PATTERN, $value, $matches)) {
                        array_push($found, ...$matches[1]);
                    }
                }
            }
        }
        $spreadsheet->disconnectWorksheets();

        return $found;
    }

    private function reject(string $message): never
    {
        throw ValidationException::withMessages(['file' => [$message]]);
    }
}
