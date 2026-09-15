import { Head } from '@inertiajs/react';
import type { ReactNode } from 'react';
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
 * Pengaturan konsol: kunci yang dibutuhkan server klien, dan tempat bagian registry.
 *
 * Isinya dibaca, bukan diubah. Kunci disetel lewat berkas di server konsol; halaman ini ada supaya
 * kunci yang hilang atau tertukar ditemukan operator sebelum perintah pasang dibuat — bukan oleh
 * teknisi yang terminalnya menjawab 503 di lokasi klien.
 */
export default function Settings({ releaseKey, licenseKey }: Props) {
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

            {/*
                Tempat untuk CP-06 di docs/todo/registry-harbor: status Harbor, robot sistem, dan
                pemakaian disk. Milik tim registry — bagian ini sengaja kosong sampai datanya dikirim
                controller `Settings`.
            */}
            <Section
                title="Registry (Harbor)"
                description="Status registry image — terjangkau, robot sistem, dan pemakaian disk — akan tampil di sini."
            >
                <p
                    className="rounded-md border border-dashed px-4 py-6 text-center text-sm text-muted-foreground"
                    data-test="harbor-slot"
                >
                    Belum tersedia. Bagian ini disiapkan untuk integrasi
                    registry Harbor.
                </p>
            </Section>
        </Shell>
    );
}
