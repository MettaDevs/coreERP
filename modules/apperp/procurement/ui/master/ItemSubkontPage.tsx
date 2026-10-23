import { PlaceholderPage } from '../_shared/PlaceholderPage';

type PageProps = {
    view: string;
    segments?: string[];
    permissions?: string[];
};

export default function ItemSubkontPage({
    view,
    segments,
    permissions,
}: PageProps) {
    return (
        <PlaceholderPage
            title="Item Subkontraktor"
            description="Katalog item borongan dan paket pekerjaan subkontraktor pengadaan."
            category="Master Data"
            view={view}
            segments={segments}
            permissions={permissions}
        />
    );
}
