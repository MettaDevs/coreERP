import { lazy, useMemo } from 'react';
import type { ComponentType, LazyExoticComponent } from 'react';
import { Card, CardContent } from '@apperp/ui/card';
import {
    Empty,
    EmptyDescription,
    EmptyHeader,
    EmptyTitle,
} from '@apperp/ui/empty';
import type { Permission } from './master/masters';
import { DETAIL_LAYOUT_RESOURCES, MASTERS, permission } from './master/masters';
import { config as dekomisioningAset } from './transactions/dekomisioning-aset/config';
import { config as permintaanPembelianAset } from './transactions/permintaan-pembelian-aset/config';

/**
 * Properti yang dikirim `HalamanModulController` pada setiap layar module ini.
 *
 * Ketiganya dulu dikumpulkan sendiri oleh UI: alamat dibaca dari ruas sesudah tanda pagar,
 * izin diambil lewat permintaan kedua ke `GET /context`, dan konteks dikirim shell sebagai
 * pesan antar bingkai. Ketiganya sekarang sudah ada sejak halaman pertama kali tampil.
 */
export type PropsModul = {
    /** Id entri menu pada `app.yaml`, misalnya `group-aset`. */
    view: string;
    /** Ruas sesudah id menu, misalnya `['01JQ…', 'ubah']`. */
    segments: string[];
    /** Izin efektif pengguna untuk module ini. */
    permissions: string[];
    konteks: {
        legal_entity_id: string | null;
        org_unit_id: string | null;
        user_id: string;
    };
};

/*
 * Satu pemuat malas per entri menu.
 *
 * Dulu kode tiap menu terpisah dengan sendirinya karena tiap app berjalan di bingkainya
 * sendiri. Setelah UI menyatu dengan shell, yang memisahkannya adalah `React.lazy`:
 * membuka satu menu hanya mengunduh potongan miliknya.
 *
 * Pemuatnya dibuat sekali di lingkup modul, bukan di dalam badan komponen. `React.lazy`
 * menghasilkan tipe komponen baru setiap kali dipanggil, jadi memanggilnya saat render
 * membuat React membongkar dan memasang ulang layarnya pada setiap render — state layar
 * hilang, dan setiap permintaan datanya berjalan lagi.
 */
const MasterPage = lazy(() => import('./master/MasterPage'));
const MasterDetailPage = lazy(() => import('./master/detail/MasterDetailPage'));
const AsetPage = lazy(
    () => import('./transactions/inventarisasi-aset/AsetPage'),
);
const DepreciationPage = lazy(
    () => import('./transactions/inventarisasi-aset/DepreciationPage'),
);
const LifecycleDocumentPage = lazy(
    () => import('./transactions/_shared/LifecycleDocumentPage'),
);
const MutationPage = lazy(
    () => import('./transactions/mutasi-aset/MutationPage'),
);
const DisposalPage = lazy(() => import('./transactions/disposal/DisposalPage'));
const ValueAdjustmentPage = lazy(
    () => import('./transactions/value-adjustment/ValueAdjustmentPage'),
);
const MonitoringPage = lazy(
    () => import('./transactions/monitoring-aset/MonitoringPage'),
);
const PlanningPage = lazy(
    () => import('./transactions/perencanaan-aset/PlanningPage'),
);
const StatusValidationPage = lazy(
    () => import('./transactions/pemeliharaan-aset/StatusValidationPage'),
);
const WorkOrderPage = lazy(
    () => import('./transactions/pemeliharaan-aset/WorkOrderPage'),
);
const MaintenanceRequestPage = lazy(
    () => import('./transactions/maintenance-requests/MaintenanceRequestPage'),
);
const MaintenanceSchedulePage = lazy(
    () => import('./transactions/maintenance-schedule/MaintenanceSchedulePage'),
);
const CounterReadingPage = lazy(
    () => import('./transactions/counter-readings/CounterReadingPage'),
);
const InsurancePage = lazy(
    () => import('./transactions/insurance/InsurancePage'),
);
const WarrantyPage = lazy(() => import('./transactions/warranty/WarrantyPage'));
const DowntimePage = lazy(() => import('./transactions/downtime/DowntimePage'));
const PengaturanAsetTetapPage = lazy(
    () => import('./pengaturan-aset-tetap/PengaturanAsetTetapPage'),
);
const AssetPostingGroupPage = lazy(
    () => import('./asset-posting-group/AssetPostingGroupPage'),
);

/**
 * Halaman laporan, berkunci id entri menunya.
 *
 * Semuanya berbentuk sama — satu izin baca, satu halaman tanpa props — jadi yang
 * membedakannya hanya data, dan data itu ditulis di satu peta, bukan satu cabang `if`
 * per laporan. Izinnya sama dengan izin entri menu di `app.yaml` dan izin definisi
 * laporannya; laporan baru cukup menambah satu entri di sini.
 */
const REPORT_PAGES: Record<
    string,
    { permission: string; Page: LazyExoticComponent<ComponentType> }
