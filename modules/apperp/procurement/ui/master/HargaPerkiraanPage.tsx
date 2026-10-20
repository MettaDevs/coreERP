import { PlaceholderPage } from '../_shared/PlaceholderPage';

type PageProps = {
    view: string;
    segments?: string[];
    permissions?: string[];
};

export default function HargaPerkiraanPage({
    view,
    segments,
    permissions,
}: PageProps) {
    return (
        <PlaceholderPage
            title="Harga Perkiraan Sendiri (HPS)"
            description="Daftar harga perkiraan pengadaan barang dan jasa sebagai acuan tender/lelang."
            category="Master Data"
            view={view}
            segments={segments}
            permissions={permissions}
        />
    );
}
