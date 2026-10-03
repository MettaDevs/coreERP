<?php

declare(strict_types=1);

namespace Modules\Apperp\ManagementAset\Http\Controllers\transaksi\InventarisasiAset;

use BaconQrCode\Common\ErrorCorrectionLevel;
use BaconQrCode\Encoder\Encoder;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Modules\Apperp\ManagementAset\Http\Controllers\Controller;
use Modules\Apperp\ManagementAset\Models\transaksi\InventarisasiAset\Aset;
use Modules\Apperp\ManagementAset\Services\AssetOrganizationDirectory;
use Modules\Apperp\ManagementAset\Support\OrganizationScope;
use stdClass;

/**
 * Lembar label aset siap cetak: kode QR, kode aset, nama, lokasi, dan unit kerja.
 *
 * Padanannya laporan **Fixed asset bar codes** (`AssetBarcode`) Dynamics 365 Finance — Fixed assets >
 * Reports > Base data — yang dicetak untuk ditempel pada barang dan dipindai saat stock opname. Isi
 * kodenya mengikuti setelan F&O **Bar code equals fixed asset number**: QR memuat kode aset, bukan
 * alamat layar. Kode aset tidak pernah berganti, terbaca oleh pemindai mana pun tanpa jaringan, dan
 * hasil pindaiannya bisa langsung diketik ke kotak cari register; alamat layar ikut berubah setiap kali
 * domain atau rute berubah, dan label yang sudah tertempel tidak bisa dicetak ulang massal.
 *
 * **Kenapa halaman cetak, bukan mesin laporan Core.** Mesin laporan mengisi layout Word/Excel dengan
 * baris tabel yang diulang ke bawah, dan gambar hanya dikenalnya sebagai logo kop. Label adalah kisi
 * tiga kolom dengan satu gambar QR per sel — dua hal yang tidak dapat diungkapkan layout itu tanpa
 * mengubah renderer Core. Lembar HTML dengan ukuran milimeter yang tetap dicetak peramban langsung ke
 * kertas label A4 tanpa antrean ekspor.
 *
 * Hak dan cakupannya sama dengan `GET /aset`: izin `management-aset.aset.read`, lalu kebijakan data
 * tanggung jawab aset lewat {@see OrganizationScope}. Aset di luar cakupan tidak dicetak dan tidak
 * disebut — sama seperti ia tidak tampil di register.
 */
final class AssetLabelController extends Controller
{
    /** Sepuluh lembar A4. Batas ini juga menjaga panjang alamat saat aset dipilih satu per satu. */
    public const MAX_LABELS = 240;

    /** Label per lembar: kertas label A4 3 × 8 berukuran 63,5 × 33,9 mm. */
    public const LABELS_PER_SHEET = 24;

