import { FormErrorHeader } from '@/components/form-error-header';
import { useInertiaFeedback } from '@/hooks/use-inertia-feedback';
import { ActionButton } from '@apperp/ui/action-button';
import { Button } from '@apperp/ui/button';
import {
    Card,
    CardHeader,
    CardTitle,
    CardContent,
    CardAction,
} from '@apperp/ui/card';
import {
    CollapsibleSection,
    CollapsibleSectionGroup,
} from '@apperp/ui/collapsible-section';
import { Spinner } from '@apperp/ui/spinner';
import { Link, router, useForm, usePage, usePoll } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import type { FormEvent, ReactNode } from 'react';
import { InstallStateBadge } from '@/components/badges';
import CopyButton from '@/components/copy-button';
import DnsStatus from '@/components/dns-status';
import type { DnsInfo } from '@/components/dns-status';
import InstallOptionsFields from '@/components/install-options';
import type { InstallOptionValues } from '@/components/install-options';
import {
    ServerAddressField,
    ServerAdvancedFields,
} from '@/components/server-settings-fields';
import type { InstallProgress } from '@/lib/install-progress';

export type ServerClient = {
    site: {
        id: string;
        name: string;
        serverAddress: string | null;
        lastSeenIp: string | null;
        appUrl: string | null;
        dns: DnsInfo;
        updateWindow: { start: string; end: string; timezone: string } | null;
        enrolledAt: string | null;
    } | null;
    progress: InstallProgress;
    newestRelease: string | null;
};

export type InstallCommand = {
    command: string;
    expiresAt: string;
    email: string;
    password: string;
    release: string | null;
};

/** Selang muat ulang parsial selama pemasangan belum mencapai keadaan akhir. */
const POLL_MS = 5000;

/**
 * Perintah pasang dan kata sandi sementara, ditampilkan sekali.
 *
 * Keduanya datang lewat flash session dan tidak tersimpan di mana pun — hanya hash kata sandinya yang
 * dikirim ke agen. Peringatannya ditulis di atas isinya, bukan di bawah: peringatan yang harus digulir
 * untuk dibaca datang terlambat.
 */
function InstallCommandCard({ issued }: { issued: InstallCommand }) {
    return (
        <section className="space-y-4 rounded-lg border border-warning/30 bg-card p-5 text-card-foreground">
            <div>
                <h3 className="text-base font-semibold">
                    Perintah pasang siap
                </h3>
                <p className="mt-1 text-sm">
                    <strong className="font-semibold">
                        Salin perintah dan kata sandi sebelum meninggalkan
                        halaman.
                    </strong>{' '}
                    Perintah baru akan membatalkan perintah sebelumnya.
                </p>
            </div>

            <div className="space-y-2">
                <div className="flex flex-wrap items-center justify-between gap-2">
                    <p className="text-xs text-muted-foreground">
                        Jalankan sekali di server klien sebagai root. Berlaku
                        sampai {issued.expiresAt}.
                    </p>
                    <CopyButton text={issued.command} label="Salin perintah" />
                </div>
                <pre
                    className="overflow-x-auto rounded-md border border-warning/30 bg-background p-3 font-mono text-xs break-all whitespace-pre-wrap"
                    data-test="install-command"
                >
                    {issued.command}
                </pre>
            </div>

            <dl className="grid gap-3 sm:grid-cols-2">
                <div className="flex flex-wrap items-center justify-between gap-3 rounded-md border border-warning/30 bg-background px-4 py-3">
                    <div className="min-w-0">
                        <dt className="text-xs text-muted-foreground">
                            Email admin klien
                        </dt>
                        <dd className="font-mono text-sm break-all">
                            {issued.email}
                        </dd>
                    </div>
                    <CopyButton text={issued.email} label="Salin" />
                </div>
                <div className="flex flex-wrap items-center justify-between gap-3 rounded-md border border-warning/30 bg-background px-4 py-3">
                    <div className="min-w-0">
                        <dt className="text-xs text-muted-foreground">
                            Kata sandi sementara
                        </dt>
                        <dd className="font-mono text-lg font-semibold tracking-wide break-all">
                            {issued.password}
                        </dd>
                    </div>
                    <CopyButton
                        text={issued.password}
                        label="Salin kata sandi"
                        variant="default"
                    />
                </div>
            </dl>

            <p className="text-xs text-muted-foreground">
                Admin klien masuk dengan email dan kata sandi ini setelah
                pemasangan selesai, lalu wajib menggantinya.
            </p>

            {issued.release === null && (
                <p className="text-sm font-medium">
                    Belum ada rilis yang terdaftar. Server klien dapat
                    tersambung, tetapi pemasangan menunggu sampai rilis
                    didaftarkan dan perintah pasang dibuat ulang.
                </p>
            )}
        </section>
    );
}

