import { FormErrorHeader } from '@/components/form-error-header';
import { useInertiaFeedback } from '@/hooks/use-inertia-feedback';
import { ActionButton } from '@apperp/ui/action-button';
import { Card, CardHeader, CardTitle, CardContent } from '@apperp/ui/card';
import { FormControl, FormGrid } from '@apperp/ui/form-control';
import { Input } from '@apperp/ui/input';
import { Head, useForm } from '@inertiajs/react';
import { CircleCheck, CircleAlert } from 'lucide-react';
import type { FormEvent, ReactNode } from 'react';
import Shell from '@/components/shell';

type KeyState =
    { ok: true; fingerprint: string } | { ok: false; error: string };

type Props = {
    releaseKey: KeyState;
    licenseKey: {
        private: { ok: true } | { ok: false; error: string };
        public: KeyState;
        pairMatches: boolean | null;
    };
    licenseTerms: { validDays: number; renewBeforeDays: number };
    registry: {
        host: string;
        robot: string | null;
        check: { ok: true } | { ok: false; error: string };
    };
    dns: {
        baseDomain: string;
        configured: boolean;
        check: { ok: true; zone: string } | { ok: false; error: string } | null;
    };
};

function Section({
    title,
    description,
    children,
    ready,
    id,
}: {
    ready?: boolean;
    id?: string;
    title: string;
    description: string;
    children: ReactNode;
}) {
    return (
        <Card id={id} className="scroll-mt-20">
            <CardHeader>
                <CardTitle>{title}</CardTitle>
                {ready !== undefined && (
                    <span
                        className={`ms-auto flex items-center gap-1.5 text-xs ${ready ? 'text-success' : 'text-destructive'}`}
                    >
                        {ready ? (
                            <CircleCheck className="size-3.5" />
                        ) : (
                            <CircleAlert className="size-3.5" />
                        )}
                        {ready ? 'Siap' : 'Perlu diperbaiki'}
                    </span>
                )}
            </CardHeader>
            <CardContent className="space-y-4">
                <p className="max-w-2xl text-sm text-muted-foreground">
                    {description}
                </p>
                {children}
            </CardContent>
        </Card>
    );
}

function Row({ label, children }: { label: string; children: ReactNode }) {
    return (
        <div className="grid gap-1 border-b py-3 last:border-b-0 sm:grid-cols-[11rem_minmax(0,1fr)] sm:gap-4">
            <dt className="text-sm text-muted-foreground">{label}</dt>
            <dd className="min-w-0 text-sm break-words">{children}</dd>
        </div>
    );
}

/** Kesalahan kunci tampil merah dengan sebabnya, bukan sebagai baris kosong. */
function Problem({ children }: { children: ReactNode }) {
    return (
        <span role="alert" className="text-destructive">
            {children}
        </span>
    );
}

function Fingerprint({ state }: { state: KeyState }) {
    return state.ok ? (
        <span className="font-mono text-xs">{state.fingerprint}</span>
    ) : (
        <Problem>{state.error}</Problem>
    );
}

/**
 * Pengaturan konsol: kunci dan registry yang dibutuhkan server klien.
 *
 * Isinya dibaca, bukan diubah. Kunci disetel lewat berkas di server konsol dan robot registry lewat
 * perintah artisan; halaman ini ada supaya yang hilang atau tertukar ditemukan operator sebelum perintah
 * pasang dibuat — bukan oleh teknisi yang terminalnya menjawab 503 di lokasi klien.
 */
