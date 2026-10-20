import { PlaceholderPage } from '../_shared/PlaceholderPage';

type PageProps = {
    view: string;
    segments?: string[];
    permissions?: string[];
};

export default function MetodePembayaranPage({
    view,
    segments,
    permissions,
}: PageProps) {
    return (
        <PlaceholderPage
            title="Metode Pembayaran"
            description="Konfigurasi opsi dan metode pembayaran untuk tagihan vendor/rekanan."
            category="Master Data"
            view={view}
            segments={segments}
            permissions={permissions}
        />
    );
}
