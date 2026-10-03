import { router } from '@inertiajs/react';
import type * as SentryModule from '@sentry/react';

/**
 * Sentry di peramban: kesalahan JavaScript dan sesi pengguna, yang menjadi Crash Free Sessions dan
 * Crash Free Users per rilis di Sentry. Pasangan sisi server-nya `ErrorReporter::toSentry()`.
 *
 * Setelannya dibaca dari meta di `resources/views/app.blade.php`, bukan dari variabel Vite: satu image
 * dipakai banyak lingkungan, jadi DSN dan nomor rilis baru diketahui saat berjalan. Tanpa meta
 * `sentry-dsn` tidak ada yang dipasang dan tidak ada yang dikirim.
 *
 * SDK-nya dimuat terpisah (`import()`) dan hanya bila DSN ada: dimuat langsung ia menambah ±29 KB gzip
 * ke bundel utama yang dibayar setiap pengguna, termasuk pemasangan on-prem yang tidak pernah memakainya.
 *
 * Jejak performa peramban sengaja tidak dinyalakan; Apdex dan waktu permintaan sudah datang dari server.
 * Pengguna hanya id-nya — tanpa nama, email, atau alamat IP.
 */
let sentry: typeof SentryModule | null = null;

export function installSentry(): void {
    const dsn = meta('sentry-dsn');

    if (dsn === '') {
        return;
    }

    const userId = meta('sentry-user');

    void import('@sentry/react').then((Sentry) => {
        Sentry.init({
            dsn,
            release: meta('sentry-release') || undefined,
            environment: meta('sentry-environment') || undefined,
            sendDefaultPii: false,
            // Pengguna sudah terpasang saat sesi pertama dimulai, supaya sesi itu terhitung milik
            // pengguna yang sama — tanpa ini Crash Free Users kosong.
            initialScope: userId === '' ? undefined : { user: { id: userId } },
        });
        sentry = Sentry;

        // Masuk dan keluar terjadi lewat navigasi Inertia, bukan muat ulang halaman.
        router.on('navigate', (event) => {
            const id = (
                event.detail.page.props as {
                    auth?: { user?: { id?: unknown } | null };
                }
            ).auth?.user?.id;
            Sentry.setUser(
                id === undefined || id === null ? null : { id: String(id) },
            );
        });
    });
}

/** Kesalahan yang sudah ditangkap aplikasi sendiri (misalnya batas halaman module) tetap sampai ke Sentry. */
export function reportToSentry(error: unknown, source: string): void {
    sentry?.captureException(error, {
        tags: { 'coreerp.sumber_kesalahan': source },
    });
}

function meta(name: string): string {
    return (
        document
            .querySelector(`meta[name="${name}"]`)
            ?.getAttribute('content')
            ?.trim() ?? ''
    );
}
