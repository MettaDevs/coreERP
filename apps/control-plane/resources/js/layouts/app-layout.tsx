import AppLayoutTemplate from '@/layouts/app/app-sidebar-layout';
import FlashToasts from '@/components/flash-toasts';
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
        </AppLayoutTemplate>
    );
}
