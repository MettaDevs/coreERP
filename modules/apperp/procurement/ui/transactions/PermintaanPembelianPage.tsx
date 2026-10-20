import { PlaceholderPage } from '../_shared/PlaceholderPage';

type PageProps = {
    view: string;
    segments?: string[];
    permissions?: string[];
};

export default function PermintaanPembelianPage({
    view,
    segments,
    permissions,
}: PageProps) {
    return (
        <PlaceholderPage
            title="Permintaan Pembelian (Purchase Requisition)"
            description="Pengajuan kebutuhan barang dan jasa dari unit kerja / pemohon internal."
            category="Transaksi Pengadaan"
            view={view}
            segments={segments}
            permissions={permissions}
        />
    );
}
