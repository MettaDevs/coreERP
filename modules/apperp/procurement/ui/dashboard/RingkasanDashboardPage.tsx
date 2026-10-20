import { PlaceholderPage } from '../_shared/PlaceholderPage';

type PageProps = {
    view: string;
    segments?: string[];
    permissions?: string[];
};

export default function RingkasanDashboardPage({
    view,
    segments,
    permissions,
}: PageProps) {
    return (
        <PlaceholderPage
            title="Dashboard Pengadaan"
            description="Ringkasan metrik pengadaan barang/jasa, status dokumen aktif, dan kinerja belanja."
            category="Dashboard"
            view={view}
            segments={segments}
            permissions={permissions}
        />
    );
}
