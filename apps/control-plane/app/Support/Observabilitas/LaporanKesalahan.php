<?php

declare(strict_types=1);

namespace App\Support\Observabilitas;

use App\Http\Middleware\ResolveModuleContext;
use App\Support\CurrentWorkspace;
use App\Support\Modules\ModuleRequestContext;
use App\Support\Modules\TenantScope;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use PDOException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/**
 * Satu kesalahan, dikumpulkan sekali, siap dibaca orang maupun mesin.
 *
 * Bentuknya meniru laporan yang sudah dipakai tim pada sistem lama: apa yang gagal, pada
 * permintaan mana, milik tenant siapa, dijalankan pengguna mana, dan — kalau kegagalannya di
 * database — query apa yang ditolak beserta apa kata drivernya. Yang berubah hanya isinya,
 * disesuaikan dengan yang benar-benar ada di CoreERP.
 *
 * **Semua dihitung di konstruktor, tidak ada yang ditunda.** Objek ini dibuat di dalam
 * penangan kesalahan, lalu ditulis ke dua tujuan. Kalau sebagian nilainya baru diambil saat
 * dirender, tujuan kedua bisa mendapat isi yang berbeda dari tujuan pertama — dan perbedaan
 * itu baru ketahuan saat seseorang membandingkan berkas log dengan SigNoz di tengah insiden.
 *
 * **Tidak ada jalur yang boleh melempar.** Aturan yang sama dengan {@see JejakAktif}, dan di
 * sini alasannya lebih tajam: kelas ini berjalan setelah sesuatu sudah gagal. Lemparan kedua
 * dari sini menghasilkan layar putih tanpa satu pun keterangan tentang kegagalan pertama.
 */
final class LaporanKesalahan
{
    /**
     * Atribut permintaan berisi **nama** yang bersanding dengan id.
     *
     * Ditulis {@see ResolveModuleContext} pada titik ketika ia sudah
     * memegang objeknya di memori, jadi mengambil namanya tidak berbiaya satu query pun.
     *
     * **Awalannya `observabilitas.`, bukan `coreerp.`, dan itu bukan selera.** Namespace
     * `coreerp.` adalah salinan harfiah kunci milik middleware app lama; 22 berkas module
     * membacanya langsung, dan sebuah test menjaga daftarnya kata per kata supaya penambahan
     * tidak lolos diam-diam. Penjaga itu menangkap percobaan pertama kunci ini — persis
     * seperti yang seharusnya. Nama atribut yang **dikirim ke SigNoz** tetap `coreerp.*`;
     * yang berbeda hanya kunci di dalam objek permintaan.
     *
     * Itu yang membuatnya penting: laporan tetap bisa menyebut "SurYA GrOUP" alih-alih hanya
     * `01kyvaf15a83dn64qp2zfr88pn` **bahkan ketika kesalahannya adalah database yang mati** —
     * keadaan yang justru melarang laporan menanyakan nama itu ke mana pun. Nama yang dibaca
     * dari memori adalah satu-satunya nama yang aman pada saat itu.
     */
    public const NAMA_TENANT = 'observabilitas.nama_tenant';

    public const NAMA_LEGAL_ENTITY = 'observabilitas.nama_legal_entity';

    public const NAMA_ORG_UNIT = 'observabilitas.nama_org_unit';

    public const NAMA_PENGGUNA = 'observabilitas.nama_pengguna';

    private const BATAS_JEJAK_TUMPUKAN = 4000;

    /**
     * @param  array<string, scalar|null>  $atribut
     */
    private function __construct(
        private readonly string $teks,
        private readonly string $ringkasan,
        private readonly array $atribut,
    ) {}

