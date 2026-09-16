<?php

declare(strict_types=1);

namespace ControlPlane\Http\Controllers\Environments;

use ControlPlane\Http\Controllers\Controller;
use ControlPlane\Models\Environment;
use ControlPlane\Models\Site;
use ControlPlane\Sites\ClientServerSetup;
use ControlPlane\Sites\ServerSettings;
use ControlPlane\Sites\SiteRejected;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Tombol-tombol panel "Server klien" di halaman lingkungan produksi. Aturannya di `ClientServerSetup`.
 *
 * Penolakan dipulangkan sebagai galat bernama `server_client`, yang dirender panel sebagai spanduk di
 * atasnya — bukan halaman 500. Setiap penolakan di sana kalimat untuk operator.
 *
 * Tidak ada konfirmasi nama situs yang diketik ulang di sini, berbeda dari `SiteActions`. Tidak satu pun
 * tombol ini memerintah server yang sedang melayani: menyiapkan hanya mencatat, dan perintah pasang baru
 * berbuat sesuatu setelah teknisi menempelkannya di server klien. Kriteria terima PRD-nya pun menuntut
 * pemasangan tanpa satu isian yang wajib diketik.
 */
final class ClientServer extends Controller
{
    public function prepare(Request $request, string $environment, ClientServerSetup $setup): RedirectResponse
    {
        $row = $this->environment($environment);
        $settings = ServerSettings::fromRequest($request);

        try {
            $setup->prepare($request, $row, $settings);
        } catch (SiteRejected $e) {
            throw ValidationException::withMessages(['server_client' => $e->getMessage()]);
        }

        return redirect('/lingkungan/'.$row->id)->with('message', 'Server klien disiapkan. Buat perintah pasangnya.');
    }

    public function update(Request $request, string $environment, ClientServerSetup $setup): RedirectResponse
    {
        $row = $this->environment($environment);
        $site = Site::query()->where('environment_id', $row->id)->first();

        if (! $site instanceof Site) {
            throw ValidationException::withMessages(['server_client' => 'Siapkan server klien lebih dulu.']);
        }

        $settings = ServerSettings::fromRequest($request);

        try {
            $warning = $setup->updateSettings($request, $site, $settings);
        } catch (SiteRejected $e) {
            throw ValidationException::withMessages(['server_client' => $e->getMessage()]);
        }

        return redirect('/lingkungan/'.$row->id)->with('message', $warning ?? 'Setelan server klien disimpan.');
    }

    public function issueCommand(Request $request, string $environment, ClientServerSetup $setup): RedirectResponse
    {
        $row = $this->environment($environment);

        try {
            $issued = $setup->issueInstallCommand($request, $row);
        } catch (SiteRejected $e) {
            throw ValidationException::withMessages(['server_client' => $e->getMessage()]);
        }

        /*
         * Perintah dan kata sandi hanya lewat flash session, pola yang sama dengan pembuatan tenant di
         * `Customers\Store`: tampil sekali, tidak pernah di alamat, tidak pernah di log, dan tidak ada
         * satu pun cara membacanya lagi dari konsol ini. Token di dalam perintahnya sekali pakai dan
         * kedaluwarsa dalam satu jam.
         */
        return redirect('/lingkungan/'.$row->id)->with('install_command', [
            'command' => $issued['command'],
            'expiresAt' => $issued['expires_at']->toDateTimeString(),
            'email' => $issued['email'],
            'password' => $issued['password'],
            'release' => $issued['release'],
        ]);
    }

    private function environment(string $id): Environment
    {
        return Environment::query()->with('tenant:id,name,slug')->whereKey($id)->firstOrFail();
    }
}