function LicenseTermsForm({
    terms,
}: {
    terms: { validDays: number; renewBeforeDays: number };
}) {
    const { wrap: wrapLocalForm0Request, summary: localForm0RequestSummary } =
        useInertiaFeedback();

    const { data, setData, patch, processing, errors } = useForm({
        valid_days: String(terms.validDays),
        renew_before_days: String(terms.renewBeforeDays),
    });

    const valid = Number(data.valid_days);
    const renew = Number(data.renew_before_days);
    const validSchedule =
        Number.isFinite(valid) &&
        Number.isFinite(renew) &&
        valid > renew &&
        renew > 0;

    return (
        <form
            noValidate
            className="space-y-4"
            onSubmit={(e: FormEvent) => {
                e.preventDefault();
                patch(
                    '/pengaturan/lisensi',
                    wrapLocalForm0Request({ preserveScroll: true }),
                );
            }}
        >
            <>
                {localForm0RequestSummary}
                <FormErrorHeader errors={errors} />
            </>

            <ActionButton action="save" type="submit" pending={processing} />
            <FormGrid>
                <FormControl error={errors.valid_days}>
                    <Input
                        label="Masa berlaku (hari)"
                        type="number"
                        min={1}
                        max={3650}
                        required
                        value={data.valid_days}
                        onChange={(e) => setData('valid_days', e.target.value)}
                    />
                </FormControl>
                <FormControl error={errors.renew_before_days}>
                    <Input
                        label="Mulai perpanjangan (hari sebelum habis)"
                        type="number"
                        min={1}
                        max={365}
                        required
                        value={data.renew_before_days}
                        onChange={(e) =>
                            setData('renew_before_days', e.target.value)
                        }
                    />
                </FormControl>
            </FormGrid>

            <p className="text-xs text-muted-foreground">
                {!validSchedule
                    ? 'Perpanjangan harus mulai sebelum lisensinya habis.'
                    : `Lisensi berlaku ${valid} hari dan mulai diperpanjang ${renew} hari sebelum habis.`}
            </p>
        </form>
    );
}

