import { PlaceholderPage } from '../_shared/PlaceholderPage';

type PageProps = {
    view: string;
    segments?: string[];
    permissions?: string[];
};

export default function TerminPembayaranPage({
    view,
    segments,
    permissions,
}: PageProps) {
    return (
        <PlaceholderPage
            title="Termin Pembayaran"
            description="Jangka waktu, termin jatuh tempo, dan syarat pembayaran rekanan pengadaan."
            category="Master Data"
            view={view}
            segments={segments}
            permissions={permissions}
        />
    );
}
