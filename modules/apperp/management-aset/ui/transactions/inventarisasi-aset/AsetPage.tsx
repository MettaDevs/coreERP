import type { Context } from './aset';
import AsetDetailPage from './AsetDetailPage';
import AsetListPage from './AsetListPage';
import PenerimaanDetailPage from './PenerimaanDetailPage';
import PenerimaanListPage from './PenerimaanListPage';

/**
 * Pemilih halaman inventarisasi aset berdasarkan alamat.
 *
 * ```
 * /management-aset/inventarisasi-aset                        register
 * /management-aset/inventarisasi-aset/<id>                   rincian aset, mode baca
 * /management-aset/inventarisasi-aset/<id>/ubah              rincian aset, mode sunting
 * /management-aset/inventarisasi-aset/penerimaan             daftar dokumen penerimaan
 * /management-aset/inventarisasi-aset/penerimaan/baru        dokumen penerimaan baru
 * /management-aset/inventarisasi-aset/penerimaan/<id>        rincian dokumen
 * /management-aset/inventarisasi-aset/penerimaan/<id>/ubah   rincian dokumen, mode sunting
 * ```
 *
 * Dokumen penerimaan tinggal di bawah alamat yang sama, bukan sebagai menu tersendiri:
 * ia satu-satunya pintu masuk ke register, dan memisahkannya menjadi menu sendiri membuat
 * orang harus tahu lebih dulu bahwa dua layar itu berbicara tentang benda yang sama.
 *
 * Tidak ada lagi `/baru` untuk aset: `POST /aset` dipensiunkan 18 September 2026, dan
 * aset yang datang satuan adalah dokumen penerimaan berbaris satu.
 *
 * Bentuknya sama dengan pemeliharaan aset dan mutasi aset. Sampai 18 September 2026
 * halaman ini satu berkas 1376 baris yang menaruh penerimaan, koreksi, dan riwayat di
 * dalam tiga `Sheet` sekaligus.
 */
export default function AsetPage({
    context,
    canUpdate,
    permissions,
    segments,
}: {
    context: Context;
    canUpdate: boolean;
    permissions: string[];
    /** Ruas alamat setelah id menu. */
    segments: string[];
}) {
    const [pertama, kedua, ketiga] = segments;

    // Diperiksa lebih dulu supaya `penerimaan` tidak ditelan cabang `{id}` di bawah,
    // urutan yang sama seperti `aset/{id}/history` pada rutenya.
    if (pertama === 'penerimaan') {
        if (!kedua) {
            return (
                <PenerimaanListPage
                    context={context}
                    permissions={permissions}
                />
            );
        }

        if (kedua === 'baru') {
            return (
                <PenerimaanDetailPage
                    context={context}
                    permissions={permissions}
                    mode="create"
                />
            );
        }

        return (
            <PenerimaanDetailPage
                key={kedua}
                context={context}
                permissions={permissions}
                penerimaanId={kedua}
                mode={ketiga === 'ubah' ? 'edit' : 'view'}
            />
        );
    }

    if (!pertama) {
        return <AsetListPage canUpdate={canUpdate} permissions={permissions} />;
    }

    return (
        <AsetDetailPage
            key={pertama}
            context={context}
            canUpdate={canUpdate}
            asetId={pertama}
            mode={kedua === 'ubah' ? 'edit' : 'view'}
        />
    );
}