export default function Settings({
    releaseKey,
    licenseKey,
    licenseTerms,
    registry,
    dns,
}: Props) {
    const checks = [
        { id: 'release-key', label: 'Kunci rilis', ready: releaseKey.ok },
        {
            id: 'license-key',
            label: 'Kunci lisensi',
            ready:
                licenseKey.private.ok &&
                licenseKey.public.ok &&
                licenseKey.pairMatches === true,
        },
        {
            id: 'registry',
            label: 'Registry',
            ready: registry.robot !== null && registry.check.ok,
        },
        {
            id: 'dns',
            label: 'DNS',
            ready: dns.configured && dns.check?.ok === true,
        },
    ];
    const issueCount = checks.filter((check) => !check.ready).length;

    return (
        <Shell
            title="Pengaturan"
            description="Periksa kesiapan pemasangan server klien dan atur masa berlaku lisensi."
        >
            <Head title="Pengaturan" />

            <Card>
                <CardHeader>
                    <CardTitle>
                        {issueCount > 0
                            ? `${issueCount} bagian perlu diperbaiki`
                            : 'Semua pemeriksaan siap'}
                    </CardTitle>
                </CardHeader>
                <CardContent>
                    <nav
                        aria-label="Kesiapan server"
                        className="grid grid-cols-2 gap-2 xl:grid-cols-4"
                    >
                        {checks.map((check) => (
                            <a
                                key={check.id}
                                href={`#${check.id}`}
                                className="flex items-center gap-3 rounded-md border p-3 text-sm hover:bg-accent focus-visible:ring-2 focus-visible:ring-ring"
                            >
                                {check.ready ? (
                                    <CircleCheck className="size-4 shrink-0 text-success" />
                                ) : (
                                    <CircleAlert className="size-4 shrink-0 text-destructive" />
                                )}
                                <span>
                                    {check.label}
                                    <span className="block text-xs text-muted-foreground">
                                        {check.ready
                                            ? 'Siap digunakan'
                                            : 'Lihat penyebab dan perbaikan'}
                                    </span>
                                </span>
                            </a>
                        ))}
                    </nav>
                </CardContent>
            </Card>
            <Section
                title="Masa lisensi bawaan"
                description="Dipakai setiap situs yang tidak punya angkanya sendiri dan tidak memakai lisensi permanen. Berlaku pada penerbitan berikutnya; lisensi yang sudah terpasang di server klien tidak berubah."
            >
                <LicenseTermsForm terms={licenseTerms} />
            </Section>
            <div className="grid items-start gap-5 xl:grid-cols-2">
                <Section
                    id="release-key"
                    ready={checks[0].ready}
                    title="Kunci rilis"
                    description="Memastikan rilis yang dipasang berasal dari penerbit yang benar."
                >
                    <dl>
                        <Row label="Sidik jari SHA-256">
                            <Fingerprint state={releaseKey} />
                        </Row>
                    </dl>
                    <p className="text-xs text-muted-foreground">
                        Dihitung dari bentuk DER kunci publik, sama dengan{' '}
                        <code className="font-mono">
                            openssl pkey -pubin -in kunci.pem -outform DER |
                            sha256sum
                        </code>
                        .
                    </p>
                </Section>

                <Section
                    id="license-key"
                    ready={checks[1].ready}
                    title="Kunci lisensi"
                    description="Kedua kunci harus terbaca dan berpasangan agar server klien menerima lisensi."
                >
                    <dl>
                        <Row label="Kunci privat">
                            {licenseKey.private.ok ? (
                                'Terbaca'
                            ) : (
                                <Problem>{licenseKey.private.error}</Problem>
                            )}
                        </Row>
                        <Row label="Sidik jari kunci publik">
                            <Fingerprint state={licenseKey.public} />
                        </Row>
                        {licenseKey.pairMatches !== null && (
                            <Row label="Pasangan">
                                {licenseKey.pairMatches ? (
                                    'Kunci privat dan publik berpasangan'
                                ) : (
                                    <Problem>
                                        Kunci privat dan kunci publik bukan
                                        pasangan. Setiap lisensi yang
                                        diterbitkan akan ditolak server klien.
                                    </Problem>
                                )}
                            </Row>
                        )}
                    </dl>
                </Section>

                <Section
                    id="registry"
                    ready={checks[2].ready}
                    title="Registry (Harbor)"
                    description="Koneksi ini diperlukan untuk mengambil image saat pemasangan dan pembaruan."
                >
                    <dl data-test="registry">
                        <Row label="Alamat untuk server klien">
                            <span className="font-mono text-xs">
                                {registry.host}
                            </span>
                        </Row>
                        <Row label="Robot sistem">
                            {registry.robot ?? (
                                <Problem>
                                    Belum disetel. Jalankan php artisan
                                    registry:robot-sistem di server konsol.
                                </Problem>
                            )}
                        </Row>
                        {registry.robot !== null && (
                            <Row label="Keadaan">
                                {registry.check.ok ? (
                                    'Terhubung, robot diterima Harbor'
                                ) : (
                                    <Problem>{registry.check.error}</Problem>
                                )}
                            </Row>
                        )}
                    </dl>
                    <p className="text-xs text-muted-foreground">
                        Rahasia robot sistem tersimpan terenkripsi dan tidak
                        pernah ditampilkan. Pemakaian disk registry belum
                        dikumpulkan.
                    </p>
                </Section>

                <Section
                    id="dns"
                    ready={checks[3].ready}
                    title="DNS (Cloudflare)"
                    description="Koneksi ini diperlukan untuk membuat alamat aplikasi server klien secara otomatis."
                >
                    <dl data-test="dns">
                        <Row label="Bentuk alamat server klien">
                            <span className="font-mono text-xs">
                                {dns.baseDomain
                                    ? `https://<tenant>.${dns.baseDomain}`
                                    : '—'}
                            </span>
                        </Row>
                        <Row label="Token Cloudflare">
                            {dns.configured ? (
                                'Tersimpan'
                            ) : (
                                <Problem>
                                    Belum disetel. Jalankan php artisan
                                    dns:token-cloudflare di server konsol.
                                </Problem>
                            )}
                        </Row>
                        {dns.check !== null && (
                            <Row label="Keadaan">
                                {dns.check.ok ? (
                                    `Terhubung, zona ${dns.check.zone} terlihat`
                                ) : (
                                    <Problem>{dns.check.error}</Problem>
                                )}
                            </Row>
                        )}
                    </dl>
                    <p className="text-xs text-muted-foreground">
                        Token hanya butuh izin Zone → DNS → Edit untuk zona
                        domain dasar, tersimpan terenkripsi, dan tidak pernah
                        ditampilkan. Konsol hanya menyentuh record yang ia buat
                        sendiri.
                    </p>
                </Section>
            </div>
        </Shell>
    );
}
