import { PlaceholderPage } from '../_shared/PlaceholderPage';

type PageProps = {
    view: string;
    segments?: string[];
    permissions?: string[];
};

export default function PenerimaanBarangPage({
    view,
    segments,
    permissions,
}: PageProps) {
    return (
        <PlaceholderPage
            title="Penerimaan Barang (Goods Receipt)"
            description="Pencatatan dan verifikasi fisik serah terima barang pesanan yang tiba di lokasi."
            category="Transaksi Pengadaan"
            view={view}
            segments={segments}
            permissions={permissions}
        />
    );
}
