<?php

declare(strict_types=1);

namespace App\Http\Controllers\Internal;

use App\Http\Controllers\Controller;
use App\Jobs\UpgradeEnvironment as UpgradeEnvironmentJob;
use App\Models\Environment;
use App\Models\EnvironmentOperation;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Keadaan seluruh armada dalam satu jawaban, dan satu pintu untuk memperbaruinya.
 *
 * ## Kenapa satu panggilan, bukan satu per lingkungan
 *
 * Karena yang dibaca layar adalah **perbandingan**: versi tiap lingkungan terhadap versi image yang
 * sedang berjalan. Menghitungnya per baris berarti konsol harus tahu versi image, dan versi image
 * adalah pengetahuan Core — ia diturunkan dari isi folder migration, yang tidak ada di container
 * konsol.
 *
 * Azure menyebut kewajibannya sebagai kewajiban control plane: *"You need to know which version of
 * infrastructure, software, or feature each tenant uses, what they're eligible to migrate to, and
 * the time-based data associated with those states."* Bagi kita "boleh naik ke mana" cuma satu
 * jawaban — versi image — jadi ia dikirim sekali di kepala jawaban, bukan diulang per baris.
 *
 * ## Ia tidak membuka satu pun database lingkungan
 *
 * Seluruh isi jawaban ini dibaca dari tabel sisi pusat: `environments` menyimpan sidik skema tiap
 * lingkungan, dan `environment_operations` menyimpan riwayatnya. Itu yang membuat layarnya tetap
 * murah pada dua ratus lingkungan — daftar yang membuka dua ratus koneksi untuk menggambar satu
 * tabel adalah daftar yang berhenti dibuka orang.
 *
 * Daftar module per lingkungan memang menuntut database lingkungannya, dan karena itu ia tinggal di
 * layar rincian satu lingkungan, bukan di daftar armada.
 */
final class FleetController extends Controller
{
    public function index(): JsonResponse
    {
        $platform = $this->platformFingerprint();
        $central = $this->fingerprintOf((string) config('database.default'));
        $latest = $this->latestOperations();

        $rows = [];
        $counts = ['current' => 0, 'behind' => 0, 'failed' => 0, 'unknown' => 0];

        foreach ($this->environments() as $environment) {
            $own = is_string($environment->database_name) && $environment->database_name !== '';
            $fingerprint = $own ? $environment->schema_fingerprint : $central;
            $operation = $latest[$environment->id] ?? null;

            $state = $this->stateOf($environment, $fingerprint, $platform, $operation);
            $counts[$state]++;

            $rows[] = [
                'id' => $environment->id,
                'tenant' => $environment->tenant->name ?? '—',
                'name' => $environment->name,
                'slug' => $environment->slug,
                'kind' => $environment->kind,
                'status' => $environment->status,
                'database' => $own ? $environment->database_name : null,
                'fingerprint' => $fingerprint,
                'state' => $state,
                'last_operation' => $operation === null ? null : [
                    'kind' => $operation->operation,
                    'status' => $operation->status,
                    'step' => $operation->step,
                    'reason' => $operation->failure_message,
                    'started_at' => $operation->started_at->toDateTimeString(),
                    'finished_at' => $operation->finished_at?->toDateTimeString(),
                    // Boleh kosong, dan itu bukan data hilang: sebuah operasi memang dapat dimulai
                    // penjadwal, bukan manusia.
                    'requested_by' => $operation->requester->name ?? null,
                ],
            ];
        }

        return response()->json([
            'platform_fingerprint' => $platform,
            'counts' => $counts,
            'environments' => $rows,
        ]);
    }

    /**
     * Mengantrekan pembaruan — satu lingkungan, atau semua yang tertinggal.
     *
     * Yang diantrekan hanya yang **perlu**: lingkungan yang sidiknya sudah sama dengan image dan
     * operasi terakhirnya berhasil dilewati. Mengantrekan semuanya setiap kali tombolnya ditekan
     * berarti dua ratus job yang seluruhnya tidak melakukan apa pun, dan operator kehilangan
     * kemampuan membaca "berapa yang sedang berjalan" karena angkanya selalu penuh.
     *
     * `force` menimpanya, dan ia ada untuk satu keadaan nyata: migration yang isinya diubah tanpa
     * mengubah nama berkasnya. Sidiknya tidak bergerak, tetapi databasenya memang tertinggal.
     */
    public function upgrade(Request $request, ?string $environmentId = null): JsonResponse
    {
        $platform = $this->platformFingerprint();
        $central = $this->fingerprintOf((string) config('database.default'));
        $latest = $this->latestOperations();
        $force = $request->boolean('force');
        $requestedBy = $this->requestedBy($request);

        $queued = [];

        foreach ($this->environments() as $environment) {
            if ($environmentId !== null && $environment->id !== $environmentId) {
                continue;
            }

            if (! in_array($environment->status, ['active', 'maintenance', 'degraded'], true)) {
                continue;
            }

            $own = is_string($environment->database_name) && $environment->database_name !== '';
            $fingerprint = $own ? $environment->schema_fingerprint : $central;
            $state = $this->stateOf($environment, $fingerprint, $platform, $latest[$environment->id] ?? null);

            if (! $force && $state === 'current') {
                continue;
            }

            UpgradeEnvironmentJob::dispatch($environment->id, $requestedBy);
            $queued[] = $environment->id;
        }

        if ($environmentId !== null && $queued === [] && ! $this->exists($environmentId)) {
            return response()->json(['message' => 'Lingkungan itu tidak ada di registry.'], 404);
        }

        return response()->json([
            'queued' => $queued,
            // Disebut apa adanya supaya layar dapat mengatakan "tidak ada yang perlu diperbarui"
            // alih-alih diam — diam terbaca seperti tombol yang tidak bekerja.
            'queued_count' => count($queued),
        ], 202);
    }

