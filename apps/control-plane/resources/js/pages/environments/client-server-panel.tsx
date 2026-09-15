import { Button } from '@apperp/ui/button';
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
        address: string | null;
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
        <section className="space-y-4 rounded-lg border border-amber-300 bg-amber-50 p-5 text-amber-950 dark:border-amber-900/60 dark:bg-amber-950/40 dark:text-amber-50">
            <div>
                <h3 className="text-base font-semibold">
                    Perintah pasang siap
                </h3>
                <p className="mt-1 text-sm">
                    <strong className="font-semibold">
                        Perintah dan kata sandi ini tidak akan muncul lagi.
                    </strong>{' '}
                    Salin sekarang. Menutup atau memuat ulang halaman ini
                    menghilangkannya; membuat perintah baru membatalkan yang
                    ini.
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
                    className="overflow-x-auto rounded-md border border-amber-200 bg-background p-3 font-mono text-xs break-all whitespace-pre-wrap dark:border-amber-900/60"
                    data-test="install-command"
                >
                    {issued.command}
                </pre>
            </div>

            <dl className="space-y-2">
                <div className="flex flex-wrap items-center justify-between gap-3 rounded-md border border-amber-200 bg-background px-4 py-3 dark:border-amber-900/60">
                    <div className="min-w-0">
                        <dt className="text-xs text-muted-foreground">
                            Email admin klien
                        </dt>
                        <dd className="truncate font-mono text-sm">
                            {issued.email}
                        </dd>
                    </div>
                    <CopyButton text={issued.email} label="Salin" />
                </div>
                <div className="flex flex-wrap items-center justify-between gap-3 rounded-md border border-amber-200 bg-background px-4 py-3 dark:border-amber-900/60">
                    <div className="min-w-0">
                        <dt className="text-xs text-muted-foreground">
                            Kata sandi sementara
                        </dt>
                        <dd className="truncate font-mono text-lg font-semibold tracking-wide">
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
    const { data, setData, post, processing, errors } = useForm({
        server_address: '',
        address: '',
        update_window_start: '',
        update_window_end: '',
    });

    function submit(e: FormEvent) {
        e.preventDefault();
        post(`/lingkungan/${environmentId}/server-klien`, {
            preserveScroll: true,
        });
    }

    return (
        <form className="space-y-4" onSubmit={submit}>
            <p className="text-sm text-muted-foreground">
                Produksi tenant ini berjalan di server milik klien, bukan di
                server kita. Menyiapkannya mencatat server itu — namanya, app
                yang dibeli, dan rilisnya diambil sistem. Belum ada yang
                dipasang sampai perintah pasangnya dijalankan.
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
                    summary="Alamat aplikasi dan jendela pembaruan, boleh diisi belakangan"
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
    const { data, setData, patch, processing, errors } = useForm({
        server_address: site.serverAddress ?? '',
        address: site.address ?? '',
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
        <CollapsibleSectionGroup>
            <CollapsibleSection
                value="setelan"
                title="Setelan server"
                summary={summary}
            >
                <form
                    className="space-y-4"
                    onSubmit={(e: FormEvent) => {
                        e.preventDefault();
                        patch(`/lingkungan/${environmentId}/server-klien`, {
                            preserveScroll: true,
                        });
                    }}
                >
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
                    <Button
                        type="submit"
                        variant="outline"
                        disabled={processing}
                    >
                        Simpan setelan
                    </Button>
                </form>
            </CollapsibleSection>
        </CollapsibleSectionGroup>
    );
}

function Line({ label, children }: { label: string; children: ReactNode }) {
    return (
        <div className="flex flex-wrap justify-between gap-4 border-b py-2.5 last:border-b-0">
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
    const { progress, site } = serverClient;
    const [issuing, setIssuing] = useState(false);

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
            {},
            {
                preserveScroll: true,
                onStart: () => setIssuing(true),
                onFinish: () => setIssuing(false),
            },
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
        <section
            className="space-y-4 rounded-lg border bg-background p-5"
            data-test="server-client-panel"
        >
            <div className="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <h2 className="text-sm font-semibold">Server klien</h2>
                    <p className="mt-1 max-w-2xl text-sm text-muted-foreground">
                        <StateSentence serverClient={serverClient} />
                    </p>
                </div>
                <div className="flex items-center gap-2">
                    {!progress.final && (
                        <Spinner aria-label="Memantau keadaan pemasangan" />
                    )}
                    <InstallStateBadge state={progress.state} />
                </div>
            </div>

            {error && (
                <div
                    role="alert"
                    className="rounded-md border border-destructive/40 bg-red-50 px-4 py-3 text-sm break-words whitespace-pre-line text-destructive dark:bg-red-950/40 dark:text-red-200"
                >
                    {error}
                </div>
            )}

            {installCommand && <InstallCommandCard issued={installCommand} />}

            {progress.state === 'not_prepared' ? (
                <Prepare environmentId={environmentId} />
            ) : (
                <>
                    <dl>
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
                                    <span className="font-normal text-muted-foreground">
                                        Belum dicatat — isi di Setelan server
                                    </span>
                                )}
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

                    {canIssue && (
                        <div className="flex flex-wrap items-center gap-3">
                            <Button
                                type="button"
                                onClick={issueCommand}
                                disabled={issuing}
                                variant={
                                    progress.state === 'awaiting_command'
                                        ? 'outline'
                                        : 'default'
                                }
                            >
                                {issuing && <Spinner />}
                                {issueLabel}
                            </Button>
                            {progress.state !== 'no_command' && (
                                <p className="max-w-xl text-xs text-muted-foreground">
                                    Perintah baru membatalkan perintah dan kata
                                    sandi sementara yang dibuat sebelumnya.
                                </p>
                            )}
                        </div>
                    )}

                    {site && progress.state !== 'revoked' && (
                        <EditSettings
                            environmentId={environmentId}
                            site={site}
                        />
                    )}
                </>
            )}
        </section>
    );
}
