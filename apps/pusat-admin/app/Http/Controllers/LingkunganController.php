<?php

declare(strict_types=1);

namespace PusatAdmin\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response as HalamanInertia;
use PusatAdmin\Lingkungan\BuatLingkungan;
use PusatAdmin\Lingkungan\LingkunganDitolak;
use PusatAdmin\Models\Lingkungan;
use PusatAdmin\Models\OperasiLingkungan;
use PusatAdmin\Models\Tenant;
use PusatAdmin\Models\User;

class LingkunganController extends Controller
{
    /** Jenis yang dikenal registry. Sama persis dengan CHECK `environments_kind_dikenal`. */
    private const JENIS = ['production', 'sandbox', 'demo'];

    public function index(): HalamanInertia
    {
        $daftar = Lingkungan::query()
            ->with('tenant:id,name,slug')
            ->whereNull('deleted_at')
            ->orderBy('tenant_id')
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (Lingkungan $l): array => [
                'id' => $l->id,
                'nama' => $l->name,
                'slug' => $l->slug,
                'jenis' => $l->kind,
                'status' => $l->status,
                'keluar' => $l->outbound_allowed,
                'database' => $l->database(),
                'berakhir' => $l->expires_at?->toDateString(),
                'tenant' => $l->tenant->name ?? 'Tanpa tenant',
            ])
            ->all();

        return Inertia::render('lingkungan/daftar', [
            'daftar' => $daftar,
            'tenant' => $this->pilihanTenant(),
            'jenis' => self::JENIS,
        ]);
    }

    public function show(string $lingkungan): HalamanInertia
    {
        $baris = Lingkungan::query()
            ->with('tenant:id,name,slug')
            ->whereKey($lingkungan)
            ->firstOrFail();

        $riwayat = $baris->operasi()
            ->with('pemesan:id,name')
            ->orderByDesc('started_at')
            ->limit(50)
            ->get()
            ->map(fn (OperasiLingkungan $o): array => [
                'id' => $o->id,
                'operasi' => $o->operation,
                'status' => $o->status,
                'langkah' => $o->step,
                'alasan' => $o->failure_message,
                'mulai' => $o->started_at->toDateTimeString(),
                'selesai' => $o->finished_at?->toDateTimeString(),
                'oleh' => $o->pemesan->name ?? 'Sistem',
            ])
            ->all();

        return Inertia::render('lingkungan/rincian', [
            'lingkungan' => [
                'id' => $baris->id,
                'nama' => $baris->name,
                'slug' => $baris->slug,
                'jenis' => $baris->kind,
                'status' => $baris->status,
                'keluar' => $baris->outbound_allowed,
                'database' => $baris->database(),
                'databaseSendiri' => $baris->database_name !== null,
                'berakhir' => $baris->expires_at?->toDateString(),
                'tenant' => $baris->tenant->name ?? 'Tanpa tenant',
                'dibuat' => $baris->created_at?->toDateTimeString(),
            ],
            'riwayat' => $riwayat,
        ]);
    }

    public function store(Request $request, BuatLingkungan $buat): RedirectResponse
    {
        $isian = $request->validate([
            'tenant_id' => ['required', 'string', 'exists:tenants,id'],
            'jenis' => ['required', 'string', 'in:'.implode(',', self::JENIS)],
            'nama' => ['required', 'string', 'max:100'],
            'berakhir' => ['nullable', 'date', 'after:today', 'required_if:jenis,demo'],
        ], [
            'berakhir.required_if' => 'Lingkungan demo wajib punya tanggal berakhir.',
            'berakhir.after' => 'Tanggal berakhir harus setelah hari ini.',
        ]);

        $pengguna = $request->user();
        abort_unless($pengguna instanceof User, 403);

        $tenant = Tenant::query()->whereKey($isian['tenant_id'])->firstOrFail();

        try {
            $lingkungan = $buat(
                $tenant,
                $isian['jenis'],
                $isian['nama'],
                isset($isian['berakhir']) ? Carbon::parse($isian['berakhir']) : null,
                $pengguna->id,
            );
        } catch (LingkunganDitolak $ditolak) {
            throw ValidationException::withMessages(['nama' => $ditolak->getMessage()]);
        }

        return redirect('/lingkungan/'.$lingkungan->id)
            ->with('pesan', 'Lingkungan "'.$lingkungan->name.'" tercatat. Ia belum dapat dimasuki sampai databasenya disiapkan.');
    }

    /** @return list<array{id: string, nama: string}> */
    private function pilihanTenant(): array
    {
        return array_values(
            Tenant::query()
                ->orderBy('name')
                ->get(['id', 'name'])
                ->map(fn (Tenant $t): array => ['id' => $t->id, 'nama' => $t->name])
                ->all()
        );
    }
}
