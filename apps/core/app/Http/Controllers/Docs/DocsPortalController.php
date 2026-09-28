<?php

namespace App\Http\Controllers\Docs;

use App\Http\Controllers\Controller;
use App\Models\CoreApp;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use JsonException;
use RuntimeException;

/**
 * Portal dokumentasi API di `/docs`, satu tampilan (Scalar) untuk beberapa spesifikasi.
 *
 * Daftarnya dibaca dari `contracts/terbit/katalog.json`, yang ditulis `contracts/bundle.py` dari
 * `x-portal` tiap akar kontrak. Satu domain integrasi satu spesifikasi — finance hari ini, domain
 * lain kelak — supaya tim luar hanya membaca bagian yang ia pakai. Domain baru muncul di sini
 * begitu akarnya dirakit, tanpa menyunting kelas ini.
 *
 * **Spesifikasi integrasi terbit tanpa login.** Pembacanya tim di luar CoreERP yang tidak punya
 * akun di tenant mana pun, dan isinya tidak memuat rahasia: token tetap hanya dapat dibuat admin
 * tenant. Yang lain tetap tertutup, dijaga gate `viewApiDocs` (admin penyedia), atau terbuka di
 * mesin pengembang: kontrak app module dan pusat admin, referensi Scramble untuk layar CoreERP
 * (`/api/v1`), dan kontrak app di katalog.
 */
final class DocsPortalController extends Controller
{
    public function __invoke(Request $request): View
    {
        $internal = $this->bolehInternal();
        $spesifikasi = collect($this->katalog())
            ->filter(fn (array $kontrak): bool => $kontrak['publik'] || $internal)
            ->map(fn (array $kontrak): array => [
                'id' => $kontrak['id'],
                'name' => $kontrak['judul'],
                'url' => route('docs.kontrak', $kontrak['id']),
            ])
            ->values();

        if ($internal) {
            $spesifikasi->push([
                'id' => 'control-plane',
                'name' => 'Layar CoreERP (internal)',
                'url' => route('scramble.docs.document'),
            ]);
            CoreApp::query()->where('status', 'available')->whereNotNull('contract_url')->orderBy('name')->get()
                ->each(fn (CoreApp $app) => $spesifikasi->push([
                    'id' => $app->id,
                    'name' => $app->name,
                    'url' => route('docs.openapi', $app->id),
                ]));
        }

        abort_if($spesifikasi->isEmpty(), 404);
        $selected = $spesifikasi->firstWhere('id', $request->query('spec')) ?? $spesifikasi->first();

        return view('api-portal', ['specifications' => $spesifikasi->all(), 'selected' => $selected]);
    }

    public function kontrak(string $spesifikasi): Response
    {
        $kontrak = collect($this->katalog())->firstWhere('id', $spesifikasi) ?? abort(404);
        abort_unless($kontrak['publik'] || $this->bolehInternal(), 403);
        $berkas = base_path('contracts/terbit/'.$kontrak['berkas']);
        abort_unless(is_file($berkas), 404);

        return response((string) file_get_contents($berkas), 200, [
            'Content-Type' => 'application/yaml; charset=utf-8',
            // Berkasnya ikut rilis dan tidak berubah di antara dua rilis.
            'Cache-Control' => $kontrak['publik'] ? 'public, max-age=300' : 'private, no-store',
        ]);
    }

    /**
     * Katalog ikut rilis bersama kontraknya. Berkas yang hilang atau rusak berarti rakitan yang
     * salah, bukan "tidak ada dokumentasi" — jadi dilempar, tidak dijawab sebagai daftar kosong.
     *
     * @return list<array{id: string, judul: string, publik: bool, berkas: string}>
     *
     * @throws JsonException
     */
    private function katalog(): array
    {
        $berkas = base_path('contracts/terbit/katalog.json');
        if (! is_file($berkas)) {
            throw new RuntimeException('Katalog kontrak tidak ada: jalankan `python contracts/bundle.py` lalu commit hasilnya.');
        }
        $isi = json_decode((string) file_get_contents($berkas), true, 8, JSON_THROW_ON_ERROR);
        if (! is_array($isi)) {
            throw new RuntimeException('Katalog kontrak bukan daftar.');
        }

        $hasil = [];
        foreach ($isi as $baris) {
            if (! is_array($baris)
                || ! is_string($baris['id'] ?? null)
                || ! is_string($baris['judul'] ?? null)
                || ! is_bool($baris['publik'] ?? null)
                || ! is_string($baris['berkas'] ?? null)
                || preg_match('/^[a-z0-9-]+\.yaml$/', $baris['berkas']) !== 1) {
                throw new RuntimeException('Katalog kontrak memuat baris yang tidak sah.');
            }
            $hasil[] = ['id' => $baris['id'], 'judul' => $baris['judul'], 'publik' => $baris['publik'], 'berkas' => $baris['berkas']];
        }

        return $hasil;
    }

    /** Sama dengan penjaga Scramble: mesin pengembang, atau admin penyedia lewat `viewApiDocs`. */
    private function bolehInternal(): bool
    {
        return app()->environment('local') || Gate::allows('viewApiDocs');
    }
}
