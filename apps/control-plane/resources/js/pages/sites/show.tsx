import { Button } from '@apperp/ui/button';
import { Input } from '@apperp/ui/input';
import { NativeSelect } from '@apperp/ui/native-select';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@apperp/ui/table';
import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import type { FormEvent, ReactNode } from 'react';
import { SiteStateBadge } from '@/components/badges';
import Shell from '@/components/shell';
import {
    labelFor,
    siteOperationLabels,
    siteOperationStatusLabels,
} from '@/lib/display';

type Report = {
    containers?: { service: string; state: string; health?: string | null }[];
    disk?: {
        data_free_bytes?: number | null;
        backup_free_bytes?: number | null;
    } | null;
    last_backup?: {
        at: string;
        size_bytes?: number | null;
        result: string;
    } | null;
    last_operation?: {
        id: string;
        result: string;
        step?: string | null;
    } | null;
    license_expires_at?: string | null;
    license_required?: boolean | null;
    certificate_expires_at?: string | null;
    server_time?: string;
    agent_version?: string;
};

type License = {
    validUntil: string | null;
    issuedAt: string | null;
    suspendedAt: string | null;
    notRequiredOnServer: boolean;
};

type Site = {
    id: string;
    name: string;
    tenant: string;
    environment: { id: string; name: string } | null;
    edition: string;
    state: string;
    reportedRelease: string | null;
    reportedDigest: string | null;
    lastSeenAt: string | null;
    address: string | null;
    updateWindow: { start: string; end: string; timezone: string } | null;
    enrolledAt: string | null;
    lastReport: Report | null;
    license: License;
};

type Operation = {
    id: string;
    operation: string;
    status: string;
    step: string | null;
    reason: string | null;
    release: string | null;
    requestedAt: string;
    finishedAt: string | null;
    requestedBy: string;
};

type AuditEvent = { id: string; action: string; by: string; at: string };

function Row({ label, children }: { label: string; children: ReactNode }) {
    return (
        <div className="flex flex-wrap justify-between gap-4 border-b py-2.5 last:border-b-0">
            <dt className="text-sm text-muted-foreground">{label}</dt>
            <dd className="text-sm font-medium break-all">{children}</dd>
        </div>
    );
}

function Section({
    title,
    description,
    children,
}: {
    title: string;
    description?: string;
    children: ReactNode;
}) {
    return (
        <section className="space-y-4 rounded-lg border bg-background p-5">
            <div>
                <h2 className="text-sm font-semibold">{title}</h2>
                {description && (
                    <p className="mt-1 text-sm text-muted-foreground">
                        {description}
                    </p>
                )}
            </div>
            {children}
        </section>
    );
}

function FieldError({ message }: { message?: string }) {
    return message ? (
        <p className="text-sm text-destructive">{message}</p>
    ) : null;
}

function bytes(value?: number | null): string {
    if (value === null || value === undefined) {
        return '—';
    }

    const units = ['B', 'KB', 'MB', 'GB', 'TB'];
    let size = value;
    let unit = 0;

    while (size >= 1024 && unit < units.length - 1) {
        size /= 1024;
        unit++;
    }

    return `${size.toFixed(unit === 0 ? 0 : 1)} ${units[unit]}`;
}

function ConfirmName({
    site,
    value,
    onChange,
    error,
}: {
    site: Site;
    value: string;
    onChange: (value: string) => void;
    error?: string;
}) {
    return (
        <div className="space-y-2">
            <Input
                label={`Ketik "${site.name}" untuk melanjutkan`}
                required
                value={value}
                onChange={(e) => onChange(e.target.value)}
            />
            <FieldError message={error} />
        </div>
    );
}

