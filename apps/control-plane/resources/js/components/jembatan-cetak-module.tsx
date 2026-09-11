import { usePage } from '@inertiajs/react';
import { useEffect } from 'react';
import { isPrintMessage, requestPrint } from '@/lib/print-requests';

/**
 * Menyambungkan permintaan cetak dari layar module ke dialog cetak Shell.
 *
 * Halaman module tidak punya bingkai: ia dirender di dalam shell yang sama, jadi tidak ada
 * `postMessage` dan tidak ada halaman tuan rumah. Permintaannya datang sebagai
 * `CustomEvent('coreerp:print')` pada `window`.
 *
 * **Kenapa event, bukan module memanggil `requestPrint()` langsung.** Keduanya berada dalam
 * satu build, jadi impor `@/lib/print-requests` dari folder module akan berhasil. Justru itu
 * bahayanya: module berhenti bisa dicabut ke repo lain, dan tidak ada satu pun pemeriksaan
 * yang gagal saat itu terjadi. Aturan module — hanya boleh menyebut `@apperp/ui` dan React —
 * tetap utuh bila jalurnya sebuah event peramban.
 *
 * **Bentuk pesannya tetap diperiksa.** Isi
 * `detail` datang dari kode module, dan kode module ikut berubah tanpa perubahan di sini;
 * pemeriksa itu yang menahan bentuk yang menyimpang supaya tidak sampai ke dialog cetak
 * sebagai parameter yang setengah benar.
 */
export default function JembatanCetakModule() {
    const { props } = usePage();
    const app = props.app;

    useEffect(() => {
        // Tanpa `app` yang dibagikan `ResolveModuleContext`, halaman yang sedang terbuka
        // bukan layar module. Tidak ada yang perlu didengarkan, dan memasang pendengar tetap
        // berarti menerima event dari halaman shell mana pun.
        if (app === undefined) {
            return;
        }

        const terima = (event: Event) => {
            const detail = (event as CustomEvent<unknown>).detail;

            if (typeof detail !== 'object' || detail === null) {
                return;
            }

            // `type` disisipkan di sini, bukan dituntut dari module. Pada jalur iframe ia
            // memang bagian pesannya karena satu jendela menerima banyak jenis pesan; sebuah
            // event bernama `coreerp:print` sudah menyebutkan jenisnya pada namanya.
            const pesan = { type: 'coreerp.print', ...detail };

            if (!isPrintMessage(pesan) || pesan.appId !== app.id) {
                return;
            }

            requestPrint({
                appId: app.id,
                appName: app.name,
                reportCode: `${app.id}.${pesan.report}`,
                title: pesan.title,
                parameters: pesan.parameters,
            });
        };

        window.addEventListener('coreerp:print', terima);

        return () => window.removeEventListener('coreerp:print', terima);
    }, [app]);

    return null;
}
