<?php

namespace Modules\Apperp\HumanResources\Http\Controllers;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Modules\Apperp\HumanResources\Models\Job;
use Modules\Apperp\HumanResources\Models\Position;
use Modules\Apperp\HumanResources\Models\Worker;
use Modules\Apperp\HumanResources\Models\WorkerPositionAssignment;
use Modules\Apperp\HumanResources\Services\DirektoriHr;
use Modules\Apperp\HumanResources\Services\PenerbitNomorHr;

/**
 * Seluruh endpoint tenaga kerja module ini.
 *
 * Sebelumnya setiap query di sini disusun dengan query builder mentah, dan penyaringan tenant
 * ditulis tangan sebagai `where('tenant_id', ...)` pada masing-masing. Selama tiap tenant punya
 * database sendiri, satu baris yang lupa ditulis tidak berakibat apa-apa. Di dalam satu
 * database bersama, ia memulangkan baris seluruh pelanggan — dan kegagalannya tidak pernah
 * terlihat sebagai kegagalan, hanya sebagai daftar yang isinya kebetulan banyak.
 *
 * Karena itu penyaringannya tidak lagi ditulis di sini sama sekali. Keempat modelnya memakai
 * `MilikTenant`, yang menyisipkan saringan pada setiap query **dan** membatalkan penyimpanan
 * baris milik tenant lain. Yang tidak perlu ditulis tidak bisa lupa ditulis.
 */
final class HumanResourcesController extends Controller
{
    public function operatingUnits(Request $request, DirektoriHr $core): JsonResponse
    {
        $this->requirePermission($request, 'positions', 'read');

        return response()->json([
            'data' => $core->operatingUnits($this->tenantId($request)),
        ]);
    }

    public function coreMembers(Request $request, DirektoriHr $core): JsonResponse
    {
        $this->requirePermission($request, 'core-account-link', 'invoke');
        $query = $request->validate(['q' => ['nullable', 'string', 'max:100']]);

        return response()->json([
            'data' => $core->members($this->tenantId($request), $query['q'] ?? ''),
        ]);
    }

    public function workers(Request $request): JsonResponse
    {
        $this->requirePermission($request, 'workers', 'read');

        $query = Worker::query()->orderBy('name');

        // Pengguna tanpa akses seluruh organisasi hanya melihat pekerja yang **sedang**
        // memegang posisi di unit kerjanya. Penugasan yang sudah berakhir tidak dihitung, dan
        // itu disengaja: yang ditanyakan layar ini adalah siapa yang menjadi tanggung
        // jawabnya hari ini, bukan siapa yang pernah.
        if (! $this->hasTenantWideScope($request)) {
            $query->whereHas('assignments', function (Builder $assignment) use ($request): void {
                $assignment
                    ->whereDate('valid_from', '<=', today())
                    ->where(fn (Builder $dates) => $dates->whereNull('valid_until')->orWhereDate('valid_until', '>=', today()))
                    ->whereHas('position', fn (Builder $position) => $position->whereIn('operating_unit_id', $this->operatingUnitIds($request)));
            });
        }

        return response()->json(['data' => $query->get()]);
    }

    public function jobs(Request $request): JsonResponse
    {
        $this->requirePermission($request, 'jobs', 'read');

        return response()->json(['data' => Job::query()->orderBy('name')->get()]);
    }

    public function positions(Request $request): JsonResponse
    {
        $this->requirePermission($request, 'positions', 'read');

        $unitKerja = $this->unitKerjaYangBolehDilihat($request);
        $query = Position::query()->orderBy('name');

        if ($unitKerja !== null) {
            $query->whereIn('operating_unit_id', $unitKerja);
        }

        return response()->json(['data' => $query->get()]);
    }

    public function assignments(Request $request): JsonResponse
    {
        $this->requirePermission($request, 'assignments', 'read');

        // `whereHas` dipasang tanpa syarat, bukan hanya ketika lingkupnya terbatas. Query lama
        // menggabungkan tabel posisi dengan join biasa, sehingga penugasan yang posisinya tidak
        // ada tidak pernah ikut terbaca siapa pun; menjadikan relasinya syarat hanya saat
        // lingkup terbatas akan diam-diam memunculkan baris tersebut bagi pengguna dengan
        // akses penuh.
        $unitKerja = $this->unitKerjaYangBolehDilihat($request);
        $query = WorkerPositionAssignment::query()
            ->whereHas('position', function (Builder $position) use ($unitKerja): void {
                if ($unitKerja !== null) {
                    $position->whereIn('operating_unit_id', $unitKerja);
                }
            })
            ->orderByDesc('valid_from');

        return response()->json(['data' => $query->get()]);
    }