function Enrollment({ site }: { site: Site }) {
    const { enrollment } = usePage<{
        enrollment: { command: string; expiresAt: string } | null;
    }>().props;
    const form = useForm({ confirm_name: '' });

    return (
        <Section
            title="Pendaftaran"
            description="Perintah pasang dijalankan sekali di server klien sebagai root. Tokennya sekali pakai dan kedaluwarsa dalam satu jam; perintahnya hanya tampil sekali."
        >
            {enrollment && (
                <div className="space-y-2">
                    <pre className="overflow-x-auto rounded-md bg-muted p-3 font-mono text-xs break-all whitespace-pre-wrap">
                        {enrollment.command}
                    </pre>
                    <p className="text-xs text-muted-foreground">
                        Berlaku sampai {enrollment.expiresAt}. Menutup halaman
                        ini menghilangkannya.
                    </p>
                </div>
            )}
            <form
                className="space-y-3"
                onSubmit={(e: FormEvent) => {
                    e.preventDefault();
                    form.post(`/situs/${site.id}/pendaftaran`, {
                        preserveScroll: true,
                        onSuccess: () => form.reset(),
                    });
                }}
            >
                <ConfirmName
                    site={site}
                    value={form.data.confirm_name}
                    onChange={(v) => form.setData('confirm_name', v)}
                    error={form.errors.confirm_name}
                />
                <Button type="submit" disabled={form.processing}>
                    Buat perintah pasang
                </Button>
            </form>
        </Section>
    );
}

function RequestOperation({
    site,
    operations,
    releases,
    licenseKeyConfigured,
    licenseValidDays,
}: {
    site: Site;
    operations: string[];
    releases: string[];
    licenseKeyConfigured: boolean;
    licenseValidDays: number;
}) {
    const { data, setData, post, processing, errors, reset } = useForm({
        operation: 'backup',
        release: releases[0] ?? '',
        valid_until: '',
        confirm_name: '',
    });

    return (
        <Section
            title="Minta operasi"
            description="Agen mengambilnya pada kunjungan berikutnya. Pembaruan hanya diambil di dalam jendela pembaruan; operasi lain kapan saja."
        >
            <form
                className="space-y-3"
                onSubmit={(e: FormEvent) => {
                    e.preventDefault();
                    post(`/situs/${site.id}/operasi`, {
                        preserveScroll: true,
                        onSuccess: () => reset('confirm_name'),
                    });
                }}
            >
                <NativeSelect
                    label="Operasi"
                    value={data.operation}
                    onChange={(e) => setData('operation', e.target.value)}
                >
                    {/*
                        Daftarnya dari server, bukan dari seluruh label. Label memuat `install`
                        untuk riwayat, dan pemasangan hanya lahir dari "Buat perintah pasang".
                    */}
                    {operations.map((key) => (
                        <option key={key} value={key}>
                            {labelFor(siteOperationLabels, key)}
                        </option>
                    ))}
                </NativeSelect>

                {data.operation === 'upgrade' &&
                    (releases.length === 0 ? (
                        <p className="text-sm text-muted-foreground">
                            Belum ada rilis terdaftar yang lebih baru dari rilis
                            terpasang untuk edisi {site.edition}.
                        </p>
                    ) : (
                        <NativeSelect
                            label="Rilis"
                            value={data.release}
                            onChange={(e) => setData('release', e.target.value)}
                        >
                            {releases.map((release) => (
                                <option key={release} value={release}>
                                    {release}
                                </option>
                            ))}
                        </NativeSelect>
                    ))}

                {data.operation === 'install_license' && (
                    <>
                        <div className="space-y-1">
                            <Input
                                label="Lisensi berlaku sampai"
                                type="date"
                                value={data.valid_until}
                                onChange={(e) =>
                                    setData('valid_until', e.target.value)
                                }
                            />
                            <p className="text-xs text-muted-foreground">
                                Kosongkan untuk {licenseValidDays} hari sejak
                                hari ini. Daftar app diambil dari app yang aktif
                                untuk tenant ini.
                            </p>
                        </div>
                        {!licenseKeyConfigured && (
                            <p className="text-sm text-destructive">
                                Kunci lisensi belum disetel di konsol ini, jadi
                                lisensi tidak dapat diterbitkan.
                            </p>
                        )}
                    </>
                )}

                <ConfirmName
                    site={site}
                    value={data.confirm_name}
                    onChange={(v) => setData('confirm_name', v)}
                    error={errors.confirm_name}
                />
                <FieldError message={errors.operation} />

                <Button type="submit" disabled={processing}>
                    Minta{' '}
                    {labelFor(
                        siteOperationLabels,
                        data.operation,
                    ).toLowerCase()}
                </Button>
            </form>
        </Section>
    );
}

