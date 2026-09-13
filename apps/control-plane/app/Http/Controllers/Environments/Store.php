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
        ], [
            'expires_at.required_if' => 'Lingkungan demo wajib punya tanggal berakhir.',
            'expires_at.after' => 'Tanggal berakhir harus setelah hari ini.',
        ]);

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
            );
        } catch (EnvironmentRejected $rejected) {
            // Dipulangkan sebagai kesalahan formulir, bukan halaman 500. Keduanya berarti
            // "permintaanmu ditolak", tetapi hanya yang pertama yang memberi tahu sebabnya.
            throw ValidationException::withMessages(['name' => $rejected->getMessage()]);
        }

        return redirect('/lingkungan/'.$environment->id)
            ->with('message', 'Lingkungan "'.$environment->name.'" tercatat. Sekarang siapkan databasenya.');
    }
}
