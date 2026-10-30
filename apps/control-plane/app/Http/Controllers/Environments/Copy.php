<?php

declare(strict_types=1);

namespace ControlPlane\Http\Controllers\Environments;

use ControlPlane\Environments\CopyViaCore;
use ControlPlane\Environments\EnvironmentRejected;
use ControlPlane\Http\Controllers\Controller;
use ControlPlane\Models\Environment;
use ControlPlane\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

final class Copy extends Controller
{
    public function __invoke(Request $request, string $environment, CopyViaCore $copy): RedirectResponse
    {
        $input = $request->validate(['name' => ['required', 'string', 'max:100']], [
            'name.required' => 'Isi nama sandbox.',
            'name.string' => 'Nama sandbox harus berupa teks.',
            'name.max' => 'Nama sandbox paling panjang 100 karakter.',
        ]);
        $operator = $request->user();
        abort_unless($operator instanceof User, 403);
        $source = Environment::query()->whereKey($environment)->whereNull('deleted_at')->firstOrFail();
        try {
            $target = $copy($source, $input['name'], $operator->id);
        } catch (EnvironmentRejected $error) {
            return redirect()->back()->withErrors(['copy' => $error->getMessage()]);
        }

        return redirect('/lingkungan/'.$target->id)->with('message', 'Penyalinan diantrekan. Riwayat akan diperbarui saat proses mulai berjalan.');
    }
}
