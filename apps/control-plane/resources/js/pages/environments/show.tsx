import { FormErrorHeader } from '@/components/form-error-header';
import { useInertiaFeedback } from '@/hooks/use-inertia-feedback';
import { Button } from '@apperp/ui/button';
import { Card, CardHeader, CardTitle, CardContent } from '@apperp/ui/card';
import {
    CollapsibleSection,
    CollapsibleSectionGroup,
} from '@apperp/ui/collapsible-section';
import { DataTable } from '@apperp/ui/data-table';
import { Spinner } from '@apperp/ui/spinner';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { ArrowLeft, ExternalLink } from 'lucide-react';
import { useState } from 'react';
import type { ReactNode } from 'react';
import {
    InstallStateBadge,
    KindBadge,
    ModuleStatusBadge,
    StatusBadge,
} from '@/components/badges';
import Shell from '@/components/shell';
import { labelFor, operationLabels, resultLabels } from '@/lib/display';
import ClientServerPanel from '@/pages/environments/client-server-panel';
import type {
    InstallCommand,
    ServerClient,
} from '@/pages/environments/client-server-panel';
import CopySandboxPanel from '@/pages/environments/copy-sandbox-panel';
import type { CopyState } from '@/pages/environments/copy-sandbox-panel';

type Environment = {
    id: string;
    name: string;
    slug: string;
    kind: string;
    status: string;
    hosting: string;
    outboundAllowed: boolean;
    database: string;
    ownDatabase: boolean;
    url: string | null;
    expiresAt: string | null;
    tenant: string;
    createdAt: string | null;
};

type Operation = {
    id: string;
    operation: string;
    status: string;
    step: string | null;
    reason: string | null;
    startedAt: string;
    finishedAt: string | null;
    requestedBy: string;
};

type Module = {
    id: string;
    name: string;
    version: string;
    status: 'installed' | 'disabled' | 'uninstalled';
    seeded: boolean;
};

function Row({ label, children }: { label: string; children: ReactNode }) {
    return (
        <div className="grid gap-1 border-b py-3 last:border-b-0 sm:grid-cols-[9rem_minmax(0,1fr)] sm:gap-4">
            <dt className="text-sm text-muted-foreground">{label}</dt>
            <dd className="min-w-0 text-sm break-words">{children}</dd>
        </div>
    );
}

