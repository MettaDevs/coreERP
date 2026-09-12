import { SidebarProvider } from '@apperp/ui/sidebar';
import { useState } from 'react';
import type { CSSProperties, ReactNode } from 'react';

const key = 'sidebar-open';

function read(): boolean {
    try {
        return localStorage.getItem(key) !== 'closed';
    } catch {
        return true;
    }
}

function save(open: boolean): void {
    try {
        localStorage.setItem(key, open ? 'open' : 'closed');
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
export default function AppShell({ children }: { children: ReactNode }) {
    const [open, setOpen] = useState(read);

    return (
        <SidebarProvider
            open={open}
            onOpenChange={(next) => {
                setOpen(next);
                save(next);
            }}
            style={{ '--sidebar-width': '15rem' } as CSSProperties}
        >
            {children}
        </SidebarProvider>
    );
}
