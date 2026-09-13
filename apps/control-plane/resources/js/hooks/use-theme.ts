import { useSyncExternalStore } from 'react';

export type Theme = 'light' | 'dark' | 'system';

const key = 'theme';
const listeners = new Set<() => void>();

let choice: Theme = 'system';

export function toTheme(value: string): Theme {
    return value === 'light' || value === 'dark' ? value : 'system';
}

function systemPrefersDark(): boolean {
    return window.matchMedia('(prefers-color-scheme: dark)').matches;
}

function apply(theme: Theme): void {
    const dark =
        theme === 'dark' || (theme === 'system' && systemPrefersDark());

    document.documentElement.classList.toggle('dark', dark);
    document.documentElement.style.colorScheme = dark ? 'dark' : 'light';
}

/*
 * Setiap sentuhan ke `localStorage` dijaga `try`.
 *
 * Peramban boleh menolaknya sama sekali — jendela privat, atau setelan yang memblokir penyimpanan
 * situs — dan penolakannya berbentuk lemparan, bukan nilai kosong. Tanpa penjaga ini satu
 * pembacaan yang gagal menjatuhkan seluruh aplikasi sebelum satu piksel pun tergambar, dan yang
 * terlihat operator hanyalah halaman putih tanpa pesan.
 */
function read(): Theme {
    try {
        return toTheme(localStorage.getItem(key) ?? '');
    } catch {
        return 'system';
    }
}

function subscribe(notify: () => void): () => void {
    listeners.add(notify);

    return () => {
        listeners.delete(notify);
    };
}

function snapshot(): Theme {
    return choice;
}

/**
 * Dipanggil sekali dari berkas masuk, sesudah skrip di `app.blade.php` menerapkan temanya.
 *
 * Yang dikerjakannya bukan menghindari kedipan — itu tugas skrip di Blade, karena ia berjalan
 * sebelum badan halaman tergambar. Yang dikerjakannya adalah menyamakan ingatan modul ini dengan
 * apa yang sudah terpasang, lalu mendengarkan perubahan tema sistem: seseorang yang memilih "ikut
 * sistem" mengharapkan layarnya ikut berubah saat senja, bukan saat ia memuat ulang halaman.
 */
export function initTheme(): void {
    choice = read();
    apply(choice);

    window
        .matchMedia('(prefers-color-scheme: dark)')
        .addEventListener('change', () => apply(choice));
}

export function useTheme(): {
    theme: Theme;
    setTheme: (theme: Theme) => void;
} {
    const theme = useSyncExternalStore(subscribe, snapshot, snapshot);

    function setTheme(next: Theme): void {
        choice = next;

        try {
            localStorage.setItem(key, next);
        } catch {
            // Pilihannya tetap berlaku sampai tab ini ditutup; yang hilang hanya ingatannya.
        }

        apply(next);
        listeners.forEach((notify) => notify());
    }

    return { theme, setTheme };
}