> = {
    'laporan-penyusutan-aset': {
        permission: 'management-aset.penyusutan.read',
        Page: lazy(() => import('./laporan/LaporanPenyusutanAsetPage')),
    },
    'laporan-mutasi-aset': {
        permission: 'management-aset.mutasi-aset.read',
        Page: lazy(() => import('./laporan/LaporanMutasiAsetPage')),
    },
    'laporan-monitoring-aset': {
        permission: 'management-aset.monitoring-aset.read',
        Page: lazy(() => import('./laporan/LaporanMonitoringAsetPage')),
    },
    'laporan-pemeliharaan-aset': {
        permission: 'management-aset.pemeliharaan-aset.read',
        Page: lazy(() => import('./laporan/LaporanPemeliharaanAsetPage')),
    },
    'kpi-pemeliharaan': {
        permission: 'management-aset.kpi-pemeliharaan.read',
        Page: lazy(() => import('./transactions/downtime/MaintenanceKpiPage')),
    },
    'laporan-penjualan-aset': {
        permission: 'management-aset.penjualan-aset.read',
        Page: lazy(() => import('./laporan/LaporanPenjualanAsetPage')),
    },
    'laporan-pemusnahan-aset': {
        permission: 'management-aset.pemusnahan-aset.read',
        Page: lazy(() => import('./laporan/LaporanPemusnahanAsetPage')),
    },
};

/**
 * Dokumen siklus hidup aset, berkunci id entri menunya.
 *
 * Konfigurasinya tetap, jadi petanya disusun sekali di lingkup modul. Penjualan dan
 * pemusnahan tidak di sini lagi: keduanya draf yang diposting, dengan halaman sendiri. Berkas
 * `config.ts` hanya berisi data dan menyebut halamannya lewat `import type`, sehingga
 * menyebutnya di sini tidak menarik `LifecycleDocumentPage` keluar dari potongannya.
 */
const LIFECYCLE = Object.fromEntries(
    [permintaanPembelianAset, dekomisioningAset].map((config) => [
        config.resource,
        config,
    ]),
);

