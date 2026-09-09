import { Context } from './workOrder';
import WorkOrderDetailPage from './WorkOrderDetailPage';
import WorkOrderListPage from './WorkOrderListPage';

/**
 * Pemilih halaman pemeliharaan aset berdasarkan alamat.
 *
 * ```
 * #/pemeliharaan-aset                          daftar
 * #/pemeliharaan-aset/baru                     work order baru
 * #/pemeliharaan-aset/<id>                     rincian, mode baca
 * #/pemeliharaan-aset/<id>/ubah                rincian, mode sunting
 * #/pemeliharaan-aset/<id>/checklist/<jobId>   rincian dengan checklist terbuka
 * ```
 */
export default function WorkOrderPage({
    context,
    permissions,
    segments,
}: {
    context: Context;
    permissions: string[];
    /** Ruas alamat setelah nama sumber daya. */
    segments: string[];
}) {
    const [pertama, kedua, ketiga] = segments;

    if (!pertama) return <WorkOrderListPage permissions={permissions} />;

    if (pertama === 'baru') {
        return (
            <WorkOrderDetailPage
                context={context}
                permissions={permissions}
                mode="create"
            />
        );
    }

    return (
        <WorkOrderDetailPage
            key={pertama}
            context={context}
            permissions={permissions}
            workOrderId={pertama}
            mode={kedua === 'ubah' ? 'edit' : 'view'}
            checklistJobId={kedua === 'checklist' ? ketiga : undefined}
        />
    );
}
