<?php

declare(strict_types=1);

namespace App\Platform\Observability\Support;

use App\Platform\Environment\Support\CurrentWorkspace;
use App\Platform\Modules\Http\Middleware\ResolveModuleContext;
use App\Platform\Modules\Support\ModuleRequestContext;
use App\Platform\Modules\Support\TenantScope;
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
 * **Tidak ada jalur yang boleh melempar.** Aturan yang sama dengan {@see ActiveSpan}, dan di
 * sini alasannya lebih tajam: kelas ini berjalan setelah sesuatu sudah gagal. Lemparan kedua
 * dari sini menghasilkan layar putih tanpa satu pun keterangan tentang kegagalan pertama.
 */
final class ErrorReport
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
    public const TENANT_NAME = 'observabilitas.nama_tenant';

    public const LEGAL_ENTITY_NAME = 'observabilitas.nama_legal_entity';

    public const ORG_UNIT_NAME = 'observabilitas.nama_org_unit';

    public const USER_NAME = 'observabilitas.nama_pengguna';

    private const STACK_TRACE_LIMIT = 4000;

    /**
     * @param  array<string, scalar|null>  $attributes
     */
    private function __construct(
        private readonly string $text,
        private readonly string $summary,
        private readonly array $attributes,
    ) {}

    public static function from(Throwable $error, ?Request $request): self
    {
        $lines = [];

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
        $attributes = ['coreerp.laporan_id' => (string) Str::ulid()];

        $query = self::queryException($error);

        self::headerSection($error, $request, $lines, $attributes);

        // Penjaga sesi memakai pemeriksaan yang lebih longgar daripada `$query`: sebuah
        // `PDOException` telanjang — koneksi ditolak sebelum satu query pun tersusun — tidak
        // pernah menjadi `QueryException`, padahal justru itu keadaan ketika bertanya ke
        // database adalah hal terakhir yang boleh dilakukan.
        self::contextSection($request, self::involvesDatabase($error), $lines, $attributes);
        self::errorSection($error, $lines, $attributes);
        self::databaseSection($query, $lines, $attributes);

        return new self(
            text: implode("\n", $lines),
            summary: self::oneLineSummary($error, $request),
            attributes: $attributes,
        );
    }

    /** Blok utuh untuk berkas log dan untuk panel detail di SigNoz. */
    public function toText(): string
    {
        return $this->text;
    }

    /** Satu baris untuk badan catatan log — strukturnya ada di atribut, bukan di sini. */
    public function summary(): string
    {
        return $this->summary;
    }

    /** @return array<string, scalar|null> */
    public function toAttributes(): array
    {
        return $this->attributes;
    }

    /**
     * @param  list<string>  $lines
     * @param  array<string, scalar|null>  $attributes
     */
    private static function headerSection(Throwable $error, ?Request $request, array &$lines, array &$attributes): void
    {
        $lines[] = '🔴 CoreERP · KESALAHAN INTERNAL';
        $lines[] = str_repeat('─', 60);

        if ($request instanceof Request) {
            $status = self::status($error);
            $lines[] = sprintf(
                '%s %s   ·   %d   ·   %s',
                $request->method(),
                self::truncate((string) $request->fullUrl(), 300),
                $status,
                (string) ($request->ip() ?? '-'),
            );

            $attributes['http.request.method'] = $request->method();
            $attributes['http.response.status_code'] = $status;
            $attributes['url.full'] = self::truncate((string) $request->fullUrl(), 300);
            $attributes['url.path'] = '/'.ltrim($request->path(), '/');
            $attributes['client.address'] = (string) ($request->ip() ?? '');
            $attributes['user_agent.original'] = self::truncate((string) $request->userAgent(), 300);
            $attributes['coreerp.sumber_kesalahan'] = 'http';
        } else {
            $lines[] = 'konsol · di luar permintaan HTTP';
            $attributes['coreerp.sumber_kesalahan'] = app()->runningInConsole() ? 'konsol' : 'tak-diketahui';
        }

        $lines[] = now()->toDateTimeString().' '.now()->format('P');
        $lines[] = self::pair('laporan', self::text($attributes['coreerp.laporan_id'] ?? null));
    }

    /**
     * @param  list<string>  $lines
     * @param  array<string, scalar|null>  $attributes
     */
    private static function contextSection(?Request $request, bool $databaseFailure, array &$lines, array &$attributes): void
    {
        if (! $request instanceof Request) {
            self::nonHttpContextSection($lines, $attributes);

            return;
        }

        $tenantId = self::requestAttribute($request, ModuleRequestContext::TENANT_ID);
        $moduleId = self::requestAttribute($request, ResolveModuleContext::ACTIVE_MODULE);
        $legalEntity = self::requestAttribute($request, ModuleRequestContext::LEGAL_ENTITY_ID);
        $unit = self::requestAttribute($request, ModuleRequestContext::ORG_UNIT_ID);
        $userId = self::requestAttribute($request, ModuleRequestContext::USER_ID);
        $appId = self::requestAttribute($request, 'coreerp.app_id');

        // Nama yang sudah ditaruh middleware module saat objeknya masih di memori. Dibaca
        // lebih dulu justru karena ia satu-satunya sumber nama yang aman ketika database mati.
        $tenantName = self::requestAttribute($request, self::TENANT_NAME);
        $legalEntityName = self::requestAttribute($request, self::LEGAL_ENTITY_NAME);
        $unitName = self::requestAttribute($request, self::ORG_UNIT_NAME);
        $userName = self::requestAttribute($request, self::USER_NAME);
        $slugTenant = null;

        // Rute Core biasa tidak melewati `ResolveModuleContext` maupun
        // `AuthenticateAppService`, jadi tidak ada satu pun atribut tenant di sana. Untuk itu
        // baru sesi ditanya — dan hanya kalau ketiga syarat di bawah terpenuhi.
        //
        // Syarat ketiga yang paling penting: `CurrentWorkspace::membership()` menjalankan query
        // dan menulis sesi. Memanggilnya saat yang gagal justru database berarti menanyakan
        // pada database kenapa database mati, membayar satu batas waktu koneksi, dan berisiko
        // melempar kesalahan kedua dari dalam penangan kesalahan pertama.
        $mayAskSession = ! $databaseFailure
            && $request->hasSession()
            && $request->user() !== null;

        if ($mayAskSession) {
            try {
                $membership = app(CurrentWorkspace::class)->membership($request);

                if ($membership !== null) {
                    $tenantId ??= (string) $membership->tenant_id;
                    $tenantName ??= self::text($membership->tenant->name);
                    $slugTenant = self::text($membership->tenant->slug);
                }
            } catch (Throwable) {
                // Konteks tenant adalah nilai tambah pada laporan, bukan syaratnya.
            }
        }

        if ($request->user() !== null) {
            try {
                $user = $request->user();
                $userId ??= self::text($user->getAuthIdentifier());
                $userName ??= self::text($user->name ?? null);
            } catch (Throwable) {
                // Sama seperti di atas.
            }
        }

        $lines[] = self::pair('tenant', self::joinTenant($tenantId, $tenantName, $slugTenant));
        $lines[] = self::pair('pengguna', self::joinUser($userId, $userName));
        $lines[] = self::pair('module', $moduleId);
        $lines[] = self::pair('entitas', self::joinNamed($legalEntityName, $legalEntity));
        $lines[] = self::pair('unit', self::joinNamed($unitName, $unit));
        $lines[] = self::pair('app', $appId);
        $lines[] = self::pair('rute', self::text($request->route()?->getName()));
        $lines[] = self::pair('korelasi', self::joinCorrelation($request));

        $attributes['coreerp.tenant_id'] = $tenantId;
        $attributes['coreerp.tenant_name'] = $tenantName;
        $attributes['coreerp.tenant_slug'] = $slugTenant;
        $attributes['coreerp.legal_entity_name'] = $legalEntityName;
        $attributes['coreerp.org_unit_name'] = $unitName;
        $attributes['coreerp.module_id'] = $moduleId;
        $attributes['coreerp.legal_entity_id'] = $legalEntity;
        $attributes['coreerp.org_unit_id'] = $unit;
        $attributes['coreerp.user_id'] = $userId;
        $attributes['coreerp.user_name'] = $userName;
        $attributes['coreerp.app_id'] = $appId;
        $attributes['http.route'] = self::text($request->route()?->getName());

        // Nama field ini persis seperti yang dicari SigNoz untuk menyambungkan log ke jejak.
        $attributes['trace_id'] = ActiveSpan::traceId();
        $attributes['span_id'] = ActiveSpan::spanId();

        $correlation = self::requestCorrelation($request);
        if ($correlation !== null) {
            $attributes['coreerp.correlation_id'] = $correlation;
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
     * @param  list<string>  $lines
     * @param  array<string, scalar|null>  $attributes
     */
    private static function nonHttpContextSection(array &$lines, array &$attributes): void
    {
        $command = self::runningCommand();

        if ($command !== null) {
            $lines[] = self::pair('perintah', $command);
            $attributes['coreerp.perintah'] = $command;
        }

        $tenantId = self::boundTenant();

        $lines[] = self::pair('tenant', $tenantId);
        $attributes['coreerp.tenant_id'] = $tenantId;
    }

    private static function runningCommand(): ?string
    {
        try {
            $argv = $_SERVER['argv'] ?? null;

            if (! is_array($argv) || $argv === []) {
                return null;
            }

            // Nama berkasnya dibuang: `artisan` sama saja untuk setiap baris, dan jalur
            // absolutnya memakan tempat tanpa menambah keterangan.
            $parts = array_slice(array_map(strval(...), $argv), 1);

            return $parts === [] ? null : self::truncate(implode(' ', $parts), 300);
        } catch (Throwable) {
            return null;
        }
    }

    private static function boundTenant(): ?string
    {
        try {
            $value = app()->bound(TenantScope::KEY) ? app(TenantScope::KEY) : null;

            return is_string($value) && $value !== '' ? $value : null;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @param  list<string>  $lines
     * @param  array<string, scalar|null>  $attributes
     */
    private static function errorSection(Throwable $error, array &$lines, array &$attributes): void
    {
        $lines[] = str_repeat('─', 60);
        $lines[] = $error::class;
        $lines[] = $error->getMessage();
        $lines[] = self::location($error);

        $attributes['exception.type'] = $error::class;
        $attributes['exception.message'] = self::truncate($error->getMessage(), 2000);
        $attributes['exception.stacktrace'] = self::truncate($error->getTraceAsString(), self::STACK_TRACE_LIMIT);
        $attributes['code.filepath'] = $error->getFile();
        $attributes['code.lineno'] = $error->getLine();
    }

    /**
     * @param  list<string>  $lines
     * @param  array<string, scalar|null>  $attributes
     */
    private static function databaseSection(?QueryException $query, array &$lines, array &$attributes): void
    {
        if ($query === null) {
            return;
        }

        $details = [];
        try {
            $details = $query->getConnectionDetails();
        } catch (Throwable) {
            // Rincian koneksi hilang bukan alasan membuang seluruh bagian database.
        }

        $driver = self::text($details['driver'] ?? null);
        $database = self::text($details['database'] ?? null);
        $host = self::text($details['host'] ?? null);
        $port = self::text($details['port'] ?? null);
        $sqlstate = self::text($query->errorInfo[0] ?? null);

        $lines[] = str_repeat('─', 60);
        $lines[] = trim(sprintf(
            '%s · %s%s%s%s',
            $driver ?? 'database',
            $database ?? '-',
            $host !== null ? ' @ '.$host.($port !== null ? ':'.$port : '') : '',
            $query->readWriteType !== null ? ' ('.$query->readWriteType.')' : '',
            $sqlstate !== null ? ' · SQLSTATE '.$sqlstate : '',
        ));

        // Pesan driver mentah — ini kalimat yang benar-benar menyebut kendala mana yang
        // dilanggar. Pesan `QueryException` sendiri adalah kalimat itu dengan SQL ditempel di
        // belakangnya, jadi keduanya ditampilkan terpisah supaya yang penting tidak tenggelam.
        $driverMessage = self::text($query->getPrevious()?->getMessage());
        if ($driverMessage !== null) {
            $lines[] = $driverMessage;
            $attributes['db.response.message'] = self::truncate($driverMessage, 2000);
        }

        $sql = null;
        $binding = [];
        try {
            $sql = $query->getSql();
            $binding = $query->getBindings();
        } catch (Throwable) {
            // Biarkan kosong; bagian di bawah menanganinya.
        }

        if ($sql !== null) {
            $readable = ReadableSql::interpolate($sql, $binding);

            $lines[] = '';
            $lines[] = 'SQL:';
            $lines[] = $readable ?? $sql;

            // Rekonstruksi gagal berarti jumlah tanda tanya tidak cocok dengan jumlah binding.
            // Nilainya tetap ditampilkan, hanya terpisah — menebak query utuh dari data yang
            // tidak konsisten adalah cara membuat laporan yang percaya diri tetapi salah.
            if ($readable === null && $binding !== []) {
                $lines[] = 'binding: '.self::truncate(json_encode($binding, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR) ?: '-', 2000);
            }

            // Bentuk berparameter yang dipakai SigNoz untuk mengelompokkan; bentuk terbaca
            // yang dipakai orang untuk menelusuri.
            $attributes['db.query.text'] = self::truncate($sql, 8000);
            $attributes['coreerp.db.query_terbaca'] = $readable;

            // Kesalahan "nilai tidak muat" adalah satu-satunya jenis yang pesannya tidak
            // pernah menyebut nilai penyebabnya. Menghitungnya di sini, saat kejadiannya masih
            // segar, menghemat pekerjaan mencocokkan tanda tanya dengan binding satu per satu.
            if (TruncationSuspects::matches($sqlstate, $driverMessage)) {
                $limit = TruncationSuspects::limitFromMessage($driverMessage);
                $suspects = TruncationSuspects::list($sql, $binding, $limit);

                if ($suspects !== []) {
                    $lines[] = '';
                    $lines[] = $limit !== null
                        ? sprintf('Nilai yang tidak muat (batas kolom %d karakter):', $limit)
                        : 'Nilai terpanjang (tersangka pemotongan):';

                    foreach ($suspects as $item) {
                        $lines[] = sprintf(
                            '  %s %-22s %4d karakter   %s',
                            $item['melebihi'] ? '→' : ' ',
                            $item['kolom'],
                            $item['panjang'],
                            $item['cuplikan'],
                        );
                    }

                    $attributes['coreerp.db.tersangka_kolom'] = $suspects[0]['kolom'];
                    $attributes['coreerp.db.tersangka_panjang'] = $suspects[0]['panjang'];
                    $attributes['coreerp.db.batas_kolom'] = $limit;
                }
            }
        }

        $attributes['db.system'] = $driver;
        $attributes['db.namespace'] = $database;
        $attributes['db.response.status_code'] = $sqlstate;
        $attributes['coreerp.db.connection'] = self::text($query->getConnectionName());
    }

    /**
     * Menemukan `QueryException` pada rantai sebab, bukan hanya di permukaan. Kegagalan
     * database sering sudah dibungkus lapisan lain sebelum sampai ke penangan.
     */
    private static function queryException(Throwable $error): ?QueryException
    {
        $now = $error;

        for ($step = 0; $step < 10 && $now !== null; $step++) {
            if ($now instanceof QueryException) {
                return $now;
            }

            $now = $now->getPrevious();
        }

        return null;
    }

    /**
     * Apakah kesalahan ini menyangkut database — dipakai untuk memutuskan boleh-tidaknya
     * bertanya ke sesi. Lebih longgar dari {@see self::kesalahanKueri()}: `PDOException`
     * telanjang pun cukup untuk membuat kita tidak menyentuh database lagi.
     */
    private static function involvesDatabase(Throwable $error): bool
    {
        $now = $error;

        for ($step = 0; $step < 10 && $now !== null; $step++) {
            if ($now instanceof QueryException || $now instanceof PDOException) {
                return true;
            }

            $now = $now->getPrevious();
        }

        return false;
    }

    private static function status(Throwable $error): int
    {
        // Diambil dari kesalahannya, bukan dari respons: pelapor berjalan sebelum respons
        // dirender, jadi pada saat ini belum ada status yang bisa dibaca.
        return $error instanceof HttpExceptionInterface ? $error->getStatusCode() : 500;
    }

    private static function oneLineSummary(Throwable $error, ?Request $request): string
    {
        $core = $error::class.': '.self::truncate($error->getMessage(), 300);

        if (! $request instanceof Request) {
            return $core;
        }

        return sprintf('%s @ %s /%s', $core, $request->method(), ltrim($request->path(), '/'));
    }

    private static function requestCorrelation(Request $request): ?string
    {
        // Hanya dibaca, tidak pernah dibuat. Correlation id yang dicetak sendiri oleh pelapor
        // tidak berhubungan dengan apa pun, dan justru menyesatkan orang pertama yang
        // mencarinya. Yang ada di sini berumur panjang — dipakai alur kerja yang event
        // keputusannya terbit berhari-hari kemudian.
        $value = self::text($request->header('X-Correlation-Id'));

        return $value !== null && preg_match('/^[0-9A-HJKMNP-TV-Z]{26}$/i', $value) === 1 ? $value : null;
    }

    private static function joinCorrelation(Request $request): ?string
    {
        $parts = [];

        $trace = ActiveSpan::traceId();
        if ($trace !== null) {
            $parts[] = 'jejak '.$trace;
        }

        $flow = self::requestCorrelation($request);
        if ($flow !== null) {
            $parts[] = 'alur '.$flow;
        }

        return $parts === [] ? null : implode(' · ', $parts);
    }

    private static function joinTenant(?string $id, ?string $name, ?string $slug): ?string
    {
        if ($id === null && $name === null) {
            return null;
        }

        if ($name === null) {
            return $id;
        }

        $marker = array_values(array_filter([$slug, $id], static fn (?string $n): bool => $n !== null));

        return $marker === [] ? $name : $name.' ('.implode(' / ', $marker).')';
    }

    /**
     * `Nama (id)`, atau salah satunya kalau yang lain tidak ada.
     *
     * Bentuk ini yang membuat laporan bisa dibaca dan sekaligus ditindaklanjuti: namanya untuk
     * mengerti, id-nya untuk mencari barisnya di database tanpa menebak.
     */
    private static function joinNamed(?string $name, ?string $id): ?string
    {
        if ($name === null) {
            return $id;
        }

        return $id === null ? $name : $name.' ('.$id.')';
    }

    private static function joinUser(?string $id, ?string $name): ?string
    {
        if ($name === null) {
            return $id;
        }

        return $id === null ? $name : $name.' ('.$id.')';
    }

    private static function location(Throwable $error): string
    {
        return $error->getFile().':'.$error->getLine();
    }

    /**
     * Baris berlabel. Field yang tidak tersedia ditandai `-`, tidak dihilangkan — pembaca
     * laporan perlu bisa membedakan "tidak ada tenant pada permintaan ini" dari "bagian ini
     * lupa ditulis".
     */
    private static function pair(string $label, ?string $value): string
    {
        return sprintf('%-9s: %s', $label, $value ?? '-');
    }

    private static function requestAttribute(Request $request, string $key): ?string
    {
        try {
            return self::text($request->attributes->get($key));
        } catch (Throwable) {
            return null;
        }
    }

    private static function text(mixed $value): ?string
    {
        if ($value === null || is_array($value) || is_object($value)) {
            return null;
        }

        $text = trim((string) $value);

        return $text === '' ? null : $text;
    }

    private static function truncate(string $value, int $limit): string
    {
        if (mb_strlen($value) <= $limit) {
            return $value;
        }

        return mb_substr($value, 0, $limit).' … (dipotong)';
    }

    /** Dipakai {@see ErrorReporter} untuk memutuskan sebuah kesalahan layak dilaporkan. */
    public static function isReportable(Throwable $error): bool
    {
        // 404, 419, dan 422 bukan kesalahan internal. Membiarkannya masuk berarti mengubur
        // laporan yang benar-benar berarti di bawah derasnya lalu lintas biasa — dan laporan
        // yang tidak pernah dibaca sama nilainya dengan laporan yang tidak pernah ditulis.
        if ($error instanceof HttpExceptionInterface) {
            $status = $error->getStatusCode();

            return $status < 400 || $status >= 500;
        }

        return true;
    }

    /** Dipakai {@see self::dari()} dan oleh test; dipublikkan supaya perilakunya bisa diuji. */
    public static function isDatabaseFailure(Throwable $error): bool
    {
        return self::involvesDatabase($error);
    }
}
