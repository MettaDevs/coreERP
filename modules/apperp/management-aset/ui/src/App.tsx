import { useEffect, useMemo, useState } from 'react';
import { applyCoreErpTheme, type CoreErpTheme } from '@apperp/ui/theme';
import { Card, CardContent } from '@apperp/ui/card';
import { Empty, EmptyDescription, EmptyHeader, EmptyTitle } from '@apperp/ui/empty';
import { api, errorMessage, setContextToken } from './api';
import MasterPage from './master/MasterPage';
import MasterDetailPage, { DETAIL_LAYOUT_RESOURCES } from './master/detail/MasterDetailPage';
import AssetPage from './transactions/inventarisasi-aset/AssetPage';
import DepreciationPage from './transactions/inventarisasi-aset/DepreciationPage';
import LifecycleDocumentPage from './transactions/_shared/LifecycleDocumentPage';
import MutationPage from './transactions/mutasi-aset/MutationPage';
import MonitoringPage from './transactions/monitoring-aset/MonitoringPage';
import PlanningPage from './transactions/perencanaan-aset/PlanningPage';
import StatusValidationPage from './transactions/pemeliharaan-aset/StatusValidationPage';
import WorkOrderPage from './transactions/pemeliharaan-aset/WorkOrderPage';
import { config as permintaanPembelianAset } from './transactions/permintaan-pembelian-aset/config';
import { config as dekomisioningAset } from './transactions/dekomisioning-aset/config';
import { config as penjualanAset } from './transactions/penjualan-aset/config';
import { config as pemusnahanAset } from './transactions/pemusnahan-aset/config';
import { MASTERS, MasterResource, Permission, permission } from './master/masters';
import FixedAssetSetupPlaceholderPage from './fixed-assets-setup/FixedAssetSetupPlaceholderPage';
import { setParentOrigin } from './shell';

/**
 * Alamat dibaca sebagai nama sumber daya diikuti ruas-ruas miliknya, misalnya
 * `#/pemeliharaan-aset/<id>/ubah`. Halaman yang membuka satu record pada layar
 * tersendiri memakai ruas itu, sehingga tombol kembali peramban, muat ulang, dan tautan
 * yang disalin semuanya mendarat di record yang sama.
 */
