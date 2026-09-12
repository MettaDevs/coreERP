import { useSyncExternalStore } from 'react';

export type Tema = 'terang' | 'gelap' | 'sistem';

const kunci = 'tampilan';
const pendengar = new Set<() => void>();

let pilihan: Tema = 'sistem';

export function keTema(nilai: string): Tema {
    return nilai === 'terang' || nilai === 'gelap' ? nilai : 'sistem';
}

function sistemGelap(): boolean {
    return window.matchMedia('(prefers-color-scheme: dark)').matches;
}

function terapkan(tema: Tema): void {
    const gelap = tema === 'gelap' || (tema === 'sistem' && sistemGelap());

    document.documentElement.classList.toggle('dark', gelap);
    document.documentElement.style.colorScheme = gelap ? 'dark' : 'light';
}

/*
 * Setiap sentuhan ke `localStorage` dijaga `try`.
 *
 * Peramban boleh menolaknya sama sekali — jendela privat, atau setelan yang memblokir penyimpanan
 * situs — dan penolakannya berbentuk lemparan, bukan nilai kosong. Tanpa penjaga ini satu
 * pembacaan yang gagal menjatuhkan seluruh aplikasi sebelum satu piksel pun tergambar, dan yang
 * terlihat operator hanyalah halaman putih tanpa pesan.
 */
function baca(): Tema {
    try {
        return keTema(localStorage.getItem(kunci) ?? '');
    } catch {
        return 'sistem';
    }
}

function langgan(beritahu: () => void): () => void {
    pendengar.add(beritahu);

    return () => {
        pendengar.delete(beritahu);
    };
}

function ambil(): Tema {
    return pilihan;
}

/**
 * Dipanggil sekali dari berkas masuk, sesudah skrip di `app.blade.php` menerapkan temanya.
 *
 * Yang dikerjakannya bukan menghindari kedipan — itu tugas skrip di Blade, karena ia berjalan
 * sebelum badan halaman tergambar. Yang dikerjakannya adalah menyamakan ingatan modul ini dengan
 * apa yang sudah terpasang, lalu mendengarkan perubahan tema sistem: seseorang yang memilih "ikut
 * sistem" mengharapkan layarnya ikut berubah saat senja, bukan saat ia memuat ulang halaman.
 */
export function pasangTema(): void {
    pilihan = baca();
    terapkan(pilihan);

    window
        .matchMedia('(prefers-color-scheme: dark)')
        .addEventListener('change', () => terapkan(pilihan));
}

export function useTema(): {
    tema: Tema;
    setel: (tema: Tema) => void;
} {
    const tema = useSyncExternalStore(langgan, ambil, ambil);

    function setel(baru: Tema): void {
        pilihan = baru;

        try {
            localStorage.setItem(kunci, baru);
        } catch {
            // Pilihannya tetap berlaku sampai tab ini ditutup; yang hilang hanya ingatannya.
        }

        terapkan(baru);
        pendengar.forEach((beritahu) => beritahu());
    }

    return { tema, setel };
}