    public static function dari(Throwable $kesalahan, ?Request $permintaan): self
    {
        $baris = [];

        /*
         * Satu id yang menandai laporan ini, dicetak sekali dan dipakai di tiga tempat.
         *
         * Tanpa id, satu-satunya tali antara pesan di Discord dan catatannya di SigNoz adalah
         * `trace_id` — dan itu tidak ada pada perintah artisan maupun pekerja antrean, yaitu
         * dua tempat kesalahan paling sering luput diperhatikan. Yang tersisa untuk mereka
         * hanyalah mencocokkan potongan teks dengan mata.
         *
         * Dicetak di sini, bukan diambil dari sesuatu yang sudah ada, karena tidak ada
         * satu pun nilai yang berlaku untuk **semua** jalur sekaligus unik per kejadian.
         * ULID dipilih karena terurut menurut waktu: dua laporan berurutan duduk berdampingan
         * ketika diurutkan, dan itu gratis.
         */
        $atribut = ['coreerp.laporan_id' => (string) Str::ulid()];

        $kueri = self::kesalahanKueri($kesalahan);

        self::bagianKepala($kesalahan, $permintaan, $baris, $atribut);

        // Penjaga sesi memakai pemeriksaan yang lebih longgar daripada `$kueri`: sebuah
        // `PDOException` telanjang — koneksi ditolak sebelum satu query pun tersusun — tidak
        // pernah menjadi `QueryException`, padahal justru itu keadaan ketika bertanya ke
        // database adalah hal terakhir yang boleh dilakukan.
        self::bagianKonteks($permintaan, self::menyangkutDatabase($kesalahan), $baris, $atribut);
        self::bagianKesalahan($kesalahan, $baris, $atribut);
        self::bagianDatabase($kueri, $baris, $atribut);

        return new self(
            teks: implode("\n", $baris),
            ringkasan: self::ringkasanSatuBaris($kesalahan, $permintaan),
            atribut: $atribut,
        );
    }

    /** Blok utuh untuk berkas log dan untuk panel detail di SigNoz. */
    public function keTeks(): string
    {
        return $this->teks;
    }

    /** Satu baris untuk badan catatan log — strukturnya ada di atribut, bukan di sini. */
    public function ringkasan(): string
    {
        return $this->ringkasan;
    }

    /** @return array<string, scalar|null> */
    public function keAtribut(): array
    {
        return $this->atribut;
    }

    /**
     * @param  list<string>  $baris
     * @param  array<string, scalar|null>  $atribut
     */
    private static function bagianKepala(Throwable $kesalahan, ?Request $permintaan, array &$baris, array &$atribut): void
    {
        $baris[] = '🔴 CoreERP · KESALAHAN INTERNAL';
        $baris[] = str_repeat('─', 60);

        if ($permintaan instanceof Request) {
            $status = self::status($kesalahan);
            $baris[] = sprintf(
                '%s %s   ·   %d   ·   %s',
                $permintaan->method(),
                self::potong((string) $permintaan->fullUrl(), 300),
                $status,
                (string) ($permintaan->ip() ?? '-'),
            );

            $atribut['http.request.method'] = $permintaan->method();
            $atribut['http.response.status_code'] = $status;
            $atribut['url.full'] = self::potong((string) $permintaan->fullUrl(), 300);
            $atribut['url.path'] = '/'.ltrim($permintaan->path(), '/');
            $atribut['client.address'] = (string) ($permintaan->ip() ?? '');
            $atribut['user_agent.original'] = self::potong((string) $permintaan->userAgent(), 300);
            $atribut['coreerp.sumber_kesalahan'] = 'http';
        } else {
            $baris[] = 'konsol · di luar permintaan HTTP';
            $atribut['coreerp.sumber_kesalahan'] = app()->runningInConsole() ? 'konsol' : 'tak-diketahui';
        }

        $baris[] = now()->toDateTimeString().' '.now()->format('P');
        $baris[] = self::pasangan('laporan', self::teks($atribut['coreerp.laporan_id'] ?? null));
    }

