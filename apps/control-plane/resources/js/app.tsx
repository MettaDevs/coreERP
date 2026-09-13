import { createInertiaApp } from '@inertiajs/react';
import type { ComponentType } from 'react';
import { createRoot } from 'react-dom/client';
import { initTheme } from '@/hooks/use-theme';

type PageModule = { default: ComponentType<Record<string, unknown>> };

/*
 * Polanya harus sama persis dengan bawaan plugin `@inertiajs/vite`.
 *
 * Begitu berkas ini menyediakan `resolve` sendiri, plugin berhenti menyisipkan pemilih halamannya —
 * ia melewati pemanggilan `createInertiaApp` yang sudah punya `resolve`. Kalau polanya berbeda,
 * seluruh halaman hilang sekaligus tanpa satu pun pesan saat membangun.
 */
const pages = import.meta.glob<PageModule>('./pages/**/*.tsx');

initTheme();

void createInertiaApp({
    title: (title) => (title ? `${title} · Pusat Admin` : 'Pusat Admin'),
    resolve: async (name: string) => {
        const load = pages[`./pages/${name}.tsx`];

        if (!load) {
            throw new Error(`Halaman tidak ditemukan: ${name}`);
        }

        return (await load()).default;
    },
    setup({ el, App, props }) {
        createRoot(el).render(<App {...props} />);
    },
    progress: { color: '#2563eb' },
});
