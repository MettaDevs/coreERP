import { PlaceholderPage } from '../_shared/PlaceholderPage';

type PageProps = {
    view: string;
    segments?: string[];
    permissions?: string[];
};

export default function PermintaanPenawaranPage({
    view,
    segments,
    permissions,
}: PageProps) {
    return (
        <PlaceholderPage
            title="Permintaan Penawaran (RFQ)"
            description="Undangan penawaran harga kepada rekanan vendor dan analisis perbandingan harga."
            category="Transaksi Pengadaan"
            view={view}
            segments={segments}
            permissions={permissions}
        />
    );
}
