import { usePage } from '@inertiajs/react';
import { useEffect } from 'react';
import { toast } from 'sonner';
import { trackExport } from '@/lib/export-watch';
import { isListExportMessage } from '@/lib/list-exports';
import { isPrintMessage, requestPrint } from '@/lib/print-requests';
import { requestListExport } from '@/lib/reports';

/**
 * Menyambungkan permintaan cetak dan ekspor daftar dari layar module ke Shell.
 *
 * Halaman module tidak punya bingkai: ia dirender di dalam shell yang sama, jadi tidak ada
 * `postMessage` dan tidak ada halaman tuan rumah. Permintaannya datang sebagai
 * `CustomEvent('coreerp:print')` dan `CustomEvent('coreerp:list-export')` pada `window`.
 *
 * **Kenapa event, bukan module memanggil `requestPrint()` langsung.** Keduanya berada dalam
 * satu build, jadi impor `@/lib/print-requests` dari folder module akan berhasil. Justru itu
 * bahayanya: module berhenti bisa dicabut ke repo lain, dan tidak ada satu pun pemeriksaan
 * yang gagal saat itu terjadi. Aturan module — hanya boleh menyebut `@apperp/ui` dan React —
 * tetap utuh bila jalurnya sebuah event peramban. Ekspor daftar juga lewat sini, bukan lewat
 * panggilan API dari module, karena tray Ekspor milik Shell yang harus mulai memantaunya.
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

        const eksporDaftar = (event: Event) => {
            const detail = (event as CustomEvent<unknown>).detail;

            if (!isListExportMessage(detail) || detail.appId !== app.id) {
                return;
            }

            requestListExport({
                app_id: app.id,
                list: detail.list,
                columns: detail.columns,
                sort: detail.sort,
                filters: detail.filters,
            })
                .then((item) => {
                    trackExport(item);
                    toast.info(
                        'Ekspor daftar dimulai. Berkasnya muncul di ikon Ekspor pada header setelah siap.',
                    );
                })
                .catch((caught: unknown) =>
                    toast.error(
                        caught instanceof Error && caught.message
                            ? caught.message
                            : 'Ekspor daftar belum dapat dimulai.',
                    ),
                );
        };

        window.addEventListener('coreerp:print', terima);
        window.addEventListener('coreerp:list-export', eksporDaftar);

        return () => {
            window.removeEventListener('coreerp:print', terima);
            window.removeEventListener('coreerp:list-export', eksporDaftar);
        };
    }, [app]);

    return null;
}