    public function __invoke(Request $request): Response
    {
        abort_unless(in_array('management-aset.aset.read', (array) $request->attributes->get('coreerp.permissions', []), true), 403);

        $ids = $this->ids($request->query('ids'));
        $search = mb_substr(trim(is_string($request->query('q')) ? $request->query('q') : ''), 0, 100);

        $query = app(OrganizationScope::class)->asetQuery(Aset::query(), $request)
            ->leftJoin('aset_m_lokasi_aset as lokasi', fn (JoinClause $join) => $join
                ->on('lokasi.id', '=', 'aset_tr_aset.lokasi_aset_id')
                ->on('lokasi.tenant_id', '=', 'aset_tr_aset.tenant_id'));

        // `ids` yang dikirim tetapi tidak berisi id sah tidak boleh jatuh ke "semua aset".
        if ($request->query->has('ids')) {
            $query->whereIn('aset_tr_aset.id', $ids);
        } elseif ($search !== '') {
            // Sama dengan pencarian `GET /aset`, supaya "cetak semua yang tampil" mencetak yang tampil.
            $pattern = '%'.mb_strtolower($search).'%';
            $query->where(fn ($builder) => $builder
                ->whereRaw('LOWER(aset_tr_aset.kode) LIKE ?', [$pattern])
                ->orWhereRaw('LOWER(aset_tr_aset.nama) LIKE ?', [$pattern])
                ->orWhereRaw('LOWER(aset_tr_aset.serial_number) LIKE ?', [$pattern]));
        }

        $rows = $query
            ->orderBy('aset_tr_aset.kode')
            ->limit(self::MAX_LABELS + 1)
            ->toBase()
            ->get(['aset_tr_aset.kode', 'aset_tr_aset.nama', 'aset_tr_aset.responsible_org_unit_id', 'lokasi.nama as lokasi_nama']);

        if ($rows->count() > self::MAX_LABELS) {
            return $this->page([], sprintf(
                'Paling banyak %d label sekali cetak. Persempit pencarian atau pilih asetnya, lalu cetak bertahap.',
                self::MAX_LABELS,
            ), 422);
        }
        if ($rows->isEmpty()) {
            return $this->page([], 'Tidak ada aset yang dapat dicetak labelnya. Aset mungkin sudah tidak ada atau berada di luar unit kerja yang dapat Anda akses.', 404);
        }

        $tenantId = (string) $request->attributes->get('coreerp.tenant_id');
        $directory = app(AssetOrganizationDirectory::class);
        $writer = new Writer(new ImageRenderer(new RendererStyle(240, 2), new SvgImageBackEnd));

        $labels = $rows->map(fn (stdClass $row): array => [
            'kode' => (string) $row->kode,
            'nama' => (string) $row->nama,
            'lokasi' => $row->lokasi_nama !== null ? (string) $row->lokasi_nama : null,
            'unit' => $directory->unitName($tenantId, $row->responsible_org_unit_id !== null ? (string) $row->responsible_org_unit_id : null),
            'qr' => $this->qr($writer, (string) $row->kode),
        ])->all();

        return $this->page($labels, null, 200);
    }

    /**
     * Id aset dari `ids=a,b,c`. Yang bukan ULID dibuang di sini, bukan dikirim ke database.
     *
     * @return list<string>
     */
    private function ids(mixed $value): array
    {
        if (! is_string($value) || $value === '') {
            return [];
        }

        $ids = array_filter(
            array_map('trim', explode(',', $value)),
            static fn (string $id): bool => preg_match('/^[0-9A-HJKMNP-TV-Z]{26}$/i', $id) === 1,
        );

        return array_values(array_unique(array_slice($ids, 0, self::MAX_LABELS + 1)));
    }

    /**
     * Kode QR sebagai SVG sebaris. Tingkat koreksi kesalahan M (±15%): label tempel tergores dan
     * terlipat, dan tingkat L bawaan pustakanya terlalu rapuh untuk itu.
     */
    private function qr(Writer $writer, string $content): string
    {
        $svg = $writer->writeString($content, Encoder::DEFAULT_BYTE_MODE_ENCODING, ErrorCorrectionLevel::M());

        // Baris pertama deklarasi XML; ia tidak sah di tengah dokumen HTML.
        return trim(substr($svg, (int) strpos($svg, "\n") + 1));
    }

    /** @param list<array{kode: string, nama: string, lokasi: ?string, unit: ?string, qr: string}> $labels */
    private function page(array $labels, ?string $message, int $status): Response
    {
        // Berkas view dibaca lewat jalurnya, bukan namespace view: module ini hanya punya satu
        // halaman Blade, dan mendaftarkan namespace untuk satu berkas menambah pintu yang tidak dipakai.
        $html = view()->file(dirname(__DIR__, 5).'/resources/views/asset-labels.blade.php', [
            'sheets' => array_chunk($labels, self::LABELS_PER_SHEET),
            'total' => count($labels),
            'message' => $message,
        ])->render();

        return response($html, $status, ['Content-Type' => 'text/html; charset=UTF-8']);
    }
}
