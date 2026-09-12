import { SidebarProvider } from '@apperp/ui/sidebar';
import { useState } from 'react';
import type { CSSProperties, ReactNode } from 'react';

const kunci = 'sidebar-terbuka';

function baca(): boolean {
    try {
        return localStorage.getItem(kunci) !== 'tutup';
    } catch {
        return true;
    }
}

function simpan(terbuka: boolean): void {
    try {
        localStorage.setItem(kunci, terbuka ? 'buka' : 'tutup');
    } catch {
        // Sesi ini tetap menghormati pilihannya; yang hilang hanya ingatannya.
    }
}

/**
 * Bingkai terluar konsol: penyedia keadaan sidebar, sepadan dengan `app-shell.tsx` milik Core.
 *
 * Bedanya keadaan itu diingat peramban, bukan server. `SidebarProvider` bawaan `@apperp/ui`
 * menuliskannya sebagai cookie `sidebar_state` dari JavaScript, sementara konsol ini mengenkripsi
 * seluruh cookie-nya — Core mengecualikan `sidebar_state` di `bootstrap/app.php`, konsol ini
 * tidak. Yang dibaca PHP dari cookie itu karena itu selalu kosong, dan sidebar akan selalu terbuka
 * kembali sesudah operator menutupnya. Membacanya dari `localStorage` pada render pertama memberi
 * jawaban yang benar tanpa menunggu server dan tanpa kedipan membuka-lalu-menutup.
 */
export default function Cangkang({ children }: { children: ReactNode }) {
    const [terbuka, setTerbuka] = useState(baca);

    return (
        <SidebarProvider
            open={terbuka}
            onOpenChange={(baru) => {
                setTerbuka(baru);
                simpan(baru);
            }}
            style={{ '--sidebar-width': '15rem' } as CSSProperties}
        >
            {children}
        </SidebarProvider>
    );
}
