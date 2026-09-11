import { createInertiaApp } from '@inertiajs/react';
import type { ComponentType } from 'react';
import { createRoot } from 'react-dom/client';

type ModulHalaman = { default: ComponentType<Record<string, unknown>> };

/*
 * Polanya harus sama persis dengan bawaan plugin `@inertiajs/vite`.
 *
 * Begitu berkas ini menyediakan `resolve` sendiri, plugin berhenti menyisipkan pemilih halamannya —
 * ia melewati pemanggilan `createInertiaApp` yang sudah punya `resolve`. Kalau polanya berbeda,
 * seluruh halaman hilang sekaligus tanpa satu pun pesan saat membangun.
 */
const halaman = import.meta.glob<ModulHalaman>('./pages/**/*.tsx');

void createInertiaApp({
    title: (judul) => (judul ? `${judul} · Pusat Admin` : 'Pusat Admin'),
    resolve: async (nama: string) => {
        const muat = halaman[`./pages/${nama}.tsx`];

        if (!muat) {
            throw new Error(`Halaman tidak ditemukan: ${nama}`);
        }

        return (await muat()).default;
    },
    setup({ el, App, props }) {
        createRoot(el).render(<App {...props} />);
    },
    progress: { color: '#2563eb' },
});
