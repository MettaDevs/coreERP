import { PlaceholderPage } from '../_shared/PlaceholderPage';

type PageProps = {
    view: string;
    segments?: string[];
    permissions?: string[];
};

export default function KualifikasiPage({
    view,
    segments,
    permissions,
}: PageProps) {
    return (
        <PlaceholderPage
            title="Kualifikasi Vendor"
            description="Status kualifikasi dan sertifikasi rekanan yang lolos tahap verifikasi."
            category="Master Data"
            view={view}
            segments={segments}
            permissions={permissions}
        />
    );
}
