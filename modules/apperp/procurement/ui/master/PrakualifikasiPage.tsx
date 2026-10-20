import { PlaceholderPage } from '../_shared/PlaceholderPage';

type PageProps = {
    view: string;
    segments?: string[];
    permissions?: string[];
};

export default function PrakualifikasiPage({
    view,
    segments,
    permissions,
}: PageProps) {
    return (
        <PlaceholderPage
            title="Prakualifikasi Vendor"
            description="Proses penilaian kelayakan dan berkas prakualifikasi rekanan pengadaan."
            category="Master Data"
            view={view}
            segments={segments}
            permissions={permissions}
        />
    );
}
