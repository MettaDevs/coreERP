import { PlaceholderPage } from '../_shared/PlaceholderPage';

type PageProps = {
    view: string;
    segments?: string[];
    permissions?: string[];
};

export default function JenisVendorPage({
    view,
    segments,
    permissions,
}: PageProps) {
    return (
        <PlaceholderPage
            title="Jenis Vendor"
            description="Klasifikasi dan kategori badan usaha atau perorangan penyedia rekanan."
            category="Master Data"
            view={view}
            segments={segments}
            permissions={permissions}
        />
    );
}