function Prepare({ environmentId }: { environmentId: string }) {
    const { wrap: wrapLocalForm0Request, summary: localForm0RequestSummary } =
        useInertiaFeedback();

    const { data, setData, post, processing, errors } = useForm({
        server_address: '',
        update_window_start: '',
        update_window_end: '',
    });

    function submit(e: FormEvent) {
        e.preventDefault();
        post(
            `/lingkungan/${environmentId}/server-klien`,
            wrapLocalForm0Request({
                preserveScroll: true,
            }),
        );
    }

    return (
        <form noValidate className="space-y-4" onSubmit={submit}>
            <>
                {localForm0RequestSummary}
                <FormErrorHeader errors={errors} />
            </>

            <p className="text-sm text-muted-foreground">
                Produksi tenant ini berjalan di server milik klien, bukan di
                server kita. Menyiapkannya mencatat server itu — namanya, app
                yang dibeli, rilis, dan alamat aplikasinya diambil sistem. Belum
                ada yang dipasang sampai perintah pasangnya dijalankan.
            </p>
            <ServerAddressField
                value={data.server_address}
                onChange={(value) => setData('server_address', value)}
                error={errors.server_address}
            />
            <CollapsibleSectionGroup>
                <CollapsibleSection
                    value="lanjutan"
                    title="Lanjutan"
                    summary="Jendela pembaruan, boleh diisi belakangan"
                >
                    <ServerAdvancedFields
                        data={data}
                        setData={setData}
                        errors={errors}
                    />
                </CollapsibleSection>
            </CollapsibleSectionGroup>
            <Button type="submit" disabled={processing}>
                {processing && <Spinner />}
                Siapkan server klien
            </Button>
        </form>
    );
}

function EditSettings({
    environmentId,
    site,
}: {
    environmentId: string;
    site: NonNullable<ServerClient['site']>;
}) {
    const { wrap: wrapLocalForm1Request, summary: localForm1RequestSummary } =
        useInertiaFeedback();

    const { data, setData, patch, processing, errors } = useForm({
        server_address: site.serverAddress ?? '',
        update_window_start: site.updateWindow?.start ?? '',
        update_window_end: site.updateWindow?.end ?? '',
    });

    const summary = [
        site.serverAddress ?? 'Alamat server belum diisi',
        site.updateWindow
            ? `pembaruan ${site.updateWindow.start}–${site.updateWindow.end}`
            : 'pembaruan kapan saja',
    ].join(' · ');

    return (
        <CollapsibleSectionGroup
            defaultValue={
                !site.serverAddress || Object.keys(errors).length > 0
                    ? ['setelan']
                    : []
            }
        >
            <CollapsibleSection
                value="setelan"
                title="Setelan server"
                summary={summary}
            >
                <form
                    noValidate
                    className="space-y-4"
                    onSubmit={(e: FormEvent) => {
                        e.preventDefault();
                        patch(
                            `/lingkungan/${environmentId}/server-klien`,
                            wrapLocalForm1Request({
                                preserveScroll: true,
                            }),
                        );
                    }}
                >
                    <>
                        {localForm1RequestSummary}
                        <FormErrorHeader errors={errors} />
                    </>

                    <ServerAddressField
                        value={data.server_address}
                        onChange={(value) => setData('server_address', value)}
                        error={errors.server_address}
                        lastSeenIp={site.lastSeenIp}
                    />
                    <ServerAdvancedFields
                        data={data}
                        setData={setData}
                        errors={errors}
                    />
                    <ActionButton
                        action="save"
                        type="submit"
                        pending={processing}
                    />
                </form>
            </CollapsibleSection>
        </CollapsibleSectionGroup>
    );
}

function Line({ label, children }: { label: string; children: ReactNode }) {
    return (
        <div className="min-w-0 space-y-1 border-b py-3 last:border-b-0">
            <dt className="text-sm text-muted-foreground">{label}</dt>
            <dd className="text-sm font-medium break-all">{children}</dd>
        </div>
    );
}

/**
 * Kalimat untuk setiap keadaan: apa yang sedang ditunggu, dan siapa yang ditunggu.
 */