function useHashRoute(): { resource: string; segments: string[] } {
    const read = () => {
        const [resource = '', ...segments] = window.location.hash
            .replace(/^#\/?/, '')
            .split('/')
            .filter((ruas) => ruas !== '');

        return { resource, segments };
    };
    const [route, setRoute] = useState(read);

    useEffect(() => {
        const onChange = () => setRoute(read());
        window.addEventListener('hashchange', onChange);
        return () => window.removeEventListener('hashchange', onChange);
    }, []);

    return route;
}

export default function App() {
    const [permissions, setPermissions] = useState<Permission[]>([]);
    const [contextReady, setContextReady] = useState(false);
    const [contextToken, setAppContextToken] = useState('');
    const [contextError, setContextError] = useState('');
    const [assetContext, setAssetContext] = useState({
        legal_entity_id: null as string | null,
        org_unit_id: null as string | null,
        user_id: null as string | number | null,
    });
    const { resource: hashResource, segments } = useHashRoute();

    // Hanya master yang boleh dilihat pengguna ini yang muncul pada navigasi.
    const visible = useMemo(
        () =>
            MASTERS.filter(
                (master) =>
                    master.showInNavigation !== false &&
                    permissions.includes(permission(master.resource, 'read')),
            ),
        [permissions],
    );
    const active = visible.find((master) => master.resource === hashResource) ?? visible[0];

    useEffect(() => {
        const parentOrigin = document.referrer ? new URL(document.referrer).origin : '';
        const receiveContext = (event: MessageEvent) => {
            if (event.source !== window.parent || event.origin !== parentOrigin) return;
            if (event.data?.type !== 'coreerp.context' || event.data?.appId !== 'management-aset')
                return;
            applyCoreErpTheme(event.data.theme as CoreErpTheme);
            setParentOrigin(event.origin);
            setContextToken(event.data.token);
            setAppContextToken(event.data.token);
            setContextReady(true);
        };
        window.addEventListener('message', receiveContext);
        if (parentOrigin) {
            window.parent.postMessage(
                { type: 'coreerp.ready', appId: 'management-aset' },
                parentOrigin,
            );
        }
        return () => window.removeEventListener('message', receiveContext);
    }, []);

    useEffect(() => {
        if (!contextToken) return;
        api<{
            data: {
                permissions: Permission[];
                legal_entity_id: string | null;
                org_unit_id: string | null;
                user_id: string | number | null;
            };
        }>('/context')
            .then((context) => {
                setPermissions(context.data.permissions);
                setAssetContext(context.data);
                setContextError('');
            })
            .catch((caught) =>
                setContextError(errorMessage(caught, 'Hak akses belum dapat dibaca.')),
            );
    }, [contextToken]);

    if (!contextReady) {
        return (
            <main>
                <Card>
                    <CardContent>
                        <Empty>
                            <EmptyDescription>Menyiapkan akses aplikasi…</EmptyDescription>
                        </Empty>
                    </CardContent>
                </Card>
            </main>
        );
    }

    if (contextError) {
        return (
            <main>
                <Card>
                    <CardContent>
                        <Empty>
                            <EmptyHeader>
                                <EmptyTitle>Aplikasi belum dapat dibuka</EmptyTitle>
                                <EmptyDescription>{contextError}</EmptyDescription>
                            </EmptyHeader>
                        </Empty>
                    </CardContent>
                </Card>
            </main>
        );
    }

    // Id view mengikuti nama prosesnya; permission tetap `aset` karena ia kontrak.
    if (
        hashResource === 'inventarisasi-aset' &&
        permissions.includes('management-aset.aset.read')
    ) {
        return (
            <main>
                <AssetPage
                    context={assetContext}
                    canUpdate={permissions.includes('management-aset.aset.update')}
                />
            </main>
        );
    }
    if (hashResource === 'penyusutan' && permissions.includes('management-aset.penyusutan.read'))
        return (
            <main>
                <DepreciationPage
                    canCreate={permissions.includes('management-aset.penyusutan.create')}
                    canFinalize={permissions.includes('management-aset.penyusutan.finalize')}
                    canCorrect={permissions.includes('management-aset.penyusutan.correct')}
                />
            </main>
        );
    if (
        hashResource === 'fixed-asset-parameters' &&
        permissions.includes('management-aset.fixed-asset-parameters.read')
    )
        return (
            <main>
                <FixedAssetSetupPlaceholderPage kind="parameters" />
            </main>
        );
    if (
        hashResource === 'fixed-asset-posting-profiles' &&
        permissions.includes('management-aset.fixed-asset-posting-profiles.read')
    )
        return (
            <main>
                <FixedAssetSetupPlaceholderPage kind="posting-profiles" />
            </main>
        );

    if (hashResource === 'mutasi-aset' && permissions.includes('management-aset.mutasi-aset.read'))
        return (
            <main>
                <MutationPage />
            </main>
        );
    if (
        hashResource === 'monitoring-aset' &&
        permissions.includes('management-aset.monitoring-aset.read')
    )
        return (
            <main>
                <MonitoringPage />
            </main>
        );
    if (
        hashResource === 'perencanaan-aset' &&
        permissions.includes('management-aset.perencanaan-aset.read')
    )
        return (
            <main>
                <PlanningPage context={assetContext} permissions={permissions} />
            </main>
        );
    if (
        hashResource === 'validasi-status-work-order' &&
        permissions.includes('management-aset.validasi-status-work-order.read')
    )
        return (
            <main>
                <StatusValidationPage permissions={permissions} />
            </main>
        );
    // Pemeliharaan aset tidak lagi memakai halaman dokumen siklus generik: ia kini work
    // order dengan baris pekerjaan, checklist, penugasan, dan status pengerjaan sendiri.
    if (
        hashResource === 'pemeliharaan-aset' &&
        permissions.includes('management-aset.pemeliharaan-aset.read')
    )
        return (
            <main data-layout="full-height" className="h-full min-h-0 overflow-hidden">
                <WorkOrderPage
                    context={assetContext}
                    permissions={permissions}
                    segments={segments}
                />
            </main>
        );
    const lifecycle = Object.fromEntries(
        [permintaanPembelianAset, dekomisioningAset, penjualanAset, pemusnahanAset].map(
            (config) => [config.resource, config],
        ),
    );
    if (
        lifecycle[hashResource] &&
        permissions.includes(`management-aset.${hashResource}.read` as Permission)
    )
        return (
            <main>
                <LifecycleDocumentPage context={assetContext} config={lifecycle[hashResource]} />
            </main>
        );

    if (!active) {
        return (
            <main>
                <Card>
                    <CardContent>
                        <Empty>
                            <EmptyHeader>
                                <EmptyTitle>Belum ada data yang dapat dibuka</EmptyTitle>
                                <EmptyDescription>
                                    Minta administrator memberi Anda akses master data.
                                </EmptyDescription>
                            </EmptyHeader>
                        </Empty>
                    </CardContent>
                </Card>
            </main>
        );
    }

    // Sementara hanya sebagian master yang memakai tata letak daftar-detail. Saat seluruh
    // master pindah, yang dihapus adalah cabang ini beserta MasterPage.
    const Page = DETAIL_LAYOUT_RESOURCES.includes(active.resource) ? MasterDetailPage : MasterPage;

    return (
        <main
            data-layout={Page === MasterDetailPage ? 'full-height' : undefined}
            className={Page === MasterDetailPage ? 'h-full min-h-0 overflow-hidden' : undefined}
        >
            <Page key={active.resource} config={active} permissions={permissions} />
        </main>
    );
}