    /**
     * @param  list<string>  $baris
     * @param  array<string, scalar|null>  $atribut
     */
    private static function bagianKonteks(?Request $permintaan, bool $kegagalanDatabase, array &$baris, array &$atribut): void
    {
        if (! $permintaan instanceof Request) {
            self::bagianKonteksLuarHttp($baris, $atribut);

            return;
        }

        $tenantId = self::atributPermintaan($permintaan, ModuleRequestContext::TENANT_ID);
        $moduleId = self::atributPermintaan($permintaan, ResolveModuleContext::MODULE_AKTIF);
        $entitas = self::atributPermintaan($permintaan, ModuleRequestContext::LEGAL_ENTITY_ID);
        $unit = self::atributPermintaan($permintaan, ModuleRequestContext::ORG_UNIT_ID);
        $penggunaId = self::atributPermintaan($permintaan, ModuleRequestContext::USER_ID);
        $appId = self::atributPermintaan($permintaan, 'coreerp.app_id');

        // Nama yang sudah ditaruh middleware module saat objeknya masih di memori. Dibaca
        // lebih dulu justru karena ia satu-satunya sumber nama yang aman ketika database mati.
        $namaTenant = self::atributPermintaan($permintaan, self::NAMA_TENANT);
        $namaEntitas = self::atributPermintaan($permintaan, self::NAMA_LEGAL_ENTITY);
        $namaUnit = self::atributPermintaan($permintaan, self::NAMA_ORG_UNIT);
        $namaPengguna = self::atributPermintaan($permintaan, self::NAMA_PENGGUNA);
        $slugTenant = null;

        // Rute control-plane biasa tidak melewati `ResolveModuleContext` maupun
        // `AuthenticateAppService`, jadi tidak ada satu pun atribut tenant di sana. Untuk itu
        // baru sesi ditanya — dan hanya kalau ketiga syarat di bawah terpenuhi.
        //
        // Syarat ketiga yang paling penting: `CurrentWorkspace::membership()` menjalankan query
        // dan menulis sesi. Memanggilnya saat yang gagal justru database berarti menanyakan
        // pada database kenapa database mati, membayar satu batas waktu koneksi, dan berisiko
        // melempar kesalahan kedua dari dalam penangan kesalahan pertama.
        $bolehTanyaSesi = ! $kegagalanDatabase
            && $permintaan->hasSession()
            && $permintaan->user() !== null;

        if ($bolehTanyaSesi) {
            try {
                $keanggotaan = app(CurrentWorkspace::class)->membership($permintaan);

                if ($keanggotaan !== null) {
                    $tenantId ??= (string) $keanggotaan->tenant_id;
                    $namaTenant ??= self::teks($keanggotaan->tenant->name);
                    $slugTenant = self::teks($keanggotaan->tenant->slug);
                }
            } catch (Throwable) {
                // Konteks tenant adalah nilai tambah pada laporan, bukan syaratnya.
            }
        }

        if ($permintaan->user() !== null) {
            try {
                $pengguna = $permintaan->user();
                $penggunaId ??= self::teks($pengguna->getAuthIdentifier());
                $namaPengguna ??= self::teks($pengguna->name ?? null);
            } catch (Throwable) {
                // Sama seperti di atas.
            }
        }

        $baris[] = self::pasangan('tenant', self::rangkaiTenant($tenantId, $namaTenant, $slugTenant));
        $baris[] = self::pasangan('pengguna', self::rangkaiPengguna($penggunaId, $namaPengguna));
        $baris[] = self::pasangan('module', $moduleId);
        $baris[] = self::pasangan('entitas', self::rangkaiBernama($namaEntitas, $entitas));
        $baris[] = self::pasangan('unit', self::rangkaiBernama($namaUnit, $unit));
        $baris[] = self::pasangan('app', $appId);
        $baris[] = self::pasangan('rute', self::teks($permintaan->route()?->getName()));
        $baris[] = self::pasangan('korelasi', self::rangkaiKorelasi($permintaan));

        $atribut['coreerp.tenant_id'] = $tenantId;
        $atribut['coreerp.tenant_name'] = $namaTenant;
        $atribut['coreerp.tenant_slug'] = $slugTenant;
        $atribut['coreerp.legal_entity_name'] = $namaEntitas;
        $atribut['coreerp.org_unit_name'] = $namaUnit;
        $atribut['coreerp.module_id'] = $moduleId;
        $atribut['coreerp.legal_entity_id'] = $entitas;
        $atribut['coreerp.org_unit_id'] = $unit;
        $atribut['coreerp.user_id'] = $penggunaId;
        $atribut['coreerp.user_name'] = $namaPengguna;
        $atribut['coreerp.app_id'] = $appId;
        $atribut['http.route'] = self::teks($permintaan->route()?->getName());

        // Nama field ini persis seperti yang dicari SigNoz untuk menyambungkan log ke jejak.
        $atribut['trace_id'] = JejakAktif::idJejak();
        $atribut['span_id'] = JejakAktif::idSpan();

        $korelasi = self::korelasiPermintaan($permintaan);
        if ($korelasi !== null) {
            $atribut['coreerp.correlation_id'] = $korelasi;
        }
    }

