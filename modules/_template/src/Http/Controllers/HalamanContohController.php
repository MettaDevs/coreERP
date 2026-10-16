<?php

declare(strict_types=1);

namespace Modules\PenerbitContoh\ChangeMe\Http\Controllers;

use App\Platform\Modules\Contracts\RequestContext;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Modules\PenerbitContoh\ChangeMe\Models\Contoh;

/**
 * Layar module, bukan endpoint JSON: layar dan datanya datang dalam satu jawaban dari proses
 * yang sama. Tidak ada iframe, tidak ada aplikasi React kedua, dan tidak ada token yang perlu
 * dipertukarkan lebih dulu.
 *
 * Nama halaman berbentuk `<id module>::<berkas>` dan diselesaikan pemilih halaman shell ke
 * `modules/penerbit-contoh/change-me/ui/Pages/Daftar.tsx`. Penerbit tidak ikut disebut; id
 * module sudah unik di seluruh runtime.
 *
 * Dua hal yang menjadi pola, bukan kebetulan:
 *
 * 1. **Izin diperiksa lewat `RequestContext`, bukan dibaca dari permintaan.** Konteksnya
 *    diisi middleware `konteks-module` yang dipasang pada grup rute module.
 * 2. **`tenant_id` tidak disebut satu kali pun di sini.** `BelongsToTenant` pada modelnya yang
 *    menyaring, dan penyaringan itu gagal-menutup: tanpa tenant aktif, query dibatalkan
 *    alih-alih dijalankan tanpa saringan. Menuliskan `where('tenant_id', ...)` sendiri hanya
 *    menambah tempat yang bisa lupa ditulis.
 */
final class HalamanContohController
{
    public function __invoke(Request $request, RequestContext $context): Response
    {
        abort_unless($context->hasPermission('change-me.contoh.read'), 403);

        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:200'],
            'sort' => ['nullable', Rule::in(['kode', 'nama'])],
            'direction' => ['nullable', Rule::in(['asc', 'desc'])],
        ]);
        $query = Contoh::query();
        if ($keyword = trim((string) ($filters['q'] ?? ''))) {
            $pattern = '%'.addcslashes(mb_strtolower($keyword), '\\%_').'%';
            $query->where(fn ($match) => $match->whereRaw('LOWER(kode) LIKE ?', [$pattern])->orWhereRaw('LOWER(nama) LIKE ?', [$pattern]));
        }
        $query->orderBy($filters['sort'] ?? 'kode', $filters['direction'] ?? 'asc')->orderBy('id');

        return Inertia::render('change-me::Daftar', [
            'contoh' => Inertia::scroll($query->simplePaginate(40, ['id', 'kode', 'nama']))->matchOn('data.id'),
            'filters' => $filters,
        ]);
    }
}
