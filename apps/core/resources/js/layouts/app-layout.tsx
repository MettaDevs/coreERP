import { usePage } from '@inertiajs/react';
import { Fragment } from 'react';
import FlashToasts from '@/components/flash-toasts';
import JembatanCetakModule from '@/components/jembatan-cetak-module';
import AppLayoutTemplate from '@/layouts/app/app-sidebar-layout';
import type { Auth, BreadcrumbItem, Workspace } from '@/types';

export default function AppLayout({
    breadcrumbs = [],
    children,
}: {
    breadcrumbs?: BreadcrumbItem[];
    children: React.ReactNode;
}) {
    const { auth, workspace, permissions } = usePage<{
        auth: Auth;
        workspace: Workspace;
        permissions?: string[];
    }>().props;
    // Data dan draft konteks lama tidak boleh tertinggal ketika workspace berganti.
    const scope = JSON.stringify([
        auth.user?.id,
        auth.membership?.id,
        workspace.active_legal_entity?.id,
        workspace.active_org_unit?.id,
        auth.membership?.permissions,
        permissions,
    ]);

    return (
        <AppLayoutTemplate breadcrumbs={breadcrumbs}>
            <FlashToasts />
            <Fragment key={scope}>{children}</Fragment>
            <JembatanCetakModule />
        </AppLayoutTemplate>
    );
}
