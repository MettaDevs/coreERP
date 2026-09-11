import FlashToasts from '@/components/flash-toasts';
import JembatanCetakModule from '@/components/jembatan-cetak-module';
import AppLayoutTemplate from '@/layouts/app/app-sidebar-layout';
import type { BreadcrumbItem } from '@/types';

export default function AppLayout({
    breadcrumbs = [],
    children,
}: {
    breadcrumbs?: BreadcrumbItem[];
    children: React.ReactNode;
}) {
    return (
        <AppLayoutTemplate breadcrumbs={breadcrumbs}>
            {children}
            <FlashToasts />
            <JembatanCetakModule />
        </AppLayoutTemplate>
    );
}
