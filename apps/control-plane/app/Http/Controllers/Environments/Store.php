<?php

declare(strict_types=1);

namespace ControlPlane\Http\Controllers\Environments;

use ControlPlane\Environments\CreateEnvironment;
use ControlPlane\Environments\EnvironmentRejected;
use ControlPlane\Http\Controllers\Controller;
use ControlPlane\Models\Environment;
use ControlPlane\Models\Tenant;
use ControlPlane\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

class Store extends Controller
{
    public function __invoke(Request $request, CreateEnvironment $create): RedirectResponse
    {
        $input = $request->validate([
            'tenant_id' => ['required', 'string', 'exists:tenants,id'],
            'kind' => ['required', 'string', 'in:'.implode(',', Environment::KINDS)],
            'name' => ['required', 'string', 'max:100'],
            // Demo tanpa tanggal berakhir adalah bug yang tidak disadari siapa pun sampai disknya
            // penuh. Database menolaknya juga; di sini hanya supaya operator melihat sebabnya.
            'expires_at' => ['nullable', 'date', 'after:today', 'required_if:kind,demo'],
            // Boleh tidak dikirim: formulir lama dan setiap pemanggil yang tidak menyebutnya tetap
            // melahirkan lingkungan di server kita, persis seperti sebelum pilihan ini ada.
            'hosting' => ['nullable', 'string', 'in:'.implode(',', Environment::HOSTINGS)],
        ], [
            'expires_at.required_if' => 'Lingkungan demo wajib punya tanggal berakhir.',
            'expires_at.after' => 'Tanggal berakhir harus setelah hari ini.',
            'hosting.in' => 'Pilih server kita atau server klien.',
        ]);

        $hosting = is_string($input['hosting'] ?? null) ? $input['hosting'] : 'provider';

        $user = $request->user();
        abort_unless($user instanceof User, 403);

        $tenant = Tenant::query()->whereKey($input['tenant_id'])->firstOrFail();

        try {
            $environment = $create(
                $tenant,
                $input['kind'],
                $input['name'],
                isset($input['expires_at']) ? Carbon::parse($input['expires_at']) : null,
                $user->id,
                $hosting,
            );
        } catch (EnvironmentRejected $rejected) {
            // Dipulangkan sebagai kesalahan formulir, bukan halaman 500. Keduanya berarti
            // "permintaanmu ditolak", tetapi hanya yang pertama yang memberi tahu sebabnya.
            throw ValidationException::withMessages([$hosting === 'client_server' && $input['kind'] !== 'production' ? 'hosting' : 'name' => $rejected->getMessage()]);
        }

        // Langkah berikutnya berbeda, dan kalimatnya menyebut yang benar. "Siapkan databasenya" untuk
        // produksi di server klien menyuruh operator mencari tombol yang memang tidak ada.
        return redirect('/lingkungan/'.$environment->id)
            ->with('message', 'Lingkungan "'.$environment->name.'" tercatat. '.($environment->hosting === 'client_server'
                ? 'Sekarang siapkan server kliennya.'
                : 'Sekarang siapkan databasenya.'));
    }
}
