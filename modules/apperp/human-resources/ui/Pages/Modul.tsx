import { Head } from '@inertiajs/react';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@apperp/ui/card';
import {
    Empty,
    EmptyDescription,
    EmptyHeader,
    EmptyTitle,
} from '@apperp/ui/empty';

/**
 * Satu halaman Inertia untuk seluruh menu module Human Resources.
 *
 * **Layarnya memang belum ada, dan halaman ini tidak berpura-pura sebaliknya.** Yang dipindah
 * pada fase ini adalah jalur masuknya, bukan isinya: dulu tiap menu dibuka perutean hash di
 * dalam aplikasi Vite tersendiri (`#/workers`), sekarang ia dibuka rute shell
 * `/human-resources/{view}/{sisa?}` dan propertinya sudah ada sejak halaman pertama tampil.
 * Menyalin layar CRUD lama ke sini hanya akan memindahkan kode yang alamat API-nya sudah
 * berpindah pula, dan kegagalannya baru terlihat di tangan pengguna.
 *
 * **Berkas ini tidak mengimpor apa pun milik shell.** Hanya `@apperp/ui`, React, dan
 * `@inertiajs/react`. Sebuah impor `@/…` akan berhasil dibangun karena shell dan module
 * berbagi satu build, dan justru itu bahayanya: module berhenti bisa dicabut ke repo lain
 * tanpa satu pun pemeriksaan yang gagal.
 *
 * Halaman ini tidak memeriksa ulang apakah `view` ada di manifest maupun apakah izinnya
 * dipegang. `HalamanModulController` sudah menjawab 404 dan 403 untuk keduanya, dan
 * pemeriksaan kedua di sini hanya akan menjadi daftar yang bisa menyimpang dari manifest.
 */
export type PropsModul = {
    /** Id entri menu pada `app.yaml`, misalnya `workers`. */
    view: string;
    /** Ruas sesudah id menu, misalnya `['01JQ…', 'ubah']`. */
    segments: string[];
    /** Izin efektif pengguna untuk module ini. */
    permissions: string[];
    konteks: {
        legal_entity_id: string | null;
        org_unit_id: string | null;
        user_id: string;
    };
};

export default function Modul({ view, segments, permissions }: PropsModul) {
    /*
     * Yang disebut di layar adalah id entri menunya, bukan labelnya.
     *
     * Label hidup di `app.yaml` dan sudah dipakai Core untuk menyusun sidebar. Menuliskannya
     * lagi di sini berarti daftar kedua yang menyimpang diam-diam begitu satu label diubah —
     * dan layar yang menyebut dirinya "Pekerja" sementara sidebar menyebutnya lain adalah
     * penyimpangan yang tidak membuat apa pun gagal.
     */
    return (
        <>
            <Head title="Human Resources" />
            <main className="p-6">
                <Card>
                    <CardHeader>
                        <CardTitle>Human Resources</CardTitle>
                        <CardDescription>
                            Entri menu yang sedang dibuka: <code>{view}</code>
                            {segments.length > 0 && (
                                <>
                                    {' '}
                                    · ruas alamat:{' '}
                                    <code>{segments.join('/')}</code>
                                </>
                            )}
                        </CardDescription>
                    </CardHeader>
                    <CardContent>
                        <Empty>
                            <EmptyHeader>
                                <EmptyTitle>
                                    Layar ini belum dipindah
                                </EmptyTitle>
                                <EmptyDescription>
                                    Module sudah berjalan di dalam runtime Core:
                                    alamat, sesi, konteks organisasi, dan{' '}
                                    {permissions.length} izin yang Anda pegang
                                    untuk module ini sudah sampai ke halaman
                                    ini. Yang belum dipindah adalah isi
                                    layarnya.
                                </EmptyDescription>
                            </EmptyHeader>
                        </Empty>
                    </CardContent>
                </Card>
            </main>
        </>
    );
}