export default function Show({
    environment,
    history,
    modules,
    modulesError,
    canProvision,
    serverClient,
    installCommand,
    installedRelease,
    copyState,
}: {
    environment: Environment;
    history: Operation[];
    modules: Module[];
    modulesError: string | null;
    canProvision: boolean;
    copyState: CopyState | null;
    serverClient: ServerClient | null;
    installCommand: InstallCommand | null;
    installedRelease: { version: string | null; error: string | null };
}) {
    const { wrapRouter: wrapRouterRequest, summary: routerRequestSummary } =
        useInertiaFeedback();

    const last = history[0];
    const [running, setRunning] = useState(false);
    const retry = environment.status === 'degraded';
    // Produksi di server klien tidak punya database di server kita. Spanduk penyiapan dan daftar
    // module membaca server kita, jadi keduanya digantikan panel "Server klien".
    const onClientServer = environment.hosting === 'client_server';
    const copyNotReady =
        environment.kind === 'sandbox' &&
        copyState !== null &&
        environment.status !== 'active';

    // Penyiapan gagal dipulangkan sebagai galat validasi bernama `provision`, bukan sebagai prop
    // tersendiri. Karena `router.post` dipakai di sini alih-alih `useForm`, tidak ada objek
    // formulir yang menampungnya — ia dibaca langsung dari props halaman.
    const provisionError = usePage().props.errors.provision;

    // Lingkungan aktif boleh memakai database bersama; tanpa database sendiri bukan berarti belum siap.
    const showBanner = !onClientServer && canProvision;
    const sentence = retry
        ? 'Penyiapan sebelumnya belum selesai. Coba lagi untuk melanjutkan langkah yang tersisa.'
        : environment.ownDatabase
          ? 'Database sudah tersedia. Selesaikan penyiapan agar lingkungan dapat dibuka.'
          : 'Lingkungan ini baru tercatat di registry. Ia belum punya database sendiri, jadi belum ada yang dapat memasukinya — hanya lingkungan berstatus Aktif yang dapat dirutekan.';

    function provision() {
        router.post(
            `/lingkungan/${environment.id}/siapkan`,
            {},
            wrapRouterRequest({
                preserveScroll: true,
                onStart: () => setRunning(true),
                onFinish: () => setRunning(false),
            }),
        );
    }

    return (
        <Shell
            title={environment.name}
            description={`Milik ${environment.tenant}`}
        >
            {routerRequestSummary}
            <Head title={environment.name} />
            <div className="flex flex-wrap items-center gap-3 text-sm">
                <Link
                    href="/lingkungan"
                    className="inline-flex items-center gap-1.5 text-muted-foreground hover:text-foreground"
                >
                    <ArrowLeft className="size-4" aria-hidden="true" />
                    Daftar lingkungan
                </Link>
                <KindBadge kind={environment.kind} />
                {serverClient ? (
                    <InstallStateBadge state={serverClient.progress.state} />
                ) : (
                    <StatusBadge status={environment.status} />
                )}
                {environment.url &&
                    (onClientServer
                        ? ['ready', 'stale'].includes(
                              serverClient?.progress.state ?? '',
                          )
                        : environment.status === 'active') && (
                        <Button asChild variant="ghost" size="sm">
                            <a
                                href={environment.url}
                                target="_blank"
                                rel="noreferrer"
                            >
                                <ExternalLink className="size-4" />
                                Buka lingkungan
                            </a>
                        </Button>
                    )}
            </div>
            <FormErrorHeader
                message={provisionError}
                operation="Lingkungan belum dapat disiapkan"
            />
            {showBanner && (
                <section className="space-y-3 rounded-md border border-warning/30 bg-warning/10 px-4 py-3 text-sm text-warning">
                    <p>{sentence}</p>

                    {canProvision && (
                        <div className="flex flex-wrap items-center gap-3">
                            <Button
                                type="button"
                                onClick={provision}
                                disabled={running}
                            >
                                {running && <Spinner />}
                                {running
                                    ? 'Sedang menyiapkan…'
                                    : retry
                                      ? 'Coba siapkan lagi'
                                      : 'Siapkan'}
                            </Button>
                            {running && (
                                <p className="text-xs">
                                    Aplikasi sedang disiapkan. Proses ini dapat
                                    memakan puluhan detik. Biarkan halaman ini
                                    terbuka.
                                </p>
                            )}
                        </div>
                    )}
                </section>
            )}

            {copyState && (
                <CopySandboxPanel
                    copy={copyState}
                    environmentId={environment.id}
                />
            )}

            {serverClient && (
                <ClientServerPanel
                    environmentId={environment.id}
                    serverClient={serverClient}
                    installCommand={installCommand}
                />
            )}

            <div className="grid items-start gap-5 xl:grid-cols-[minmax(0,2fr)_minmax(18rem,1fr)]">
                <Card>
                    <CardHeader>
                        <CardTitle>Akses dan pengaturan</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <dl>
                            {environment.url && (
                                <Row label="Alamat">
                                    <a
                                        href={environment.url}
                                        target="_blank"
                                        rel="noreferrer"
                                        className="font-mono text-xs break-all underline underline-offset-4"
                                    >
                                        {environment.url}
                                    </a>
                                    {onClientServer
                                        ? // Hanya selama belum terpasang. Server yang sudah melapor, atau pernah
                                          // melapor lalu berhenti, sudah melewati kedua langkah itu.
                                          !['ready', 'stale'].includes(
                                              serverClient?.progress.state ??
                                                  '',
                                          ) && (
                                              <span className="ms-2 text-xs text-muted-foreground">
                                                  — terbuka setelah record DNS
                                                  dan pemasangan di server klien
                                                  selesai
                                              </span>
                                          )
                                        : environment.status !== 'active' && (
                                              <span className="ms-2 text-xs text-muted-foreground">
                                                  — belum dapat dibuka sampai
                                                  statusnya Aktif
                                              </span>
                                          )}
                                </Row>
                            )}

                            <Row label="Berjalan di">
                                {onClientServer ? (
                                    <span>
                                        Server klien
                                        {serverClient?.site?.serverAddress && (
                                            <span className="block font-mono text-xs font-normal text-muted-foreground">
                                                {
                                                    serverClient.site
                                                        .serverAddress
                                                }
                                            </span>
                                        )}
                                    </span>
                                ) : (
                                    'Server kita'
                                )}
                            </Row>

                            <Row label="Versi terpasang">
                                <div className="max-w-md">
                                    <span className="font-mono text-xs">
                                        {installedRelease.version ??
                                            'Belum diketahui'}
                                    </span>
                                    {!installedRelease.version && (
                                        <p className="mt-1 text-xs font-normal text-muted-foreground">
                                            {installedRelease.error ??
                                                (onClientServer
                                                    ? 'Agen belum melaporkan versi yang terpasang.'
                                                    : 'Runtime Core belum mencatat nomor rilisnya.')}
                                        </p>
                                    )}
                                </div>
                            </Row>
                            <Row label="Berlaku sampai">
                                {environment.expiresAt ?? 'Tanpa batas waktu'}
                            </Row>
                        </dl>
                        <CollapsibleSectionGroup className="mt-4">
                            <CollapsibleSection
                                value="technical"
                                title="Informasi teknis"
                                summary="Identitas, database, dan pengiriman keluar"
                            >
                                <dl>
                                    <Row label="Slug">
                                        <span className="font-mono text-xs">
                                            {environment.slug}
                                        </span>
                                    </Row>
                                    <Row label="ID">
                                        <span className="font-mono text-xs">
                                            {environment.id}
                                        </span>
                                    </Row>
                                    {onClientServer ? (
                                        <Row label="Database">
                                            Di server klien
                                        </Row>
                                    ) : (
                                        <>
                                            <Row label="Database">
                                                <span className="font-mono text-xs">
                                                    {environment.database}
                                                </span>
                                            </Row>
                                            <Row label="Database sendiri">
                                                {environment.ownDatabase
                                                    ? 'Ya'
                                                    : 'Tidak'}
                                            </Row>
                                        </>
                                    )}
                                    <Row label="Pengiriman keluar">
                                        {environment.outboundAllowed
                                            ? 'Diizinkan'
                                            : 'Dinonaktifkan'}
                                    </Row>

                                    <Row label="Dibuat">
                                        {environment.createdAt ?? '—'}
                                    </Row>
                                </dl>
                            </CollapsibleSection>
                        </CollapsibleSectionGroup>
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <CardTitle>Aktivitas terakhir</CardTitle>
                    </CardHeader>
                    <CardContent>
                        {last ? (
                            <dl>
                                <Row label="Jenis">
                                    {labelFor(operationLabels, last.operation)}
                                </Row>
                                <Row label="Hasil">
                                    {labelFor(resultLabels, last.status)}
                                </Row>
                                <Row label="Langkah">{last.step ?? '—'}</Row>
                                <Row label="Waktu mulai">{last.startedAt}</Row>
                                <Row label="Dimulai oleh">
                                    {last.requestedBy}
                                </Row>
                                {last.reason && (
                                    <Row label="Alasan gagal">
                                        <span className="text-destructive">
                                            {last.reason}
                                        </span>
                                    </Row>
                                )}
                            </dl>
                        ) : (
                            <p className="text-sm text-muted-foreground">
                                Belum ada operasi yang tercatat.
                            </p>
                        )}
                    </CardContent>
                </Card>
            </div>

            {!onClientServer && (
                <Card>
                    <CardHeader>
                        <CardTitle>Aplikasi di lingkungan ini</CardTitle>
                        <span className="text-xs text-muted-foreground">
                            {copyNotReady
                                ? 'Belum tersedia'
                                : modulesError
                                  ? 'Belum terbaca'
                                  : `${modules.length} aplikasi`}
                        </span>
                    </CardHeader>
                    <CardContent className="space-y-3">
                        <p className="text-sm text-muted-foreground">
                            Daftar pemasangan di lingkungan ini. Hak penggunaan
                            tenant dapat berbeda dengan aplikasi yang sudah
                            terpasang.
                        </p>
                        {copyNotReady ? (
                            <p className="text-sm text-muted-foreground">
                                Daftar aplikasi akan tersedia setelah penyalinan
                                selesai.
                            </p>
                        ) : modulesError ? (
                            <FormErrorHeader
                                message={modulesError}
                                operation="Daftar aplikasi belum dapat dibaca"
                            />
                        ) : (
                            <DataTable
                                data={modules}
                                getRowKey={(item) => item.id}
                                showRowNumbers={false}
                                emptyMessage={
                                    canProvision
                                        ? 'Belum ada aplikasi yang tercatat. Selesaikan penyiapan lingkungan terlebih dahulu.'
                                        : 'Belum ada aplikasi yang terpasang di lingkungan ini.'
                                }
                                columns={[
                                    {
                                        id: 'name',
                                        header: 'Aplikasi',
                                        minWidth: 220,
                                        cell: (item) => (
                                            <div className="space-y-1 py-1">
                                                <p className="font-medium">
                                                    {item.name}
                                                </p>
                                                <p className="font-mono text-xs text-muted-foreground">
                                                    {item.id}
                                                </p>
                                            </div>
                                        ),
                                    },
                                    {
                                        id: 'version',
                                        header: 'Versi',
                                        minWidth: 120,
                                        cell: (item) => item.version,
                                    },
                                    {
                                        id: 'status',
                                        header: 'Status pemasangan',
                                        minWidth: 180,
                                        cell: (item) => (
                                            <ModuleStatusBadge
                                                status={item.status}
                                            />
                                        ),
                                    },
                                    {
                                        id: 'seeded',
                                        header: 'Data awal',
                                        minWidth: 150,
                                        cell: (item) =>
                                            item.seeded
                                                ? 'Sudah terisi'
                                                : 'Belum terisi',
                                    },
                                ]}
                            />
                        )}
                    </CardContent>
                </Card>
            )}
            <Card>
                <CardHeader>
                    <CardTitle>Riwayat aktivitas</CardTitle>
                    <span className="text-xs text-muted-foreground">
                        {history.length} aktivitas
                    </span>
                </CardHeader>
                <CardContent>
                    <DataTable
                        data={history}
                        getRowKey={(operation) => operation.id}
                        showRowNumbers={false}
                        emptyMessage="Belum ada aktivitas penyiapan atau pembaruan yang tercatat."
                        columns={[
                            {
                                id: 'operation',
                                header: 'Aktivitas',
                                minWidth: 180,
                                cell: (operation) =>
                                    labelFor(
                                        operationLabels,
                                        operation.operation,
                                    ),
                            },
                            {
                                id: 'status',
                                header: 'Hasil',
                                minWidth: 180,
                                cell: (operation) => (
                                    <div className="space-y-1">
                                        <p>
                                            {labelFor(
                                                resultLabels,
                                                operation.status,
                                            )}
                                        </p>
                                        {operation.reason && (
                                            <p className="text-xs text-destructive">
                                                {operation.reason}
                                            </p>
                                        )}
                                    </div>
                                ),
                            },
                            {
                                id: 'step',
                                header: 'Langkah',
                                minWidth: 160,
                                cell: (operation) =>
                                    operation.step ?? 'Tidak tercatat',
                            },
                            {
                                id: 'started',
                                header: 'Mulai',
                                minWidth: 160,
                                cell: (operation) => operation.startedAt,
                            },
                            {
                                id: 'finished',
                                header: 'Selesai',
                                minWidth: 160,
                                cell: (operation) =>
                                    operation.finishedAt ?? 'Tidak tercatat',
                            },
                            {
                                id: 'by',
                                header: 'Dimulai oleh',
                                minWidth: 160,
                                cell: (operation) => operation.requestedBy,
                            },
                        ]}
                    />
                </CardContent>
            </Card>
        </Shell>
    );
}