    /**
     * Konteks untuk kesalahan yang terjadi di luar permintaan HTTP.
     *
     * Sebelum ini, laporan dari perintah artisan dan pekerja antrean berisi tepat satu
     * keterangan: "di luar permintaan HTTP". Itu memberi tahu di mana kesalahannya **tidak**
     * terjadi, dan tidak satu pun tentang di mana ia terjadi — pada pemasangan on-prem, yang
     * tersisa bagi orang yang membacanya hanyalah jejak tumpukan.
     *
     * Dua hal yang bisa diketahui tanpa menyentuh database, dan keduanya diambil di sini:
     *
     * **Perintahnya**, dari `argv`. Itu menjawab "apa yang sedang berjalan" — pertanyaan
     * pertama yang ditanyakan siapa pun. Nilainya dipotong dan tidak pernah ditulis mentah ke
     * atribut metrik, karena argumen bisa memuat apa saja.
     *
     * **Tenant aktif**, dari ikatan container yang sama dengan yang dibaca `TenantScope`. Ini
     * bukan tebakan: ikatan itu adalah satu-satunya hal yang membuat query module berjalan
     * sama sekali, jadi ketika ada pekerjaan yang menyentuh data sebuah tenant, ikatannya
     * pasti ada. Yang tidak ada adalah **nama**-nya — untuk itu perlu bertanya ke database,
     * dan laporan ini sering berjalan justru ketika database yang gagal.
     *
     * Batasnya jujur: pekerjaan yang tidak pernah mengikat tenant — perintah lintas tenant,
     * migrasi, pekerjaan yang gagal sebelum sempat mengikat — tetap melaporkan `-`. Itu
     * keadaan sebenarnya, bukan kegagalan membaca.
     *
     * @param  list<string>  $baris
     * @param  array<string, scalar|null>  $atribut
     */
    private static function bagianKonteksLuarHttp(array &$baris, array &$atribut): void
    {
        $perintah = self::perintahBerjalan();

        if ($perintah !== null) {
            $baris[] = self::pasangan('perintah', $perintah);
            $atribut['coreerp.perintah'] = $perintah;
        }

        $tenantId = self::tenantTerikat();

        $baris[] = self::pasangan('tenant', $tenantId);
        $atribut['coreerp.tenant_id'] = $tenantId;
    }

    private static function perintahBerjalan(): ?string
    {
        try {
            $argv = $_SERVER['argv'] ?? null;

            if (! is_array($argv) || $argv === []) {
                return null;
            }

            // Nama berkasnya dibuang: `artisan` sama saja untuk setiap baris, dan jalur
            // absolutnya memakan tempat tanpa menambah keterangan.
            $bagian = array_slice(array_map(strval(...), $argv), 1);

            return $bagian === [] ? null : self::potong(implode(' ', $bagian), 300);
        } catch (Throwable) {
            return null;
        }
    }

