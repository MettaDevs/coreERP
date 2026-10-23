import { PlaceholderPage } from '../_shared/PlaceholderPage';

type PageProps = {
    view: string;
    segments?: string[];
    permissions?: string[];
};

export default function KategoriPengadaanPage({
    view,
    segments,
    permissions,
}: PageProps) {
    return (
        <PlaceholderPage
            title="Kategori Pengadaan"
            description="Klasifikasi dan pengelompokan jenis pengadaan operasional dan proyek."
            category="Master Data"
            view={view}
            segments={segments}
            permissions={permissions}
        />
    );
}