    /**
     * Keadaan yang dibaca operator, bukan sidiknya.
     *
     * Sidik skema adalah nama berkas migration sepanjang lima puluh karakter: jawaban yang benar
     * untuk mesin dan jawaban yang tidak terbaca untuk manusia. Yang diturunkan di sini satu kata
     * yang dapat dijadikan lencana.
     *
     * @return 'current'|'behind'|'failed'|'unknown'
     */
    private function stateOf(
        Environment $environment,
        ?string $fingerprint,
        string $platform,
        ?EnvironmentOperation $operation,
    ): string {
        if (in_array($environment->status, ['maintenance', 'degraded'], true)) {
            return 'failed';
        }

        if ($operation !== null && $operation->status === 'running') {
            // Sedang dikerjakan. Bukan tertinggal dan bukan mutakhir — dan membedakannya penting,
            // karena tombol "Perbarui" pada baris yang sedang berjalan akan ditolak kunci operasinya
            // dan terbaca seperti kegagalan.
            return 'unknown';
        }

        if ($fingerprint === null || $fingerprint === '') {
            // Belum pernah punya skema sama sekali. Itu bukan "tertinggal" — ia belum pernah ada.
            return 'unknown';
        }

        return $fingerprint === $platform ? 'current' : 'behind';
    }

    /** @return list<Environment> */
    private function environments(): array
    {
        /** @var list<Environment> $rows */
        $rows = Environment::query()
            ->with('tenant:id,name,slug')
            ->whereNull('deleted_at')
            ->orderBy('tenant_id')
            ->orderBy('kind')
            ->get()
            ->all();

        return $rows;
    }

    private function exists(string $environmentId): bool
    {
        return Environment::query()->whereKey($environmentId)->whereNull('deleted_at')->exists();
    }

    /**
     * Operasi terakhir tiap lingkungan, dalam satu query.
     *
     * `DISTINCT ON` milik PostgreSQL, bukan satu query per baris. Yang kedua terbaca lebih sederhana
     * dan berubah menjadi dua ratus query pada dua ratus lingkungan — dan halaman yang lambat adalah
     * halaman yang berhenti dibuka orang, yang berarti armadanya berhenti dipantau.
     *
     * @return array<string, EnvironmentOperation>
     */
    private function latestOperations(): array
    {
        $ids = DB::connection((new EnvironmentOperation)->getConnectionName())
            ->table('environment_operations')
            ->selectRaw('distinct on (environment_id) id')
            ->orderBy('environment_id')
            ->orderByDesc('started_at')
            ->pluck('id')
            ->all();

        if ($ids === []) {
            return [];
        }

        return EnvironmentOperation::query()
            ->with('requester:id,name')
            ->whereIn('id', $ids)
            ->get()
            ->keyBy('environment_id')
            ->all();
    }

    /**
     * Sidik image yang sedang berjalan: nama berkas migration terakhir di dalamnya.
     *
     * Dibaca dari folder, bukan dari database, dan itu bedanya dengan sidik lingkungan. Yang ini
     * menjawab "boleh naik ke mana"; yang itu menjawab "sekarang di mana". Membacanya dari database
     * mana pun akan membuat keduanya selalu sama, dan perbandingannya berhenti berarti apa pun.
     */
    private function platformFingerprint(): string
    {
        $files = glob(database_path('migrations').'/*.php') ?: [];
        $names = array_map(static fn (string $path): string => basename($path, '.php'), $files);
        sort($names);

        return (string) end($names);
    }

    private function fingerprintOf(string $connection): ?string
    {
        // Urut nama berkas, bukan urut `id`. Alasannya di `CopyEnvironment::schemaFingerprint()`:
        // urutan penerapan tidak sama dengan urutan nama, dan sidik dari `id` melaporkan setiap
        // lingkungan tertinggal selamanya.
        $row = DB::connection($connection)->table('migrations')->orderByDesc('migration')->first();

        return is_object($row) && property_exists($row, 'migration') ? (string) $row->migration : null;
    }

    /**
     * Id operator yang menekan tombolnya, bila ia benar-benar ada sebagai user di sini.
     *
     * Diperiksa keberadaannya lebih dulu karena kolomnya berkunci asing ke `users`: id yang tidak
     * ada akan menjatuhkan **pembaruannya** demi sebuah nama di kolom riwayat.
     */
    private function requestedBy(Request $request): ?int
    {
        $id = $request->input('requested_by');

        if (! is_int($id) && ! (is_string($id) && $id !== '' && ctype_digit($id))) {
            return null;
        }

        $id = (int) $id;

        return User::query()->whereKey($id)->exists() ? $id : null;
    }
}