function Revoke({ site }: { site: Site }) {
    const { data, setData, post, processing, errors } = useForm({
        confirm_name: '',
    });

    return (
        <Section
            title="Cabut situs"
            description="Tanda tangan agen situs ini berhenti diterima dan permintaan yang menunggu dibatalkan. Aplikasinya di server klien tetap berjalan — yang berhenti pengelolaannya, bukan pelayanan pasien. Tidak dapat dibatalkan dari layar ini."
        >
            <form
                className="space-y-3"
                onSubmit={(e: FormEvent) => {
                    e.preventDefault();
                    post(`/situs/${site.id}/cabut`, { preserveScroll: true });
                }}
            >
                <ConfirmName
                    site={site}
                    value={data.confirm_name}
                    onChange={(v) => setData('confirm_name', v)}
                    error={errors.confirm_name}
                />
                <Button
                    type="submit"
                    variant="destructive"
                    disabled={processing}
                >
                    Cabut situs
                </Button>
            </form>
        </Section>
    );
}

/**
 * Lisensi yang diterbitkan konsol ini, dan tombol yang menghentikan atau melanjutkan perpanjangannya.
 *
 * Yang ditampilkan di sini lisensi yang *dikirim*. Yang *terpasang* di server klien ada di "Laporan
 * terakhir"; selisih keduanya berarti agen gagal memasangnya.
 */
function LicenseRenewal({ site, revoked }: { site: Site; revoked: boolean }) {
    const { license } = site;
    const suspended = license.suspendedAt !== null;
    const { data, setData, post, processing, errors, reset } = useForm({
        confirm_name: '',
    });

    return (
        <Section
            title="Lisensi"
            description="Diperpanjang otomatis lewat laporan agen. Menghentikan perpanjangan tidak menyentuh server klien: lisensi yang terpasang tetap berlaku sampai tanggal berakhirnya, lalu aplikasinya terkunci."
        >
            <dl>
                <Row label="Berlaku sampai">{license.validUntil ?? '—'}</Row>
                <Row label="Terakhir diterbitkan">
                    {license.issuedAt ?? 'Belum pernah'}
                </Row>
                <Row label="Perpanjangan otomatis">
                    {suspended
                        ? `Perpanjangan dihentikan sejak ${license.suspendedAt}`
                        : 'Berjalan'}
                </Row>
            </dl>

            {!revoked && (
                <form
                    className="space-y-3"
                    onSubmit={(e: FormEvent) => {
                        e.preventDefault();
                        post(
                            `/situs/${site.id}/lisensi/${suspended ? 'lanjutkan' : 'hentikan'}`,
                            {
                                preserveScroll: true,
                                onSuccess: () => reset('confirm_name'),
                            },
                        );
                    }}
                >
                    <ConfirmName
                        site={site}
                        value={data.confirm_name}
                        onChange={(v) => setData('confirm_name', v)}
                        error={errors.confirm_name}
                    />
                    <Button
                        type="submit"
                        variant={suspended ? 'default' : 'destructive'}
                        disabled={processing}
                    >
                        {suspended
                            ? 'Lanjutkan perpanjangan lisensi'
                            : 'Hentikan perpanjangan lisensi'}
                    </Button>
                </form>
            )}
        </Section>
    );
}

/**
 * Rincian satu situs: keadaan terakhir, tindakan, riwayat operasi, dan jejak audit.
 *
 * Tindakan yang tersedia mengikuti keadaan situsnya, bukan disembunyikan setelah ditekan. Situs yang
 * belum terdaftar hanya menawarkan pendaftaran.
 */
