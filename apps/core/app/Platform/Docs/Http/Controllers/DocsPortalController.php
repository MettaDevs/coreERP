<?php

namespace App\Platform\Docs\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Platform\Modules\Models\CoreApp;
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
        $internal = $this->mayReadInternal();
        $specification = collect($this->catalog())
            ->filter(fn (array $contract): bool => $contract['publik'] || $internal)
            ->map(fn (array $contract): array => [
                'id' => $contract['id'],
                'name' => $contract['judul'],
                'url' => route('docs.kontrak', $contract['id']),
            ])
            ->values();

        if ($internal) {
            $specification->push([
                'id' => 'control-plane',
                'name' => 'Layar CoreERP (internal)',
                'url' => route('scramble.docs.document'),
            ]);
            CoreApp::query()->where('status', 'available')->whereNotNull('contract_url')->orderBy('name')->get()
                ->each(fn (CoreApp $app) => $specification->push([
                    'id' => $app->id,
                    'name' => $app->name,
                    'url' => route('docs.openapi', $app->id),
                ]));
        }

        abort_if($specification->isEmpty(), 404);
        $selected = $specification->firstWhere('id', $request->query('spec')) ?? $specification->first();

        return view('api-portal', ['specifications' => $specification->all(), 'selected' => $selected]);
    }

    public function contract(string $specification): Response
    {
        $contract = collect($this->catalog())->firstWhere('id', $specification) ?? abort(404);
        abort_unless($contract['publik'] || $this->mayReadInternal(), 403);
        $file = base_path('contracts/terbit/'.$contract['berkas']);
        abort_unless(is_file($file), 404);

        return response((string) file_get_contents($file), 200, [
            'Content-Type' => 'application/yaml; charset=utf-8',
            // Berkasnya ikut rilis dan tidak berubah di antara dua rilis.
            'Cache-Control' => $contract['publik'] ? 'public, max-age=300' : 'private, no-store',
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
    private function catalog(): array
    {
        $file = base_path('contracts/terbit/katalog.json');
        if (! is_file($file)) {
            throw new RuntimeException('Katalog kontrak tidak ada: jalankan `python contracts/bundle.py` lalu commit hasilnya.');
        }
        $content = json_decode((string) file_get_contents($file), true, 8, JSON_THROW_ON_ERROR);
        if (! is_array($content)) {
            throw new RuntimeException('Katalog kontrak bukan daftar.');
        }

        $result = [];
        foreach ($content as $row) {
            if (! is_array($row)
                || ! is_string($row['id'] ?? null)
                || ! is_string($row['judul'] ?? null)
                || ! is_bool($row['publik'] ?? null)
                || ! is_string($row['berkas'] ?? null)
                || preg_match('/^[a-z0-9-]+\.yaml$/', $row['berkas']) !== 1) {
                throw new RuntimeException('Katalog kontrak memuat baris yang tidak sah.');
            }
            $result[] = ['id' => $row['id'], 'judul' => $row['judul'], 'publik' => $row['publik'], 'berkas' => $row['berkas']];
        }

        return $result;
    }

    /** Sama dengan penjaga Scramble: mesin pengembang, atau admin penyedia lewat `viewApiDocs`. */
    private function mayReadInternal(): bool
    {
        return app()->environment('local') || Gate::allows('viewApiDocs');
    }
}
