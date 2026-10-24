import { PlaceholderPage } from '../_shared/PlaceholderPage';

type PageProps = {
    view: string;
    segments?: string[];
    permissions?: string[];
};

export default function VendorPage({ view, segments, permissions }: PageProps) {
    return (
        <PlaceholderPage
            title="Data Vendor"
            description="Kelola direktori rekanan, pemasok, penyedia barang dan jasa pengadaan."
            category="Master Data"
            view={view}
            segments={segments}
            permissions={permissions}
        />
    );
}
