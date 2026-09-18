import type { Context } from './mutasi';
import MutasiDetailPage from './MutasiDetailPage';
import MutasiListPage from './MutasiListPage';

/**
 * Pemilih halaman mutasi aset berdasarkan alamat.
 *
 * ```
 * /management-aset/mutasi-aset               daftar
 * /management-aset/mutasi-aset/baru          berita acara baru
 * /management-aset/mutasi-aset/<id>          rincian, mode baca
 * /management-aset/mutasi-aset/<id>/ubah     rincian, mode sunting
 * ```
 *
 * Bentuknya sama persis dengan pemeliharaan aset, dan itu disengaja: dua daftar dokumen
 * yang berperilaku sama tidak boleh punya dua cara berbeda untuk membuka recordnya.
 */
export default function MutationPage({
    context,
    permissions,
    segments,
}: {
    context: Context;
    permissions: string[];
    /** Ruas alamat setelah id menu. */
    segments: string[];
}) {
    const [pertama, kedua] = segments;

    if (!pertama) {
        return <MutasiListPage permissions={permissions} />;
    }

    if (pertama === 'baru') {
        return (
            <MutasiDetailPage
                context={context}
                permissions={permissions}
                mode="create"
            />
        );
    }

    return (
        <MutasiDetailPage
            key={pertama}
            context={context}
            permissions={permissions}
            mutasiId={pertama}
            mode={kedua === 'ubah' ? 'edit' : 'view'}
        />
    );
}
