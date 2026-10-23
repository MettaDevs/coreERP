import { PlaceholderPage } from '../_shared/PlaceholderPage';

type PageProps = {
    view: string;
    segments?: string[];
    permissions?: string[];
};

export default function LaporanPengadaanPage({
    view,
    segments,
    permissions,
}: PageProps) {
    return (
        <PlaceholderPage
            title="Laporan Pengadaan"
            description="Rekapitulasi aktivitas belanja, status pengadaan, dan laporan realisasi anggaran."
            category="Laporan"
            view={view}
            segments={segments}
            permissions={permissions}
        />
    );
}