export default function Show({
    site,
    history,
    operations,
    releases,
    audit,
    licenseKeyConfigured,
    licenseValidDays,
}: {
    site: Site;
    history: Operation[];
    operations: string[];
    releases: string[];
    audit: AuditEvent[];
    licenseKeyConfigured: boolean;
    licenseValidDays: number;
}) {
    const report = site.lastReport;
    const revoked = site.state === 'revoked';
    const operationError = usePage().props.errors.operation;

    return (
        <Shell title={site.name} description={`Milik ${site.tenant}`}>
            <Head title={site.name} />

            {operationError && (
                <div
                    role="alert"
                    className="rounded-md border border-destructive/40 bg-red-50 px-4 py-3 text-sm text-destructive dark:bg-red-950/40 dark:text-red-200"
                >
                    {operationError}
                </div>
            )}

            {site.license.notRequiredOnServer && (
                <div
                    role="alert"
                    className="rounded-md border border-amber-300 bg-amber-50 px-4 py-3 text-sm text-amber-900 dark:border-amber-800 dark:bg-amber-950/40 dark:text-amber-200"
                >
                    Server ini tidak mewajibkan lisensi — periksa berkas .env di
                    server klien.
                </div>
            )}

            <div className="grid gap-6 lg:grid-cols-2">
                <Section title="Keterangan">
                    <dl>
                        <Row label="Keadaan">
                            <SiteStateBadge state={site.state} />
                        </Row>
                        <Row label="Lingkungan">
                            {site.environment ? (
                                <Link
                                    href={`/lingkungan/${site.environment.id}`}
                                    className="underline underline-offset-4"
                                >
                                    {site.environment.name}
                                </Link>
                            ) : (
                                'Didaftarkan tanpa lingkungan'
                            )}
                        </Row>
                        <Row label="Edisi">{site.edition}</Row>
                        <Row label="Rilis terpasang">
                            {site.reportedRelease ?? '—'}
                        </Row>
                        <Row label="Terakhir terlihat">
                            {site.lastSeenAt ?? 'Belum pernah'}
                        </Row>
                        <Row label="Jendela pembaruan">
                            {site.updateWindow
                                ? `${site.updateWindow.start}–${site.updateWindow.end} (${site.updateWindow.timezone})`
                                : 'Kapan saja'}
                        </Row>
                        <Row label="Terdaftar">{site.enrolledAt ?? '—'}</Row>
                        {site.address && (
                            <Row label="Alamat">
                                <a
                                    href={site.address}
                                    target="_blank"
                                    rel="noreferrer"
                                    className="underline underline-offset-4"
                                >
                                    {site.address}
                                </a>
                            </Row>
                        )}
                    </dl>
                </Section>

                <Section
                    title="Laporan terakhir"
                    description="Hanya isi yang boleh keluar dari server klien: keadaan container, sisa disk, cadangan, dan masa berlaku."
                >
                    {report ? (
                        <dl>
                            <Row label="Container">
                                {(report.containers ?? [])
                                    .map(
                                        (c) =>
                                            `${c.service}: ${c.state}${c.health ? ` (${c.health})` : ''}`,
                                    )
                                    .join(', ') || '—'}
                            </Row>
                            <Row label="Sisa disk data">
                                {bytes(report.disk?.data_free_bytes)}
                            </Row>
                            <Row label="Sisa disk cadangan">
                                {bytes(report.disk?.backup_free_bytes)}
                            </Row>
                            <Row label="Cadangan terakhir">
                                {report.last_backup
                                    ? `${report.last_backup.at} — ${labelFor(siteOperationStatusLabels, report.last_backup.result)}, ${bytes(report.last_backup.size_bytes)}`
                                    : 'Belum ada'}
                            </Row>
                            <Row label="Lisensi berakhir">
                                {report.license_expires_at ?? '—'}
                            </Row>
                            <Row label="Sertifikat berakhir">
                                {report.certificate_expires_at ?? '—'}
                            </Row>
                            <Row label="Versi agen">
                                {report.agent_version ?? '—'}
                            </Row>
                        </dl>
                    ) : (
                        <p className="text-sm text-muted-foreground">
                            Situs ini belum pernah melapor.
                        </p>
                    )}
                </Section>
            </div>

            <div className="grid gap-6 lg:grid-cols-2">
                {!revoked && site.state === 'not_enrolled' && (
                    <Enrollment site={site} />
                )}
                {!revoked && site.state !== 'not_enrolled' && (
                    <RequestOperation
                        site={site}
                        operations={operations}
                        releases={releases}
                        licenseKeyConfigured={licenseKeyConfigured}
                        licenseValidDays={licenseValidDays}
                    />
                )}
                <LicenseRenewal site={site} revoked={revoked} />
            </div>

            <Section title="Riwayat operasi">
                <div className="overflow-x-auto">
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>Operasi</TableHead>
                                <TableHead>Keadaan</TableHead>
                                <TableHead>Langkah</TableHead>
                                <TableHead>Diminta</TableHead>
                                <TableHead>Oleh</TableHead>
                                <TableHead />
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {history.length === 0 && (
                                <TableRow>
                                    <TableCell
                                        colSpan={6}
                                        className="py-6 text-center text-sm text-muted-foreground"
                                    >
                                        Belum ada operasi.
                                    </TableCell>
                                </TableRow>
                            )}
                            {history.map((op) => (
                                <TableRow key={op.id}>
                                    <TableCell className="font-medium">
                                        {labelFor(
                                            siteOperationLabels,
                                            op.operation,
                                        )}
                                        {op.release && (
                                            <span className="ms-2 font-mono text-xs text-muted-foreground">
                                                {op.release}
                                            </span>
                                        )}
                                    </TableCell>
                                    <TableCell>
                                        {labelFor(
                                            siteOperationStatusLabels,
                                            op.status,
                                        )}
                                        {op.reason && (
                                            <p className="mt-1 max-w-md text-xs whitespace-pre-line text-destructive">
                                                {op.reason}
                                            </p>
                                        )}
                                    </TableCell>
                                    <TableCell className="text-sm text-muted-foreground">
                                        {op.step ?? '—'}
                                    </TableCell>
                                    <TableCell className="text-sm text-muted-foreground">
                                        {op.requestedAt}
                                    </TableCell>
                                    <TableCell className="text-sm">
                                        {op.requestedBy}
                                    </TableCell>
                                    <TableCell className="text-end">
                                        {op.status === 'requested' && (
                                            <Button
                                                size="sm"
                                                variant="ghost"
                                                onClick={() =>
                                                    router.post(
                                                        `/situs/${site.id}/operasi/${op.id}/batal`,
                                                        {},
                                                        {
                                                            preserveScroll: true,
                                                        },
                                                    )
                                                }
                                            >
                                                Batalkan
                                            </Button>
                                        )}
                                    </TableCell>
                                </TableRow>
                            ))}
                        </TableBody>
                    </Table>
                </div>
            </Section>

            <Section
                title="Jejak audit"
                description="Tindakan operator terhadap situs ini. Jejaknya hanya dapat ditambah; database menolak perubahan dan penghapusan."
            >
                {audit.length === 0 ? (
                    <p className="text-sm text-muted-foreground">
                        Belum ada tindakan tercatat.
                    </p>
                ) : (
                    <ul className="space-y-1 text-sm">
                        {audit.map((event) => (
                            <li key={event.id} className="flex flex-wrap gap-2">
                                <span className="text-muted-foreground">
                                    {event.at}
                                </span>
                                <span className="font-mono text-xs">
                                    {event.action}
                                </span>
                                <span>oleh {event.by}</span>
                            </li>
                        ))}
                    </ul>
                )}
            </Section>

            {!revoked && <Revoke site={site} />}
        </Shell>
    );
}
