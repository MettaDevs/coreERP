import { Toaster } from '@apperp/ui/sonner';
import { TooltipProvider } from '@apperp/ui/tooltip';
import { createInertiaApp } from '@inertiajs/react';
import type { ComponentType } from 'react';
import { initializeTheme } from '@/hooks/use-appearance';
import AppLayout from '@/layouts/app-layout';
import AuthLayout from '@/layouts/auth-layout';
import halamanModule from '@/pages/modules/host';

const appName = import.meta.env.VITE_APP_NAME || 'Laravel';

type ModulHalaman = { default: ComponentType<Record<string, unknown>> };

/**
 * Halaman shell. Sampai F2-11 pemilih halaman disisipkan plugin `@inertiajs/vite`;
 * begitu berkas ini menyediakan `resolve` sendiri, plugin itu berhenti menyisipkannya
 * (ia melewati pemanggilan `createInertiaApp` yang sudah punya `resolve`). Pola glob di
 * bawah karena itu harus tetap sama dengan bawaan plugin, kalau tidak, seluruh halaman
 * shell hilang sekaligus.
 */
const halamanShell = import.meta.glob<ModulHalaman>('./pages/**/*.tsx');

/**
 * Halaman module, di luar akar proyek ini.
 *
 * Polanya relatif, bukan lewat alias: `import.meta.glob` diselesaikan Rollup saat
 * membangun, dan pola relatif adalah bentuk yang pasti dikenali. Izin membaca folder di
 * luar akar diberikan `server.fs.allow` pada `vite.config.ts`; tanpa itu server
 * pengembangan menolak menyajikan berkasnya, sementara `npm run build` tetap berhasil —
 * gagal hanya di satu dari dua jalur, yang membuatnya mudah salah didiagnosa.
 */
const halamanModul = import.meta.glob<ModulHalaman>(
    '../../../../modules/*/*/ui/Pages/**/*.tsx',
);

/**
 * Nama halaman module berbentuk `Modul::Halaman`, misalnya `contoh-a::Daftar`.
 *
 * Penerbit sengaja tidak ikut disebut. Id module unik di seluruh runtime — registry
 * mencarinya dengan id, dan katalog app berkunci id — jadi menuliskan penerbitnya pada
 * setiap `Inertia::render` hanya menambah satu hal lagi yang bisa salah ketik tanpa
 * menambah ketepatan. Karena itu yang dicocokkan di sini adalah akhiran jalurnya.
 */
function halamanModuleUntuk(
    nama: string,
): ComponentType<Record<string, unknown>> {
    const [modul, halaman] = nama.split('::');
    const akhiran = `/${modul}/ui/Pages/${halaman}.tsx`;
    const jalur = Object.keys(halamanModul).find((berkas) =>
        berkas.endsWith(akhiran),
    );

    if (!jalur) {
        throw new Error(`Halaman module tidak ditemukan: ${nama}`);
    }

    return halamanModule(nama, halamanModul[jalur]);
}

createInertiaApp({
    title: (title) => (title ? `${title} - ${appName}` : appName),
    resolve: async (name: string) => {
        if (name.includes('::')) {
            return halamanModuleUntuk(name);
        }

        const muat = halamanShell[`./pages/${name}.tsx`];

        if (!muat) {
            throw new Error(`Page not found: ${name}`);
        }

        const modul = await muat();

        return modul.default ?? modul;
    },
    layout: (name) => {
        switch (true) {
            case name === 'welcome':
                return null;
            case name.startsWith('auth/'):
                return AuthLayout;
            default:
                return AppLayout;
        }
    },
    strictMode: true,
    withApp(app) {
        return (
            <TooltipProvider delayDuration={1000}>
                {app}
                <Toaster />
            </TooltipProvider>
        );
    },
    progress: {
        color: '#4B5563',
    },
});

// This will set light / dark mode on load...
initializeTheme();
