<?php

declare(strict_types=1);

namespace ControlPlane\Http\Controllers\Lingkungan;

use ControlPlane\Http\Controllers\Controller;
use ControlPlane\Lingkungan\BuatLingkungan;
use ControlPlane\Lingkungan\LingkunganDitolak;
use ControlPlane\Models\Lingkungan;
use ControlPlane\Models\Tenant;
use ControlPlane\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

class Simpan extends Controller
{
    public function __invoke(Request $request, BuatLingkungan $buat): RedirectResponse
    {
        $isian = $request->validate([
            'tenant_id' => ['required', 'string', 'exists:tenants,id'],
            'jenis' => ['required', 'string', 'in:'.implode(',', Lingkungan::JENIS)],
            'nama' => ['required', 'string', 'max:100'],
            // Demo tanpa tanggal berakhir adalah bug yang tidak disadari siapa pun sampai disknya
            // penuh. Database menolaknya juga; di sini hanya supaya operator melihat sebabnya.
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
            // Dipulangkan sebagai kesalahan formulir, bukan halaman 500. Keduanya berarti
            // "permintaanmu ditolak", tetapi hanya yang pertama yang memberi tahu sebabnya.
            throw ValidationException::withMessages(['nama' => $ditolak->getMessage()]);
        }

        return redirect('/lingkungan/'.$lingkungan->id)
            ->with('pesan', 'Lingkungan "'.$lingkungan->name.'" tercatat. Sekarang siapkan databasenya.');
    }
}
