import { lazy, useMemo } from 'react';
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
import { config as pemusnahanAset } from './transactions/pemusnahan-aset/config';
import { config as penjualanAset } from './transactions/penjualan-aset/config';
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
const PengaturanAsetTetapPlaceholderPage = lazy(
    () => import('./pengaturan-aset-tetap/PengaturanAsetTetapPlaceholderPage'),
);

/**
 * Dokumen siklus hidup aset, berkunci id entri menunya.
 *
 * Keempat konfigurasinya tetap, jadi petanya disusun sekali di lingkup modul. Berkas
 * `config.ts` hanya berisi data dan menyebut halamannya lewat `import type`, sehingga
 * menyebutnya di sini tidak menarik `LifecycleDocumentPage` keluar dari potongannya.
 */
const LIFECYCLE = Object.fromEntries(
    [
        permintaanPembelianAset,
        dekomisioningAset,
        penjualanAset,
        pemusnahanAset,
    ].map((config) => [config.resource, config]),
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
                />
            </main>
        );
    }

    if (
        view === 'fixed-aset-parameters' &&
        permissions.includes('management-aset.fixed-asset-parameters.read')
    ) {
        return (
            <main>
                <PengaturanAsetTetapPlaceholderPage kind="parameters" />
            </main>
        );
    }

    if (
        view === 'fixed-aset-posting-profiles' &&
        permissions.includes('management-aset.fixed-asset-posting-profiles.read')
    ) {
        return (
            <main>
                <PengaturanAsetTetapPlaceholderPage kind="posting-profiles" />
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
            <main>
                <MonitoringPage />
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
