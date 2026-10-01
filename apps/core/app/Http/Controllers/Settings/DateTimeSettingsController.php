<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\DateTimeSettingsRequest;
use App\Models\User;
use App\Platform\Environment\Support\CurrentWorkspace;
use App\Support\UserClock;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;

/**
 * Zona waktu dan tanggal kerja pengguna, padanan *Time Zone* dan *Work Date* di My Settings Business
 * Central (area 7 TODO analisa gap BC fase 1, K-04 dan K-10).
 *
 * Zona waktu tersimpan di akun pengguna. Tanggal kerja hanya hidup selama sesi dan menjadi tanggal bawaan
 * transaksi baru. Keduanya kembali ke halaman asal karena diubah dari dua tempat: My Profile dan pengingat
 * di Shell.
 */
class DateTimeSettingsController extends Controller
{
    public function update(DateTimeSettingsRequest $request, CurrentWorkspace $workspace, UserClock $clock): RedirectResponse
    {
        $user = $request->user();
        if ($user instanceof User && $request->has('timezone')) {
            $zone = $request->validated('timezone');
            $user->forceFill(['timezone' => is_string($zone) ? $zone : null])->save();
        }

        if ($request->has('work_date')) {
            $date = $request->validated('work_date');
            // Tanggal kerja yang sama dengan hari ini disimpan sebagai "hari ini", bukan tanggalnya:
            // besok ia harus ikut bergeser, bukan tertinggal di tanggal kemarin.
            $workspace->setWorkDate($request, is_string($date) && $date !== $clock->today($request) ? $date : null);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Tanggal dan waktu disimpan.']);

        return back();
    }

    public function dismissWorkDateNotice(Request $request, CurrentWorkspace $workspace): RedirectResponse
    {
        $workspace->dismissWorkDateNotice($request);

        return back();
    }
}
