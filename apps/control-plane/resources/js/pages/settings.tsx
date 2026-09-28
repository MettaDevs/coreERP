import { Button } from '@apperp/ui/button';
import { Input } from '@apperp/ui/input';
import { Head, useForm } from '@inertiajs/react';
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
}: {
    title: string;
    description: string;
    children: ReactNode;
}) {
    return (
        <section className="space-y-4 rounded-lg border bg-background p-5">
            <div>
                <h2 className="text-base font-semibold">{title}</h2>
                <p className="mt-1 max-w-2xl text-sm text-muted-foreground">
                    {description}
                </p>
            </div>
            {children}
        </section>
    );
}

function Row({ label, children }: { label: string; children: ReactNode }) {
    return (
        <div className="flex flex-wrap justify-between gap-4 border-b py-2.5 last:border-b-0">
            <dt className="text-sm text-muted-foreground">{label}</dt>
            <dd className="max-w-full text-sm font-medium break-all">
                {children}
            </dd>
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
/**
 * Masa lisensi bawaan: berapa lama lisensi berlaku, dan berapa hari sebelum habis ia diperpanjang.
 *
 * Angka kedua sekaligus menjawab pertanyaan yang tidak pernah ditanyakan sampai terjadi: berapa lama
 * konsol ini boleh mati tanpa mengunci klinik. Jawabannya masa lisensi dikurangi jendela perpanjangan,
 * dan kalimat di bawah isian menyebutkannya, supaya angkanya tidak perlu dihitung orang di kepala.
 */
function LicenseTermsForm({
    terms,
}: {
    terms: { validDays: number; renewBeforeDays: number };
}) {
    const { data, setData, patch, processing, errors } = useForm({
        valid_days: String(terms.validDays),
        renew_before_days: String(terms.renewBeforeDays),
    });

    const valid = Number(data.valid_days);
    const renew = Number(data.renew_before_days);
    const tolerance =
        Number.isFinite(valid) && Number.isFinite(renew) && valid > renew
            ? valid - renew
            : null;

    return (
        <form
            className="space-y-4"
            onSubmit={(e: FormEvent) => {
                e.preventDefault();
                patch('/pengaturan/lisensi', { preserveScroll: true });
            }}
        >
            <div className="grid gap-4 sm:grid-cols-2">
                <div className="space-y-2">
                    <Input
                        label="Masa berlaku (hari)"
                        type="number"
                        min={1}
                        max={3650}
                        required
                        value={data.valid_days}
                        onChange={(e) => setData('valid_days', e.target.value)}
                    />
                    {errors.valid_days && (
                        <p className="text-sm text-destructive">
                            {errors.valid_days}
                        </p>
                    )}
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
                    {errors.renew_before_days && (
                        <p className="text-sm text-destructive">
                            {errors.renew_before_days}
                        </p>
                    )}
                </div>
            </div>

            <p className="text-xs text-muted-foreground">
                {tolerance === null
                    ? 'Perpanjangan harus mulai sebelum lisensinya habis.'
                    : `Konsol ini boleh mati paling lama ${tolerance} hari sebelum server klien mulai kehilangan lisensinya.`}
            </p>

            <Button type="submit" disabled={processing}>
                Simpan masa lisensi
            </Button>
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
    return (
        <Shell
            title="Pengaturan"
            description="Kunci yang dipakai server klien untuk memeriksa rilis dan lisensi. Sidik jarinya dicocokkan dengan yang dipaku agen di server klien."
        >
            <Head title="Pengaturan" />

            <Section
                title="Kunci rilis"
                description="Kunci publik yang diantar ke agen saat pemasangan pertama dan dipakai memeriksa tanda tangan setiap rilis. Kunci privatnya tidak pernah ada di konsol ini."
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
                title="Kunci lisensi"
                description="Konsol ini menandatangani lisensi server klien dengan kunci privat, dan agen memeriksanya dengan kunci publik yang diantar saat pendaftaran. Tanpa kunci privat, lisensi tidak diperpanjang dan server klien terkunci saat lisensinya habis."
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
                                    pasangan. Setiap lisensi yang diterbitkan
                                    akan ditolak server klien.
                                </Problem>
                            )}
                        </Row>
                    )}
                </dl>
            </Section>

            <Section
                title="Masa lisensi bawaan"
                description="Dipakai setiap situs yang tidak punya angkanya sendiri dan tidak memakai lisensi permanen. Berlaku pada penerbitan berikutnya; lisensi yang sudah terpasang di server klien tidak berubah."
            >
                <LicenseTermsForm terms={licenseTerms} />
            </Section>

            <Section
                title="Registry (Harbor)"
                description="Server klien menarik image rilis dari registry ini dengan robot pull-only yang diterbitkan konsol per operasi. Tanpa robot sistem yang diterima Harbor, pemasangan dan pembaruan gagal saat menarik image."
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
                    Rahasia robot sistem tersimpan terenkripsi dan tidak pernah
                    ditampilkan. Pemakaian disk registry belum dikumpulkan.
                </p>
            </Section>

            <Section
                title="DNS (Cloudflare)"
                description="Setiap server klien mendapat alamat aplikasi otomatis di domain dasar. Konsol membuat record DNS-nya ke IP server klien saat perintah pasang dibuat, dan menghapusnya saat server klien dicabut. Tanpa token yang melihat zonanya, perintah pasang tidak dapat dibuat."
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
                    Token hanya butuh izin Zone → DNS → Edit untuk zona domain
                    dasar, tersimpan terenkripsi, dan tidak pernah ditampilkan.
                    Konsol hanya menyentuh record yang ia buat sendiri.
                </p>
            </Section>
        </Shell>
    );
}