    public function storeWorker(Request $request, PenerbitNomorHr $numbers, DirektoriHr $core): JsonResponse
    {
        $this->requirePermission($request, 'workers', 'create');
        $tenantId = $this->tenantId($request);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'email' => ['nullable', 'email', 'max:255'],
            'core_membership_id' => ['nullable', 'ulid'],
        ]);

        if ($data['core_membership_id'] ?? null) {
            $this->requirePermission($request, 'core-account-link', 'invoke');
            $core->member($tenantId, $data['core_membership_id']);
        }
        abort_if(! $this->hasTenantWideScope($request), 403, 'Pekerja tanpa posisi hanya dapat dibuat oleh pengguna dengan akses seluruh organisasi.');

        $idempotencyKey = $this->idempotencyKey($request);
        $existing = Worker::query()->where('creation_key', $idempotencyKey)->first();

        if ($existing) {
            return response()->json(['data' => $existing]);
        }

        $worker = DB::transaction(fn (): Worker => Worker::query()->create([
            'creation_key' => $idempotencyKey,
            'personnel_number' => $numbers->issue('human-resources.pekerja', $tenantId, 'worker:'.$idempotencyKey),
            ...$data,
        ]));

        return response()->json(['data' => $worker], 201);
    }

    public function storeJob(Request $request, PenerbitNomorHr $numbers): JsonResponse
    {
        $this->requirePermission($request, 'jobs', 'create');
        $tenantId = $this->tenantId($request);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'description' => ['nullable', 'string', 'max:2000'],
        ]);
        $idempotencyKey = $this->idempotencyKey($request);

        $existing = Job::query()->where('creation_key', $idempotencyKey)->first();

        if ($existing) {
            return response()->json(['data' => $existing]);
        }

        $job = DB::transaction(fn (): Job => Job::query()->create([
            'creation_key' => $idempotencyKey,
            'code' => $numbers->issue('human-resources.jabatan', $tenantId, 'job:'.$idempotencyKey),
            ...$data,
        ]));

        return response()->json(['data' => $job], 201);
    }

    public function storePosition(Request $request, PenerbitNomorHr $numbers): JsonResponse
    {
        $this->requirePermission($request, 'positions', 'create');
        $tenantId = $this->tenantId($request);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'job_id' => ['required', 'ulid'],
            'operating_unit_id' => ['required', 'ulid'],
            'valid_from' => ['required', 'date'],
            'valid_until' => ['nullable', 'date', 'after:valid_from'],
        ]);

        abort_unless(Job::query()->whereKey($data['job_id'])->exists(), 422, 'Jabatan tidak tersedia.');
        $this->requireOperatingUnit($request, $data['operating_unit_id']);

        $idempotencyKey = $this->idempotencyKey($request);

        $existing = Position::query()->where('creation_key', $idempotencyKey)->first();

        if ($existing) {
            return response()->json(['data' => $existing]);
        }

        $position = DB::transaction(fn (): Position => Position::query()->create([
            'creation_key' => $idempotencyKey,
            'code' => $numbers->issue('human-resources.posisi', $tenantId, 'position:'.$idempotencyKey),
            ...$data,
        ]));

        return response()->json(['data' => $position], 201);
    }

    public function storeAssignment(Request $request): JsonResponse
    {
        $this->requirePermission($request, 'assignments', 'create');
        $data = $request->validate([
            'worker_id' => ['required', 'ulid'],
            'position_id' => ['required', 'ulid'],
            'valid_from' => ['required', 'date'],
            'valid_until' => ['nullable', 'date', 'after:valid_from'],
            'is_primary' => ['required', 'boolean'],
        ]);

        $worker = Worker::query()->whereKey($data['worker_id'])->first();
        $position = Position::query()->whereKey($data['position_id'])->first();
        abort_unless($worker && $position, 422, 'Pekerja atau posisi tidak tersedia.');
        $this->requireOperatingUnit($request, $position->operating_unit_id);

        $positionIsFilled = WorkerPositionAssignment::query()
            ->where('position_id', $data['position_id'])
            ->where('valid_from', '<=', $data['valid_until'] ?? '9999-12-31')
            ->where(fn (Builder $query) => $query->whereNull('valid_until')->orWhere('valid_until', '>=', $data['valid_from']))
            ->exists();
        abort_if($positionIsFilled, 422, 'Posisi sudah terisi pada periode tersebut.');

        $assignment = WorkerPositionAssignment::query()->create($data);

        // **Yang hilang di sini, dan kenapa ia tidak diganti diam-diam.**
        //
        // Sampai 10 September 2026 baris berikutnya memanggil Core lewat HTTP ke
        // `human-resources/position-assignments`, yang menerapkan aturan role otomatis: pekerja
        // yang punya akun Core mendapat role beserta lingkup unit kerjanya begitu ia ditugaskan.
        // Jalur itu dibuang bersama dua klien HTTP lainnya, dan tidak ada penggantinya: seluruh
        // permukaan Core yang boleh dipanggil module terdaftar sebagai antarmuka pada namespace
        // kontrak Core, dan tidak satu pun di antaranya menyentuh penugasan role.
        //
        // Mempertahankan pemanggilan HTTP-nya bukan pilihan yang lebih aman, meskipun terlihat
        // begitu. Setelan `services.coreerp` ikut hilang bersama kerangka app lama, jadi
        // pemanggilan itu sekarang menembak alamat kosong dan **selalu** melempar — akibatnya
        // setiap penugasan untuk pekerja yang akunnya tertaut akan dijawab 500, bukan tersimpan.
        // Yang tersisa dari mempertahankannya hanyalah penjaga batas yang merah selamanya.
        //
        // Akibat yang sungguhan ditanggung: penugasan tetap tersimpan, tetapi role otomatisnya
        // tidak lagi ikut diperbarui, dan admin tenant harus menugaskan rolenya sendiri. Ini
        // dicatat sebagai perubahan perilaku, bukan sebagai perbaikan. Yang mengembalikannya
        // adalah sebuah antarmuka baru di Core — menerima id keanggotaan, id posisi, id unit
        // kerja, id penugasan, dan status aktif — bukan menghidupkan kembali kliennya.

        return response()->json(['data' => $assignment], 201);
    }

    private function tenantId(Request $request): string
    {
        return (string) $request->attributes->get('coreerp.tenant_id');
    }

    private function requirePermission(Request $request, string $resource, string $action): void
    {
        $permission = "human-resources.{$resource}.{$action}";
        abort_unless(in_array($permission, $request->attributes->get('coreerp.permissions', []), true), 403);
    }

    private function idempotencyKey(Request $request): string
    {
        return (string) $request->validate([
            'idempotency_key' => ['required', 'string', 'max:154', 'regex:/^[A-Za-z0-9._:-]+$/'],
        ])['idempotency_key'];
    }

    private function hasTenantWideScope(Request $request): bool
    {
        return (bool) ($this->policyScope($request)['all'] ?? false);
    }

    /**
     * Id unit kerja pada seluruh pemberian lingkup kebijakan, tanpa duplikat.
     *
     * Setiap lapisnya diperiksa bentuknya, bukan dipercaya. Isi kebijakan data datang dari
     * Core sebagai `mixed`, dan satu nilai yang bentuknya tidak seperti dugaan akan menjadi
     * `whereIn` yang isinya bukan id — yang berarti bukan nol baris melainkan baris yang
     * salah. Yang tidak dikenali dilewati, bukan diteruskan.
     *
     * @return list<string>
     */
    private function operatingUnitIds(Request $request): array
    {
        $grants = $this->policyScope($request)['scope_grants'] ?? null;

        if (! is_array($grants)) {
            return [];
        }

        $unit = [];

        foreach ($grants as $grant) {
            $ids = is_array($grant) ? ($grant['operating_unit_ids'] ?? null) : null;

            if (! is_array($ids)) {
                continue;
            }

            foreach ($ids as $id) {
                if (is_string($id)) {
                    $unit[$id] = true;
                }
            }
        }

        return array_keys($unit);
    }

    /**
     * Unit kerja yang boleh dilihat pengguna, atau `null` bila ia berhak atas semuanya.
     *
     * Memulangkan daftar, bukan menyaring query yang dioper masuk. Bedanya bukan gaya:
     * penyaringan dipakai pada dua tempat yang bentuknya berbeda — langsung pada daftar posisi,
     * dan dari dalam syarat relasi pada daftar penugasan — dan sebuah fungsi yang menerima
     * query harus bisa menerima kedua bentuk itu sekaligus. Sebagai daftar nilai, ia tidak
     * peduli pada bentuk query mana pun, dan aturannya tetap tinggal di satu tempat.
     *
     * `'__none__'` menahan `whereIn` kosong. Daftar kosong berarti pengguna tidak dipercayai
     * unit kerja mana pun, dan jawaban yang benar untuk itu adalah nol baris, bukan semuanya.
     *
     * @return list<string>|null
     */
    private function unitKerjaYangBolehDilihat(Request $request): ?array
    {
        if ($this->hasTenantWideScope($request)) {
            return null;
        }

        return $this->operatingUnitIds($request) ?: ['__none__'];
    }

    private function requireOperatingUnit(Request $request, string $operatingUnitId): void
    {
        abort_unless($this->hasTenantWideScope($request) || in_array($operatingUnitId, $this->operatingUnitIds($request), true), 403, 'Unit kerja ini berada di luar akses Anda.');
    }

    /** @return array<string, mixed> */
    private function policyScope(Request $request): array
    {
        $policies = $request->attributes->get('coreerp.data_policies', []);

        return is_array($policies) && is_array($policies['human-resources.workforce-responsibility'] ?? null)
            ? $policies['human-resources.workforce-responsibility']
            : [];
    }
}