function StateSentence({
    serverClient,
}: {
    serverClient: ServerClient;
}): ReactNode {
    const { progress, newestRelease } = serverClient;

    switch (progress.state) {
        case 'no_command':
            return progress.commandExpired
                ? 'Perintah pasang sebelumnya kedaluwarsa sebelum dijalankan. Buat yang baru.'
                : 'Server klien sudah dicatat. Buat perintah pasang, lalu jalankan di server klien sebagai root.';
        case 'awaiting_command':
            return `Menunggu perintah pasang dijalankan di server klien. Token di dalamnya berlaku sampai ${progress.commandExpiresAt ?? '—'}.`;
        case 'awaiting_release':
            return newestRelease
                ? `Server klien sudah tersambung, tetapi perintah pasangnya dibuat sebelum ada rilis. Rilis ${newestRelease} kini terdaftar — buat perintah pasang lagi untuk memakainya.`
                : 'Server klien sudah tersambung. Belum ada rilis yang terdaftar, jadi belum ada yang dapat dipasang.';
        case 'connected':
            return 'Server klien sudah tersambung. Agen mengambil pemasangan pada kunjungan berikutnya, biasanya dalam satu menit.';
        case 'installing':
            return 'Agen sedang memasang di server klien. Halaman ini memperbarui dirinya sendiri.';
        case 'ready':
            return 'Terpasang dan melapor. Pembaruan, cadangan, lisensi, ganti kunci, diagnosa, dan pencabutan ada di halaman server kliennya.';
        case 'stale':
            return 'Terpasang, tetapi agen berhenti melapor. Sebabnya dapat berupa server yang mati, internet klien yang putus, atau agen yang berhenti.';
        case 'failed':
            return 'Pemasangan gagal. Periksa langkah dan sebabnya, perbaiki di server klien bila perlu, lalu coba lagi.';
        case 'revoked':
            return 'Server klien ini dicabut. Aplikasinya di sana tetap berjalan; pengelolaannya yang berhenti.';
        default:
            return null;
    }
}

/**
 * Panel "Server klien" di halaman lingkungan produksi yang berjalan di server klien.
 *
 * Keadaannya dihitung server (`InstallProgress`). Selama keadaannya belum akhir — menunggu teknisi,
 * menunggu agen, atau memasang — panel memuat ulang dirinya sebagian setiap beberapa detik: hanya prop
 * `serverClient`, sehingga perintah pasang yang baru saja ditampilkan tidak ikut hilang.
 */
