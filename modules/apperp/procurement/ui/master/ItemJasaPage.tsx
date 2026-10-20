import { PlaceholderPage } from '../_shared/PlaceholderPage';

type PageProps = {
    view: string;
    segments?: string[];
    permissions?: string[];
};

export default function ItemJasaPage({
    view,
    segments,
    permissions,
}: PageProps) {
    return (
        <PlaceholderPage
            title="Item Upah / Jasa"
            description="Katalog item jasa, pekerjaan teknis, dan tarif standar pengadaan."
            category="Master Data"
            view={view}
            segments={segments}
            permissions={permissions}
        />
    );
}