export default function App({
    view,
    segments,
    permissions,
    konteks,
}: PropsModul) {
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
    const active =
        visible.find((master) => master.resource === view) ?? visible[0];

    // Id view mengikuti nama prosesnya; permission tetap `aset` karena ia kontrak.
    if (
        view === 'inventarisasi-aset' &&
        permissions.includes('management-aset.aset.read')
    ) {
        return (
            <main
                data-layout="full-height"
                className="h-full min-h-0 overflow-hidden"
            >
                <AsetPage
                    context={konteks}
                    canUpdate={permissions.includes(
                        'management-aset.aset.update',
                    )}
                    permissions={permissions}
                    segments={segments}
                />
            </main>
        );
    }

    if (
        view === 'penyusutan' &&
        permissions.includes('management-aset.penyusutan.read')
    ) {
        return (
            <main>
                <DepreciationPage
                    canCreate={permissions.includes(
                        'management-aset.penyusutan.create',
                    )}
                    canFinalize={permissions.includes(
                        'management-aset.penyusutan.finalize',
                    )}
                    canCorrect={permissions.includes(
                        'management-aset.penyusutan.correct',
                    )}
                    canPost={permissions.includes(
                        'management-aset.penyusutan.post',
                    )}
                    context={konteks}
                />
            </main>
        );
    }

    if (
        view === 'fixed-aset-parameters' &&
        permissions.includes('management-aset.fixed-asset-parameters.read')
    ) {
        return (
            <main
                data-layout="full-height"
                className="h-full min-h-0 overflow-hidden"
            >
                <PengaturanAsetTetapPage permissions={permissions} />
            </main>
        );
    }

    if (
        view === 'fixed-aset-posting-profiles' &&
        permissions.includes(
            'management-aset.fixed-asset-posting-profiles.read',
        )
    ) {
        return (
            <main>
                <AssetPostingGroupPage permissions={permissions} />
            </main>
        );
    }

    if (
        view === 'mutasi-aset' &&
        permissions.includes('management-aset.mutasi-aset.read')
    ) {
        return (
            <main
                data-layout="full-height"
                className="h-full min-h-0 overflow-hidden"
            >
                <MutationPage
                    context={konteks}
                    permissions={permissions}
                    segments={segments}
                />
            </main>
        );
    }

    if (
        view === 'monitoring-aset' &&
        permissions.includes('management-aset.monitoring-aset.read')
    ) {
        return (
            <main
                data-layout="full-height"
                className="h-full min-h-0 overflow-hidden"
            >
                <MonitoringPage
                    context={konteks}
                    permissions={permissions}
                    segments={segments}
                />
            </main>
        );
    }

    if (
        (view === 'penjualan-aset' || view === 'pemusnahan-aset') &&
        permissions.includes(`management-aset.${view}.read`)
    ) {
        return (
            <main
                data-layout="full-height"
                className="h-full min-h-0 overflow-hidden"
            >
                <DisposalPage
                    resource={view}
                    permissions={permissions}
                    segments={segments}
                />
            </main>
        );
    }

    if (
        view === 'penyesuaian-nilai-aset' &&
        permissions.includes('management-aset.penyesuaian-nilai-aset.read')
    ) {
        return (
            <main
                data-layout="full-height"
                className="h-full min-h-0 overflow-hidden"
            >
                <ValueAdjustmentPage
                    context={konteks}
                    permissions={permissions}
                    segments={segments}
                />
            </main>
        );
    }

    if (
        view === 'perencanaan-aset' &&
        permissions.includes('management-aset.perencanaan-aset.read')
    ) {
        return (
            <main>
                <PlanningPage context={konteks} permissions={permissions} />
            </main>
        );
    }

    if (
        view === 'validasi-status-work-order' &&
        permissions.includes('management-aset.validasi-status-work-order.read')
    ) {
        return (
            <main>
                <StatusValidationPage permissions={permissions} />
            </main>
        );
    }

    // Pemeliharaan aset tidak lagi memakai halaman dokumen siklus generik: ia kini work
    // order dengan baris pekerjaan, checklist, penugasan, dan status pengerjaan sendiri.
    if (
        view === 'pemeliharaan-aset' &&
        permissions.includes('management-aset.pemeliharaan-aset.read')
    ) {
        return (
            <main
                data-layout="full-height"
                className="h-full min-h-0 overflow-hidden"
            >
                <WorkOrderPage
                    context={konteks}
                    permissions={permissions}
                    segments={segments}
                />
            </main>
        );
    }

    // Tiga layar pemeliharaan preventif dan reaktif: permintaan dari unit, usulan jadwal dari
    // rencana, dan pembacaan counter yang menjadi dasar rencana berbasis pemakaian.
    if (
        view === 'permintaan-pemeliharaan' &&
        permissions.includes('management-aset.permintaan-pemeliharaan.read')
    ) {
        return (
            <main
                data-layout="full-height"
                className="h-full min-h-0 overflow-hidden"
            >
                <MaintenanceRequestPage
                    context={konteks}
                    permissions={permissions}
                    segments={segments}
                />
            </main>
        );
    }

    if (
        view === 'jadwal-pemeliharaan' &&
        permissions.includes('management-aset.jadwal-pemeliharaan.read')
    ) {
        return (
            <main>
                <MaintenanceSchedulePage
                    context={konteks}
                    permissions={permissions}
                />
            </main>
        );
    }

    if (
        view === 'pembacaan-counter' &&
        permissions.includes('management-aset.pembacaan-counter.read')
    ) {
        return (
            <main>
                <CounterReadingPage
                    context={konteks}
                    permissions={permissions}
                />
            </main>
        );
    }

    // Asuransi, garansi dan kontrak servis, serta downtime: daftar dengan form di dialog.
    if (
        view === 'asuransi-aset' &&
        permissions.includes('management-aset.polis-asuransi.read')
    ) {
        return (
            <main>
                <InsurancePage context={konteks} permissions={permissions} />
            </main>
        );
    }

    if (
        view === 'garansi-kontrak-servis' &&
        (permissions.includes('management-aset.garansi-aset.read') ||
            permissions.includes('management-aset.kontrak-servis.read'))
    ) {
        return (
            <main>
                <WarrantyPage context={konteks} permissions={permissions} />
            </main>
        );
    }

    if (
        view === 'downtime-aset' &&
        permissions.includes('management-aset.downtime-aset.read')
    ) {
        return (
            <main>
                <DowntimePage permissions={permissions} />
            </main>
        );
    }

    if (
        LIFECYCLE[view] &&
        permissions.includes(`management-aset.${view}.read`)
    ) {
        return (
            <main>
                <LifecycleDocumentPage
                    context={konteks}
                    config={LIFECYCLE[view]}
                />
            </main>
        );
    }

    const report = REPORT_PAGES[view];

    if (report && permissions.includes(report.permission)) {
        return (
            <main>
                <report.Page />
            </main>
        );
    }

    if (!active) {
        return (
            <main>
                <Card>
                    <CardContent>
                        <Empty>
                            <EmptyHeader>
                                <EmptyTitle>
                                    Belum ada data yang dapat dibuka
                                </EmptyTitle>
                                <EmptyDescription>
                                    Minta administrator memberi Anda akses
                                    master data.
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
    const Page = DETAIL_LAYOUT_RESOURCES.includes(active.resource)
        ? MasterDetailPage
        : MasterPage;

    return (
        <main
            data-layout={Page === MasterDetailPage ? 'full-height' : undefined}
            className={
                Page === MasterDetailPage
                    ? 'h-full min-h-0 overflow-hidden'
                    : undefined
            }
        >
            <Page
                key={active.resource}
                config={active}
                // Layar master menyempitkan daftar izin ke kode yang dikenalnya;
                // controller mengirimkannya sebagai daftar string biasa.
                permissions={permissions as Permission[]}
            />
        </main>
    );
}