export default function ClientServerPanel({
    environmentId,
    serverClient,
    installCommand,
}: {
    environmentId: string;
    serverClient: ServerClient;
    installCommand: InstallCommand | null;
}) {
    const { wrapRouter: wrapRouterRequest, summary: routerRequestSummary } =
        useInertiaFeedback();

    const { progress, site } = serverClient;
    const [issuing, setIssuing] = useState(false);
    const [options, setOptions] = useState<InstallOptionValues>({
        kunci_lisensi: false,
        proxy_luar: false,
        app_port: '',
    });
    const portError = usePage().props.errors.app_port;

    /*
     * Galat panel disimpan sendiri, bukan dibaca langsung dari `errors`. Setiap muat ulang parsial
     * membawa `errors` yang baru — kosong — dan tanpa salinan ini penolakan "Core tidak menjawab"
     * hilang lima detik setelah muncul, sebelum sempat dibaca. Galat baru menggantikannya; yang kosong
     * tidak. Disesuaikan saat render, bukan di efek, supaya tidak ada satu render pun tanpa galatnya.
     */
    const incomingError = usePage().props.errors.server_client;
    const [error, setError] = useState<string | undefined>(incomingError);
    const [lastIncoming, setLastIncoming] = useState(incomingError);

    if (incomingError !== lastIncoming) {
        setLastIncoming(incomingError);

        if (incomingError) {
            setError(incomingError);
        }
    }

    const { start, stop } = usePoll(
        POLL_MS,
        { only: ['serverClient'] },
        { autoStart: false },
    );

    useEffect(() => {
        if (progress.final) {
            stop();
        } else {
            start();
        }

        return () => stop();
        // `start` dan `stop` dibuat ulang setiap render; yang menentukan hanya keadaan akhirnya.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [progress.final]);

    function issueCommand() {
        setError(undefined);
        router.post(
            `/lingkungan/${environmentId}/perintah-pasang`,
            { ...options },
            wrapRouterRequest({
                preserveScroll: true,
                onStart: () => setIssuing(true),
                onFinish: () => setIssuing(false),
            }),
        );
    }

    const canIssue = [
        'no_command',
        'awaiting_command',
        'awaiting_release',
        'failed',
    ].includes(progress.state);

    const issueLabel =
        progress.state === 'failed'
            ? 'Coba lagi'
            : progress.state === 'no_command'
              ? 'Buat perintah pasang'
              : 'Buat perintah pasang baru';

    return (
        <Card data-test="server-client-panel">
            {routerRequestSummary}
            <CardHeader>
                <div>
                    <CardTitle>Pemasangan server klien</CardTitle>
                    <p className="mt-1 max-w-2xl text-sm text-muted-foreground">
                        <StateSentence serverClient={serverClient} />
                    </p>
                </div>
                <CardAction>
                    <div className="flex items-center gap-2">
                        {!progress.final && (
                            <Spinner aria-label="Memantau keadaan pemasangan" />
                        )}
                        <InstallStateBadge state={progress.state} />
                    </div>
                </CardAction>
            </CardHeader>
            <CardContent className="space-y-5">
                {error && (
                    <div
                        role="alert"
                        className="rounded-md border border-destructive/40 bg-destructive/10 px-4 py-3 text-sm break-words whitespace-pre-line text-destructive"
                    >
                        {error}
                    </div>
                )}

                {installCommand && (
                    <InstallCommandCard issued={installCommand} />
                )}

                {progress.state === 'not_prepared' ? (
                    <Prepare environmentId={environmentId} />
                ) : (
                    <>
                        <dl className="grid gap-x-6 sm:grid-cols-2">
                            {site && (
                                <Line label="Server klien">
                                    <Link
                                        href={`/situs/${site.id}`}
                                        className="underline underline-offset-4"
                                    >
                                        {site.name}
                                    </Link>
                                </Line>
                            )}
                            {site && (
                                <Line label="Alamat server">
                                    {site.serverAddress ? (
                                        <span className="inline-flex items-center gap-1">
                                            <span className="font-mono text-xs">
                                                {site.serverAddress}
                                            </span>
                                            <CopyButton
                                                text={site.serverAddress}
                                                label="Salin alamat server"
                                                iconOnly
                                            />
                                        </span>
                                    ) : (
                                        <span className="font-normal text-warning">
                                            Belum dicatat — wajib sebelum
                                            perintah pasang, isi di Setelan
                                            server
                                        </span>
                                    )}
                                </Line>
                            )}
                            {site && (
                                <Line label="Alamat aplikasi (otomatis)">
                                    <DnsStatus
                                        siteId={site.id}
                                        appUrl={site.appUrl}
                                        dns={site.dns}
                                        serverAddress={site.serverAddress}
                                        revoked={progress.state === 'revoked'}
                                    />
                                </Line>
                            )}
                            {(progress.release ||
                                progress.state === 'awaiting_release') && (
                                <Line label="Rilis yang dipasang">
                                    {progress.release ??
                                        'Belum ada rilis yang terdaftar'}
                                </Line>
                            )}
                            {progress.reportedRelease && (
                                <Line label="Rilis terpasang">
                                    {progress.reportedRelease}
                                </Line>
                            )}
                            {progress.step && (
                                <Line label="Langkah">
                                    <span className="font-mono text-xs">
                                        {progress.step}
                                    </span>
                                </Line>
                            )}
                            {progress.failureMessage && (
                                <Line label="Sebab">
                                    <span className="whitespace-pre-line text-destructive">
                                        {progress.failureMessage}
                                    </span>
                                </Line>
                            )}
                            {progress.lastSeenAt && (
                                <Line label="Terakhir terlihat">
                                    {progress.lastSeenAt}
                                </Line>
                            )}
                        </dl>

                        {site && progress.state !== 'revoked' && (
                            <EditSettings
                                environmentId={environmentId}
                                site={site}
                            />
                        )}
                        {canIssue && (
                            <InstallOptionsFields
                                value={options}
                                onChange={setOptions}
                                portError={portError}
                                disabled={issuing}
                            />
                        )}

                        {canIssue && (
                            <div className="flex flex-wrap items-center gap-3">
                                {/*
                                Tombolnya mati tanpa alamat server, dan server menolaknya juga: record DNS
                                alamat aplikasi dibuat menunjuk alamat itu saat perintah dibuat.
                            */}
                                <Button
                                    type="button"
                                    onClick={issueCommand}
                                    disabled={issuing || !site?.serverAddress}
                                    variant={
                                        progress.state === 'awaiting_command'
                                            ? 'outline'
                                            : 'default'
                                    }
                                >
                                    {issuing && <Spinner />}
                                    {issueLabel}
                                </Button>
                                {!site?.serverAddress && (
                                    <p className="max-w-xl text-xs text-warning">
                                        Catat alamat server (IP VPS) di Setelan
                                        server lebih dulu.
                                    </p>
                                )}
                                {site?.serverAddress &&
                                    progress.state !== 'no_command' && (
                                        <p className="max-w-xl text-xs text-muted-foreground">
                                            Perintah baru membatalkan perintah
                                            dan kata sandi sementara yang dibuat
                                            sebelumnya.
                                        </p>
                                    )}
                            </div>
                        )}
                    </>
                )}
            </CardContent>
        </Card>
    );
}
