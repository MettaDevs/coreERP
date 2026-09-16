import { Badge } from '@apperp/ui/badge';
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
import { ArrowUpCircle, ExternalLink } from 'lucide-react';
import type { FormEvent, ReactNode } from 'react';
import { InstallStateBadge } from '@/components/badges';
import CopyButton from '@/components/copy-button';
import DnsStatus from '@/components/dns-status';
import type { DnsInfo } from '@/components/dns-status';
import {
    ServerAddressField,
    ServerAdvancedFields,
} from '@/components/server-settings-fields';
import Shell from '@/components/shell';
import {
    labelFor,
    siteAuditLabels,
    siteOperationLabels,
    siteOperationStatusLabels,
} from '@/lib/display';
import { progressDetail } from '@/lib/install-progress';
import type { InstallProgress } from '@/lib/install-progress';
import { newerRelease } from '@/lib/release';
import { daysUntil, relativeTime } from '@/lib/time';

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
    perpetual: boolean;
    issuedPerpetual: boolean;
    terms: {
        perpetual: boolean;
        validDays: number;
        renewBeforeDays: number;
        overridden: boolean;
    };
    defaultTerms: { validDays: number; renewBeforeDays: number };
    notRequiredOnServer: boolean;
};

type Site = {
    id: string;
    name: string;
    tenant: string;
    environment: { id: string; name: string } | null;
    edition: string;
    state: string;
    serverAddress: string | null;
    appUrl: string | null;
    appUrlAutomatic: boolean;
    dns: DnsInfo;
    lastSeenIp: string | null;
    reportedRelease: string | null;
    newestRelease: string | null;
    reportedDigest: string | null;
    lastSeenAt: string | null;
    lastSeenIso: string | null;
    updateWindow: { start: string; end: string; timezone: string } | null;
    enrolledAt: string | null;
    lastReport: Report | null;
    license: License;
    progress: InstallProgress;
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

/** Edisi satu image yang dipakai setiap server klien sejak 15 September 2026 — lihat `Site::SINGLE_IMAGE_EDITION`. */
const SINGLE_IMAGE_EDITION = 'coreerp';

function Row({ label, children }: { label: string; children: ReactNode }) {
    return (
        <div className="flex flex-wrap justify-between gap-4 border-b py-2.5 last:border-b-0">
            <dt className="text-sm text-muted-foreground">{label}</dt>
            <dd className="text-end text-sm font-medium break-all">
                {children}
            </dd>
        </div>
    );
}

function Section({
    id,
    title,
    description,
    children,
}: {
    id?: string;
    title: string;
    description?: string;
    children: ReactNode;
}) {
    return (
        <section
            id={id}
            className="scroll-mt-24 space-y-4 rounded-lg border bg-background p-5"
        >
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

function Summary({
    label,
    children,
    hint,
}: {
    label: string;
    children: ReactNode;
    hint?: ReactNode;
}) {
    return (
        <div className="min-w-0 rounded-lg border bg-background p-4">
            <p className="text-xs text-muted-foreground">{label}</p>
            <div className="mt-1.5 min-w-0 text-sm font-medium">{children}</div>
            {hint && (
                <div className="mt-1 text-xs text-muted-foreground">{hint}</div>
            )}
        </div>
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

/**
 * Setelan server: alamat mesin dan jendela pembaruan, bersama alamat aplikasi otomatis dan record DNS-nya.
 *
 * Isinya sama dengan "Setelan server" di panel halaman lingkungan, dan disimpan lewat aturan yang sama.
 * Situs yang dicabut hanya ditampilkan, karena setelannya tidak lagi berarti apa pun.
 */
function ServerSettingsSection({
    site,
    revoked,
}: {
    site: Site;
    revoked: boolean;
}) {
    const { data, setData, patch, processing, errors } = useForm({
        server_address: site.serverAddress ?? '',
        update_window_start: site.updateWindow?.start ?? '',
        update_window_end: site.updateWindow?.end ?? '',
    });
    const refusal = (errors as Record<string, string | undefined>).settings;

    if (revoked) {
        return (
            <Section id="setelan" title="Setelan server">
                <dl>
                    <Row label="Alamat server">{site.serverAddress ?? '—'}</Row>
                    <Row label="Alamat aplikasi">{site.appUrl ?? '—'}</Row>
                    <Row label="Jendela pembaruan">
                        {site.updateWindow
                            ? `${site.updateWindow.start}–${site.updateWindow.end} (${site.updateWindow.timezone})`
                            : 'Kapan saja'}
                    </Row>
                </dl>
            </Section>
        );
    }

    return (
        <Section
            id="setelan"
            title="Setelan server"
            description="Alamat aplikasi dibentuk sistem dan record DNS-nya mengikuti alamat server. Tidak satu pun isian ini mengubah server klien; jendela pembaruan berlaku pada pembaruan berikutnya."
        >
            <div className="space-y-1.5 rounded-md border bg-muted/30 p-3">
                <p className="text-xs text-muted-foreground">
                    Alamat aplikasi{site.appUrlAutomatic ? ' (otomatis)' : ''}
                </p>
                {site.appUrlAutomatic ? (
                    <DnsStatus
                        siteId={site.id}
                        appUrl={site.appUrl}
                        dns={site.dns}
                        serverAddress={site.serverAddress}
                    />
                ) : (
                    <p className="font-mono text-sm break-all">
                        {site.appUrl ?? 'Tidak dicatat'}
                    </p>
                )}
            </div>
            <form
                className="space-y-4"
                onSubmit={(e: FormEvent) => {
                    e.preventDefault();
                    patch(`/situs/${site.id}/setelan`, {
                        preserveScroll: true,
                    });
                }}
            >
                {refusal && (
                    <p role="alert" className="text-sm text-destructive">
                        {refusal}
                    </p>
                )}
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
                <Button type="submit" variant="outline" disabled={processing}>
                    Simpan setelan
                </Button>
            </form>
        </Section>
    );
}

/**
 * Pemasangan server klien yang lahir dari halaman lingkungan: keadaannya di sini, tombolnya di sana.
 *
 * Perintah pasang membuat token pendaftaran dan operasi pasang sekaligus, dan hanya panel di halaman
 * lingkungan yang melakukannya. Menaruh tombol kedua di sini berarti dua tempat yang menampilkan kata sandi
 * sementara yang sama hanya sekali.
 */
function Installation({ site }: { site: Site }) {
    const detail = progressDetail(site.progress);

    return (
        <Section
            title="Pemasangan"
            description="Perintah pasang dibuat dan dipantau dari panel Server klien di halaman lingkungannya."
        >
            <div className="flex flex-wrap items-center gap-2">
                <InstallStateBadge state={site.progress.state} />
                {detail && (
                    <span className="text-sm text-muted-foreground">
                        {detail}
                    </span>
                )}
            </div>
            {site.progress.failureMessage && (
                <p className="text-sm whitespace-pre-line text-destructive">
                    {site.progress.failureMessage}
                </p>
            )}
            {site.environment && (
                <Button asChild>
                    <Link href={`/lingkungan/${site.environment.id}`}>
                        Buka panel pemasangan
                    </Link>
                </Button>
            )}
        </Section>
    );
}

/** Pendaftaran tangan untuk situs lama tanpa lingkungan — satu-satunya yang masih memakainya. */
function Enrollment({ site }: { site: Site }) {
    const { enrollment } = usePage<{
        enrollment: { command: string; expiresAt: string } | null;
    }>().props;
    const form = useForm({});

    return (
        <Section
            title="Pendaftaran"
            description="Situs lama tanpa lingkungan. Perintah pasang dijalankan sekali di server klien sebagai root; tokennya sekali pakai, kedaluwarsa dalam satu jam, dan perintahnya hanya tampil sekali."
        >
            {enrollment && (
                <div className="space-y-2">
                    <div className="flex justify-end">
                        <CopyButton
                            text={enrollment.command}
                            label="Salin perintah"
                        />
                    </div>
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
    const { data, setData, post, processing, errors } = useForm({
        operation: 'backup',
        release: releases[0] ?? '',
        valid_until: '',
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
                            yang terpasang di server ini.
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
    const { post, processing } = useForm({});

    return (
        <section className="space-y-4 rounded-lg border border-red-200 bg-background p-5 dark:border-red-900/60">
            <div>
                <h2 className="text-sm font-semibold text-red-700 dark:text-red-300">
                    Cabut server klien
                </h2>
                <p className="mt-1 text-sm text-muted-foreground">
                    Tanda tangan agen server ini berhenti diterima dan
                    permintaan yang menunggu dibatalkan. Aplikasinya di server
                    klien tetap berjalan — yang berhenti pengelolaannya, bukan
                    pelayanan pasien. Tidak dapat dibatalkan dari layar ini.
                </p>
            </div>
            <form
                className="space-y-3"
                onSubmit={(e: FormEvent) => {
                    e.preventDefault();
                    post(`/situs/${site.id}/cabut`, { preserveScroll: true });
                }}
            >
                <Button
                    type="submit"
                    variant="destructive"
                    disabled={processing}
                >
                    Cabut server klien
                </Button>
            </form>
        </section>
    );
}

/**
 * Lisensi yang diterbitkan konsol ini, dan tombol yang menghentikan atau melanjutkan perpanjangannya.
 *
 * Yang ditampilkan di sini lisensi yang *dikirim*. Yang *terpasang* di server klien ada di "Laporan
 * terakhir"; selisih keduanya berarti agen gagal memasangnya.
 */
/** Satu kalimat yang menyebut masa yang berlaku, dan dari mana angkanya datang. */
function termsSentence(license: License): string {
    const { terms } = license;

    if (terms.perpetual) {
        return license.issuedPerpetual
            ? 'Permanen'
            : 'Permanen mulai penerbitan berikutnya; yang terpasang sekarang masih bertanggal';
    }

    const asal = terms.overridden
        ? 'khusus situs ini'
        : 'mengikuti bawaan konsol';
    const dasar = `${terms.validDays} hari, diperpanjang ${terms.renewBeforeDays} hari sebelum habis (${asal})`;

    return license.issuedPerpetual
        ? `${dasar}. Yang terpasang sekarang masih lisensi permanen, dan diganti pada penerbitan berikutnya`
        : dasar;
}

/**
 * Mengubah masa lisensi situs ini: mengikuti bawaan, angka sendiri, atau permanen.
 *
 * Permanen berarti lisensinya tidak pernah habis — bukan bahwa seluruh modul terbuka. Daftar app tetap
 * datang dari app yang dibeli tenant, jadi kalimat di layar tidak boleh menjanjikan yang sebaliknya.
 */
function LicenseTermsForm({ site }: { site: Site }) {
    const { terms, defaultTerms } = site.license;
    const { data, setData, post, processing, errors } = useForm({
        mode: terms.perpetual
            ? 'perpetual'
            : terms.overridden
              ? 'custom'
              : 'default',
        valid_days: String(terms.validDays),
        renew_before_days: String(terms.renewBeforeDays),
    });

    return (
        <form
            className="space-y-3 border-t pt-4"
            onSubmit={(e: FormEvent) => {
                e.preventDefault();
                post(`/situs/${site.id}/lisensi/masa`, {
                    preserveScroll: true,
                });
            }}
        >
            <NativeSelect
                label="Masa lisensi"
                value={data.mode}
                onChange={(e) => setData('mode', e.target.value)}
            >
                <option value="default">
                    Bawaan konsol — {defaultTerms.validDays} hari, diperpanjang{' '}
                    {defaultTerms.renewBeforeDays} hari sebelum habis
                </option>
                <option value="custom">Angka sendiri untuk situs ini</option>
                <option value="perpetual">
                    Permanen — tanpa tanggal berakhir
                </option>
            </NativeSelect>

            {data.mode === 'custom' && (
                <div className="grid gap-3 sm:grid-cols-2">
                    <div className="space-y-2">
                        <Input
                            label="Masa berlaku (hari)"
                            type="number"
                            min={1}
                            max={3650}
                            required
                            value={data.valid_days}
                            onChange={(e) =>
                                setData('valid_days', e.target.value)
                            }
                        />
                        <FieldError message={errors.valid_days} />
                    </div>
                    <div className="space-y-2">
                        <Input
                            label="Diperpanjang berapa hari sebelum habis"
                            type="number"
                            min={1}
                            max={365}
                            required
                            value={data.renew_before_days}
                            onChange={(e) =>
                                setData('renew_before_days', e.target.value)
                            }
                        />
                        <FieldError message={errors.renew_before_days} />
                    </div>
                </div>
            )}

            {data.mode === 'perpetual' && (
                <p className="text-sm text-muted-foreground">
                    Lisensi permanen tidak pernah habis, jadi aplikasi di server
                    klien ini tidak akan pernah terkunci karena masa lisensi —
                    termasuk ketika konsol ini mati berbulan-bulan. App yang
                    boleh dibuka tetap mengikuti yang dibeli tenant.
                </p>
            )}

            <Button type="submit" variant="outline" disabled={processing}>
                Simpan masa lisensi
            </Button>
        </form>
    );
}

function LicenseRenewal({ site, revoked }: { site: Site; revoked: boolean }) {
    const { license } = site;
    const suspended = license.suspendedAt !== null;
    const { post, processing } = useForm({});

    return (
        <Section
            title="Lisensi"
            description="Diperpanjang otomatis lewat laporan agen. Menghentikan perpanjangan tidak menyentuh server klien: lisensi yang terpasang tetap berlaku sampai tanggal berakhirnya, lalu aplikasinya terkunci."
        >
            <dl>
                <Row label="Berlaku sampai">
                    {license.issuedPerpetual
                        ? 'Permanen, tanpa tanggal berakhir'
                        : (license.validUntil ?? '—')}
                </Row>
                <Row label="Terakhir diterbitkan">
                    {license.issuedAt ?? 'Belum pernah'}
                </Row>
                <Row label="Masa lisensi">{termsSentence(license)}</Row>
                <Row label="Perpanjangan otomatis">
                    {license.issuedPerpetual && license.perpetual
                        ? 'Tidak diperpanjang; lisensi permanen tidak pernah habis'
                        : suspended
                          ? `Perpanjangan dihentikan sejak ${license.suspendedAt}`
                          : 'Berjalan'}
                </Row>
            </dl>

            {!revoked && <LicenseTermsForm site={site} />}

            {!revoked && (
                <form
                    className="space-y-3"
                    onSubmit={(e: FormEvent) => {
                        e.preventDefault();
                        post(
                            `/situs/${site.id}/lisensi/${suspended ? 'lanjutkan' : 'hentikan'}`,
                            {
                                preserveScroll: true,
                            },
                        );
                    }}
                >
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

function ContainerBadge({
    container,
}: {
    container: { service: string; state: string; health?: string | null };
}) {
    const good =
        container.state === 'running' &&
        (!container.health || container.health === 'healthy');
    const classes = good
        ? 'border-emerald-200 bg-emerald-50 text-emerald-700 dark:border-emerald-900/60 dark:bg-emerald-950/40 dark:text-emerald-200'
        : 'border-red-200 bg-red-50 text-red-700 dark:border-red-900/60 dark:bg-red-950/40 dark:text-red-200';

    return (
        <Badge variant="outline" className={`font-mono ${classes}`}>
            {container.service}
            <span className="font-sans opacity-80">
                {container.health ?? container.state}
            </span>
        </Badge>
    );
}

/**
 * Rincian satu server klien: ringkasan di atas, setelan dan laporan, tindakan, lalu riwayat.
 *
 * Tindakan yang tersedia mengikuti keadaannya, bukan disembunyikan setelah ditekan. Server yang belum
 * terdaftar menaut ke panel pemasangannya — atau, untuk situs lama tanpa lingkungan, menawarkan pendaftaran.
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
    const newer = newerRelease(site.reportedRelease, site.newestRelease);
    const seen = relativeTime(site.lastSeenIso);
    const licenseDays = daysUntil(site.license.validUntil);

    return (
        <Shell
            title={site.name}
            description={`Milik ${site.tenant}`}
            actions={
                site.environment && (
                    <Button asChild variant="outline">
                        <Link href={`/lingkungan/${site.environment.id}`}>
                            Lingkungan {site.environment.name}
                        </Link>
                    </Button>
                )
            }
        >
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

            <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                <Summary
                    label="Keadaan"
                    hint={
                        seen
                            ? `Terakhir terlihat ${seen}`
                            : 'Belum pernah melapor'
                    }
                >
                    <InstallStateBadge state={site.progress.state} />
                </Summary>
                <Summary
                    label="Alamat server"
                    hint={
                        site.lastSeenIp
                            ? `Agen melapor dari ${site.lastSeenIp}`
                            : undefined
                    }
                >
                    {site.serverAddress ? (
                        <span className="flex items-center gap-1">
                            <span className="truncate font-mono">
                                {site.serverAddress}
                            </span>
                            <CopyButton
                                text={site.serverAddress}
                                label="Salin alamat server"
                                iconOnly
                            />
                        </span>
                    ) : (
                        <a
                            href="#setelan"
                            className="font-normal text-amber-700 hover:underline dark:text-amber-300"
                        >
                            Belum dicatat
                        </a>
                    )}
                </Summary>
                <Summary
                    label="Rilis terpasang"
                    hint={
                        newer ? (
                            <span className="flex items-center gap-1 text-amber-700 dark:text-amber-300">
                                <ArrowUpCircle className="size-3.5" />
                                {newer} tersedia
                            </span>
                        ) : site.newestRelease ? (
                            `Terbaru ${site.newestRelease}`
                        ) : undefined
                    }
                >
                    <span className="font-mono">
                        {site.reportedRelease ?? '—'}
                    </span>
                </Summary>
                <Summary
                    label="Lisensi berlaku sampai"
                    hint={
                        site.license.issuedPerpetual
                            ? 'Tidak pernah habis'
                            : licenseDays === null
                              ? 'Belum diterbitkan'
                              : licenseDays < 0
                                ? `Habis ${Math.abs(licenseDays)} hari lalu`
                                : `${licenseDays} hari lagi`
                    }
                >
                    {site.license.issuedPerpetual
                        ? 'Permanen'
                        : (site.license.validUntil ?? '—')}
                </Summary>
            </div>

            <div className="grid gap-6 lg:grid-cols-2">
                <ServerSettingsSection site={site} revoked={revoked} />

                <Section
                    title="Laporan terakhir"
                    description="Hanya isi yang boleh keluar dari server klien: keadaan container, sisa disk, cadangan, dan masa berlaku."
                >
                    {report ? (
                        <dl>
                            {(report.containers ?? []).length > 0 && (
                                <div className="space-y-2 border-b py-2.5">
                                    <dt className="text-sm text-muted-foreground">
                                        Container
                                    </dt>
                                    <dd className="flex flex-wrap gap-1.5">
                                        {(report.containers ?? []).map((c) => (
                                            <ContainerBadge
                                                key={c.service}
                                                container={c}
                                            />
                                        ))}
                                    </dd>
                                </div>
                            )}
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
                            <Row label="Lisensi terpasang berakhir">
                                {report.license_expires_at ?? '—'}
                            </Row>
                            <Row label="Sertifikat berakhir">
                                {report.certificate_expires_at ?? '—'}
                            </Row>
                            <Row label="Versi agen">
                                {report.agent_version ?? '—'}
                            </Row>
                            <Row label="Terdaftar">
                                {site.enrolledAt ?? '—'}
                            </Row>
                            {site.edition !== SINGLE_IMAGE_EDITION && (
                                <Row label="Edisi">{site.edition}</Row>
                            )}
                            {site.reportedDigest && (
                                <Row label="Digest">
                                    <span className="font-mono text-xs font-normal">
                                        {site.reportedDigest}
                                    </span>
                                </Row>
                            )}
                        </dl>
                    ) : (
                        <p className="text-sm text-muted-foreground">
                            Server ini belum pernah melapor. Laporan pertama
                            datang sekitar satu menit setelah agennya terpasang.
                        </p>
                    )}
                    {site.appUrl && (
                        <a
                            href={site.appUrl}
                            target="_blank"
                            rel="noreferrer"
                            className="inline-flex items-center gap-1 text-sm underline underline-offset-4"
                        >
                            Buka aplikasi
                            <ExternalLink className="size-3.5" />
                        </a>
                    )}
                </Section>
            </div>

            <div className="grid gap-6 lg:grid-cols-2">
                {!revoked &&
                    site.state === 'not_enrolled' &&
                    (site.environment ? (
                        <Installation site={site} />
                    ) : (
                        <Enrollment site={site} />
                    ))}
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
                                                            preserveScroll:
                                                                true,
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
                description="Tindakan operator dan sistem terhadap server ini. Jejaknya hanya dapat ditambah; database menolak perubahan dan penghapusan."
            >
                {audit.length === 0 ? (
                    <p className="text-sm text-muted-foreground">
                        Belum ada tindakan tercatat.
                    </p>
                ) : (
                    <ul className="divide-y text-sm">
                        {audit.map((event) => (
                            <li
                                key={event.id}
                                className="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1 py-2"
                            >
                                <span>
                                    <span className="font-medium">
                                        {labelFor(
                                            siteAuditLabels,
                                            event.action,
                                        )}
                                    </span>
                                    <span className="ms-2 text-muted-foreground">
                                        oleh {event.by}
                                    </span>
                                </span>
                                <span className="flex items-baseline gap-3 text-xs text-muted-foreground">
                                    <span className="font-mono">
                                        {event.action}
                                    </span>
                                    <span>{event.at}</span>
                                </span>
                            </li>
                        ))}
                    </ul>
                )}
            </Section>

            {!revoked && <Revoke site={site} />}
        </Shell>
    );
}