    private static function tenantTerikat(): ?string
    {
        try {
            $nilai = app()->bound(TenantScope::KUNCI) ? app(TenantScope::KUNCI) : null;

            return is_string($nilai) && $nilai !== '' ? $nilai : null;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @param  list<string>  $baris
     * @param  array<string, scalar|null>  $atribut
     */
    private static function bagianKesalahan(Throwable $kesalahan, array &$baris, array &$atribut): void
    {
        $baris[] = str_repeat('─', 60);
        $baris[] = $kesalahan::class;
        $baris[] = $kesalahan->getMessage();
        $baris[] = self::lokasi($kesalahan);

        $atribut['exception.type'] = $kesalahan::class;
        $atribut['exception.message'] = self::potong($kesalahan->getMessage(), 2000);
        $atribut['exception.stacktrace'] = self::potong($kesalahan->getTraceAsString(), self::BATAS_JEJAK_TUMPUKAN);
        $atribut['code.filepath'] = $kesalahan->getFile();
        $atribut['code.lineno'] = $kesalahan->getLine();
    }

    /**
     * @param  list<string>  $baris
     * @param  array<string, scalar|null>  $atribut
     */
    private static function bagianDatabase(?QueryException $kueri, array &$baris, array &$atribut): void
    {
        if ($kueri === null) {
            return;
        }

        $rincian = [];
        try {
            $rincian = $kueri->getConnectionDetails();
        } catch (Throwable) {
            // Rincian koneksi hilang bukan alasan membuang seluruh bagian database.
        }

        $driver = self::teks($rincian['driver'] ?? null);
        $database = self::teks($rincian['database'] ?? null);
        $host = self::teks($rincian['host'] ?? null);
        $port = self::teks($rincian['port'] ?? null);
        $sqlstate = self::teks($kueri->errorInfo[0] ?? null);

        $baris[] = str_repeat('─', 60);
        $baris[] = trim(sprintf(
            '%s · %s%s%s%s',
            $driver ?? 'database',
            $database ?? '-',
            $host !== null ? ' @ '.$host.($port !== null ? ':'.$port : '') : '',
            $kueri->readWriteType !== null ? ' ('.$kueri->readWriteType.')' : '',
            $sqlstate !== null ? ' · SQLSTATE '.$sqlstate : '',
        ));

        // Pesan driver mentah — ini kalimat yang benar-benar menyebut kendala mana yang
        // dilanggar. Pesan `QueryException` sendiri adalah kalimat itu dengan SQL ditempel di
        // belakangnya, jadi keduanya ditampilkan terpisah supaya yang penting tidak tenggelam.
        $pesanDriver = self::teks($kueri->getPrevious()?->getMessage());
        if ($pesanDriver !== null) {
            $baris[] = $pesanDriver;
            $atribut['db.response.message'] = self::potong($pesanDriver, 2000);
        }

        $sql = null;
        $binding = [];
        try {
            $sql = $kueri->getSql();
            $binding = $kueri->getBindings();
        } catch (Throwable) {
            // Biarkan kosong; bagian di bawah menanganinya.
        }

        if ($sql !== null) {
            $terbaca = SqlTerbaca::gabungkan($sql, $binding);

            $baris[] = '';
            $baris[] = 'SQL:';
            $baris[] = $terbaca ?? $sql;

            // Rekonstruksi gagal berarti jumlah tanda tanya tidak cocok dengan jumlah binding.
            // Nilainya tetap ditampilkan, hanya terpisah — menebak query utuh dari data yang
            // tidak konsisten adalah cara membuat laporan yang percaya diri tetapi salah.
            if ($terbaca === null && $binding !== []) {
                $baris[] = 'binding: '.self::potong(json_encode($binding, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR) ?: '-', 2000);
            }

            // Bentuk berparameter yang dipakai SigNoz untuk mengelompokkan; bentuk terbaca
            // yang dipakai orang untuk menelusuri.
            $atribut['db.query.text'] = self::potong($sql, 8000);
            $atribut['coreerp.db.query_terbaca'] = $terbaca;

            // Kesalahan "nilai tidak muat" adalah satu-satunya jenis yang pesannya tidak
            // pernah menyebut nilai penyebabnya. Menghitungnya di sini, saat kejadiannya masih
            // segar, menghemat pekerjaan mencocokkan tanda tanya dengan binding satu per satu.
            if (TersangkaPemotongan::cocok($sqlstate, $pesanDriver)) {
                $batas = TersangkaPemotongan::batasDariPesan($pesanDriver);
                $tersangka = TersangkaPemotongan::daftar($sql, $binding, $batas);

                if ($tersangka !== []) {
                    $baris[] = '';
                    $baris[] = $batas !== null
                        ? sprintf('Nilai yang tidak muat (batas kolom %d karakter):', $batas)
                        : 'Nilai terpanjang (tersangka pemotongan):';

                    foreach ($tersangka as $satu) {
                        $baris[] = sprintf(
                            '  %s %-22s %4d karakter   %s',
                            $satu['melebihi'] ? '→' : ' ',
                            $satu['kolom'],
                            $satu['panjang'],
                            $satu['cuplikan'],
                        );
                    }

                    $atribut['coreerp.db.tersangka_kolom'] = $tersangka[0]['kolom'];
                    $atribut['coreerp.db.tersangka_panjang'] = $tersangka[0]['panjang'];
                    $atribut['coreerp.db.batas_kolom'] = $batas;
                }
            }
        }

        $atribut['db.system'] = $driver;
        $atribut['db.namespace'] = $database;
        $atribut['db.response.status_code'] = $sqlstate;
        $atribut['coreerp.db.connection'] = self::teks($kueri->getConnectionName());
    }

    /**
     * Menemukan `QueryException` pada rantai sebab, bukan hanya di permukaan. Kegagalan
     * database sering sudah dibungkus lapisan lain sebelum sampai ke penangan.
     */
    private static function kesalahanKueri(Throwable $kesalahan): ?QueryException
    {
        $sekarang = $kesalahan;

        for ($langkah = 0; $langkah < 10 && $sekarang !== null; $langkah++) {
            if ($sekarang instanceof QueryException) {
                return $sekarang;
            }

            $sekarang = $sekarang->getPrevious();
        }

        return null;
    }

    /**
     * Apakah kesalahan ini menyangkut database — dipakai untuk memutuskan boleh-tidaknya
     * bertanya ke sesi. Lebih longgar dari {@see self::kesalahanKueri()}: `PDOException`
     * telanjang pun cukup untuk membuat kita tidak menyentuh database lagi.
     */
    private static function menyangkutDatabase(Throwable $kesalahan): bool
    {
        $sekarang = $kesalahan;

        for ($langkah = 0; $langkah < 10 && $sekarang !== null; $langkah++) {
            if ($sekarang instanceof QueryException || $sekarang instanceof PDOException) {
                return true;
            }

            $sekarang = $sekarang->getPrevious();
        }

        return false;
    }

    private static function status(Throwable $kesalahan): int
    {
        // Diambil dari kesalahannya, bukan dari respons: pelapor berjalan sebelum respons
        // dirender, jadi pada saat ini belum ada status yang bisa dibaca.
        return $kesalahan instanceof HttpExceptionInterface ? $kesalahan->getStatusCode() : 500;
    }

    private static function ringkasanSatuBaris(Throwable $kesalahan, ?Request $permintaan): string
    {
        $inti = $kesalahan::class.': '.self::potong($kesalahan->getMessage(), 300);

        if (! $permintaan instanceof Request) {
            return $inti;
        }

        return sprintf('%s @ %s /%s', $inti, $permintaan->method(), ltrim($permintaan->path(), '/'));
    }

    private static function korelasiPermintaan(Request $permintaan): ?string
    {
        // Hanya dibaca, tidak pernah dibuat. Correlation id yang dicetak sendiri oleh pelapor
        // tidak berhubungan dengan apa pun, dan justru menyesatkan orang pertama yang
        // mencarinya. Yang ada di sini berumur panjang — dipakai alur kerja yang event
        // keputusannya terbit berhari-hari kemudian.
        $nilai = self::teks($permintaan->header('X-Correlation-Id'));

        return $nilai !== null && preg_match('/^[0-9A-HJKMNP-TV-Z]{26}$/i', $nilai) === 1 ? $nilai : null;
    }

    private static function rangkaiKorelasi(Request $permintaan): ?string
    {
        $bagian = [];

        $jejak = JejakAktif::idJejak();
        if ($jejak !== null) {
            $bagian[] = 'jejak '.$jejak;
        }

        $alur = self::korelasiPermintaan($permintaan);
        if ($alur !== null) {
            $bagian[] = 'alur '.$alur;
        }

        return $bagian === [] ? null : implode(' · ', $bagian);
    }

    private static function rangkaiTenant(?string $id, ?string $nama, ?string $slug): ?string
    {
        if ($id === null && $nama === null) {
            return null;
        }

        if ($nama === null) {
            return $id;
        }

        $penanda = array_values(array_filter([$slug, $id], static fn (?string $n): bool => $n !== null));

        return $penanda === [] ? $nama : $nama.' ('.implode(' / ', $penanda).')';
    }

    /**
     * `Nama (id)`, atau salah satunya kalau yang lain tidak ada.
     *
     * Bentuk ini yang membuat laporan bisa dibaca dan sekaligus ditindaklanjuti: namanya untuk
     * mengerti, id-nya untuk mencari barisnya di database tanpa menebak.
     */
    private static function rangkaiBernama(?string $nama, ?string $id): ?string
    {
        if ($nama === null) {
            return $id;
        }

        return $id === null ? $nama : $nama.' ('.$id.')';
    }

    private static function rangkaiPengguna(?string $id, ?string $nama): ?string
    {
        if ($nama === null) {
            return $id;
        }

        return $id === null ? $nama : $nama.' ('.$id.')';
    }

    private static function lokasi(Throwable $kesalahan): string
    {
        return $kesalahan->getFile().':'.$kesalahan->getLine();
    }

    /**
     * Baris berlabel. Field yang tidak tersedia ditandai `-`, tidak dihilangkan — pembaca
     * laporan perlu bisa membedakan "tidak ada tenant pada permintaan ini" dari "bagian ini
     * lupa ditulis".
     */
    private static function pasangan(string $label, ?string $nilai): string
    {
        return sprintf('%-9s: %s', $label, $nilai ?? '-');
    }

    private static function atributPermintaan(Request $permintaan, string $kunci): ?string
    {
        try {
            return self::teks($permintaan->attributes->get($kunci));
        } catch (Throwable) {
            return null;
        }
    }

    private static function teks(mixed $nilai): ?string
    {
        if ($nilai === null || is_array($nilai) || is_object($nilai)) {
            return null;
        }

        $teks = trim((string) $nilai);

        return $teks === '' ? null : $teks;
    }

    private static function potong(string $nilai, int $batas): string
    {
        if (mb_strlen($nilai) <= $batas) {
            return $nilai;
        }

        return mb_substr($nilai, 0, $batas).' … (dipotong)';
    }

    /** Dipakai {@see PelaporKesalahan} untuk memutuskan sebuah kesalahan layak dilaporkan. */
    public static function layakDilaporkan(Throwable $kesalahan): bool
    {
        // 404, 419, dan 422 bukan kesalahan internal. Membiarkannya masuk berarti mengubur
        // laporan yang benar-benar berarti di bawah derasnya lalu lintas biasa — dan laporan
        // yang tidak pernah dibaca sama nilainya dengan laporan yang tidak pernah ditulis.
        if ($kesalahan instanceof HttpExceptionInterface) {
            $status = $kesalahan->getStatusCode();

            return $status < 400 || $status >= 500;
        }

        return true;
    }

    /** Dipakai {@see self::dari()} dan oleh test; dipublikkan supaya perilakunya bisa diuji. */
    public static function kegagalanDatabase(Throwable $kesalahan): bool
    {
        return self::menyangkutDatabase($kesalahan);
    }
}
