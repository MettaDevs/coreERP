import { PlaceholderPage } from '../_shared/PlaceholderPage';

type PageProps = {
    view: string;
    segments?: string[];
    permissions?: string[];
};

export default function PesananPembelianPage({
    view,
    segments,
    permissions,
}: PageProps) {
    return (
        <PlaceholderPage
            title="Pesanan Pembelian (Purchase Order)"
            description="Penerbitan kontrak pesanan pembelian barang dan jasa resmi kepada vendor terpilih."
            category="Transaksi Pengadaan"
            view={view}
            segments={segments}
            permissions={permissions}
        />
    );
}
