/**
 * Permintaan cetak dari layar module ke shell.
 *
 * Module tidak mencetak sendiri: ia meminta shell membuka dialog cetak Core, dan Core yang
 * memilih layout, mengantrekan ekspor, serta memberi tahu hasilnya lewat tray dan lonceng
 * di header. Yang diketahui module hanya kode laporan dan parameternya.
 *
 * Dulu permintaan ini sebuah pesan antar bingkai ke `window.parent`, lengkap dengan
 * pemeriksaan origin, karena UI module berjalan di dalam iframe. Sekarang ia satu dokumen dengan shell,
 * jadi jalur baliknya sebuah `CustomEvent` pada `window` yang didengarkan shell.
 *
 * **Kenapa event, bukan impor langsung ke dialog cetak Core.** Module hanya boleh menyebut
 * `@apperp/ui`, React, `@inertiajs/react`, dan berkasnya sendiri. Sebuah impor `@/…` akan
 * berhasil dibangun — folder shell ikut build yang sama — dan justru itu bahayanya: module
 * berhenti bisa dicabut ke repo lain, dan tidak ada satu pun langkah yang gagal saat itu
 * terjadi.
 */

export const APP_ID = 'management-aset';

/** Nama event yang didengarkan shell. */
export const EVENT_CETAK = 'coreerp:print';

export type PrintRequest = {
    /** Kode laporan pada `app.yaml`, tanpa awalan ID app. */
    report: string;
    /** Judul dialog untuk pengguna, misalnya "Cetak work order PMHA-000012". */
    title: string;
    parameters: Record<string, unknown>;
};

export function requestPrint(request: PrintRequest): void {
    window.dispatchEvent(
        new CustomEvent(EVENT_CETAK, {
            detail: { appId: APP_ID, ...request },
        }),
    );
}
