import { router } from '@inertiajs/react';

/**
 * Pelaporan kesalahan peramban ke pengumpul OTLP.
 *
 * Sampai berkas ini ada, satu-satunya jejak kesalahan sisi peramban adalah `console.error`
 * pada `BatasKesalahan` — yaitu tidak ada jejak sama sekali, karena konsol milik peramban
 * pengguna dan tidak seorang pun di tim melihatnya. Kesalahan React yang hanya muncul pada
 * satu tenant, pada satu versi peramban, karena itu tidak pernah sampai ke siapa pun; yang
 * sampai hanya laporan lisan "halamannya kosong".
 *
 * **Kenapa penulisan sendiri, bukan `@opentelemetry/*`.** SDK peramban resmi membawa
 * penyedia, pemroses, penjadwal, serta konteks lacak — puluhan kilobyte terkompresi — dan
 * seluruhnya untuk satu hal yang dibutuhkan di sini: mengirim satu catatan kesalahan ke
 * satu alamat. Bundel ini diunduh setiap pengguna ERP, sebagian di jaringan kantor yang
 * lambat, jadi berat itu dibayar setiap hari demi kejadian yang jarang. Protokolnya sendiri
 * cukup kecil untuk ditulis langsung: OTLP/HTTP menerima JSON biasa pada `POST /v1/logs`,
 * dan bentuk muatannya adalah apa yang disusun `muatanLog()` di bawah.
 *
 * Batasnya perlu disebut supaya tidak salah harap: tidak ada jejak (trace), tidak ada
 * kumpulan-lalu-kirim, tidak ada percobaan ulang. Satu kesalahan berarti satu permintaan
 * yang boleh gagal diam-diam.
 *
 * **Mati secara bawaan.** Tanpa `VITE_OTEL_ENDPOINT` saat membangun, seluruh berkas ini
 * tidak melakukan apa-apa. Pemasangan di server pelanggan yang tidak punya pengumpul karena
 * itu tidak menembak alamat yang tidak ada pada setiap kesalahan — kegagalan telemetri yang
 * justru menambah kesalahan baru di konsol adalah cara tercepat membuat orang mematikannya
 * seluruhnya.
 *
 * **Pengumpulnya harus mengizinkan origin aplikasi.** Muatan dikirim dengan
 * `Content-Type: application/json` ke host lain, jadi peramban mendahuluinya dengan
 * permintaan `OPTIONS`. Pengumpul OTLP menolaknya kecuali `cors.allowed_origins` disetel.
 * Kalau lupa, tidak ada satu pun catatan yang sampai sementara aplikasi tetap terlihat
 * sehat — kegagalan yang hanya kelihatan di tab jaringan.
 */

type NilaiAtribut = string | number | boolean;
type Atribut = Record<string, NilaiAtribut | null | undefined>;

/** Dari mana kesalahan ditangkap; ketiganya menangkap hal yang berbeda. */
type SumberKesalahan =
    'batas-module' | 'kesalahan-jendela' | 'promise-tanpa-penangkap';

/**
 * Alamat pangkal pengumpul, mis. `http://127.0.0.1:4318`. Kosong berarti mati.
 *
 * Nilainya disisipkan Vite saat membangun, jadi ia tetap per-bangunan: satu bangunan SaaS
 * dengan pengumpul, satu bangunan on-prem tanpa, tanpa saklar apa pun saat berjalan.
 */
const alamatPengumpul = String(
    import.meta.env.VITE_OTEL_ENDPOINT ?? '',
).replace(/\/+$/, '');

const namaLayanan =
    String(import.meta.env.VITE_OTEL_SERVICE_NAME ?? '') ||
    'coreerp-control-plane';

/**
 * Batas laporan per pemuatan halaman.
 *
 * Kesalahan render React tidak datang sendirian: satu komponen yang melempar pada setiap
 * render menghasilkan lemparan baru setiap kali React mencoba lagi. Tanpa batas ini, satu
 * halaman rusak pada satu peramban dapat mengirim ribuan permintaan dalam sedetik, dan yang
 * pertama tumbang justru pengumpulnya.
 */
const BATAS_LAPORAN = 20;

let jumlahLaporan = 0;
let sudahDipasang = false;
const sidikYangSudahDikirim = new Set<string>();

/** Konteks halaman yang sedang terbuka, diperbarui setiap kunjungan Inertia. */
let komponenHalaman = '';
let idTenant = '';
let namaTenant = '';

function aktif(): boolean {
    return alamatPengumpul !== '';
}

function nilaiOtlp(nilai: NilaiAtribut): Record<string, unknown> {
    if (typeof nilai === 'boolean') {
        return { boolValue: nilai };
    }

    if (typeof nilai === 'number') {
        return Number.isInteger(nilai)
            ? { intValue: String(nilai) }
            : { doubleValue: nilai };
    }

    return { stringValue: nilai };
}

function atributOtlp(atribut: Atribut): Record<string, unknown>[] {
    return Object.entries(atribut)
        .filter(([, nilai]) => nilai !== null && nilai !== undefined)
        .map(([kunci, nilai]) => ({
            key: kunci,
            value: nilaiOtlp(nilai as NilaiAtribut),
        }));
}

/**
 * Bentuk muatan `POST /v1/logs`.
 *
 * `timeUnixNano` adalah string, bukan angka: nanodetik sejak epoch melewati bilangan bulat
 * aman JavaScript, dan pemetaan JSON milik OTLP memang meminta bilangan 64-bit ditulis
 * sebagai string. Mengirimnya sebagai angka membuat pengumpul menolak seluruh berkas dengan
 * galat penguraian yang tidak menyebut medannya.
 */
function muatanLog(pesan: string, atribut: Atribut): Record<string, unknown> {
    const waktu = `${Date.now()}000000`;

    return {
        resourceLogs: [
            {
                resource: {
                    attributes: atributOtlp({
                        'service.name': namaLayanan,
                        'telemetry.sdk.language': 'webjs',
                        'telemetry.sdk.name': 'coreerp-pelaporan-kesalahan',
                    }),
                },
                scopeLogs: [
                    {
                        scope: { name: 'coreerp.shell' },
                        logRecords: [
                            {
                                timeUnixNano: waktu,
                                observedTimeUnixNano: waktu,
                                // 17 adalah ERROR pada tangga keparahan OTLP.
                                severityNumber: 17,
                                severityText: 'ERROR',
                                body: { stringValue: pesan },
                                attributes: atributOtlp(atribut),
                            },
                        ],
                    },
                ],
            },
        ],
    };
}

/**
 * Pengiriman yang tidak boleh terlihat oleh sisa aplikasi.
 *
 * `keepalive` supaya catatan yang lahir saat halaman ditutup tetap terkirim, dan setiap
 * jalur kegagalan — jaringan, CORS, pengumpul mati — berakhir di penangkap kosong. Sebuah
 * `fetch` yang ditolak tanpa penangkap memicu `unhandledrejection`, dan itu berarti
 * pelaporan kesalahan yang melaporkan kegagalannya sendiri, tanpa henti.
 */
function kirim(muatan: Record<string, unknown>): void {
    try {
        void fetch(`${alamatPengumpul}/v1/logs`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(muatan),
            keepalive: true,
            mode: 'cors',
            credentials: 'omit',
        }).catch(() => undefined);
    } catch {
        // Sengaja dibiarkan. Telemetri yang menjatuhkan halaman lebih buruk daripada
        // telemetri yang hilang.
    }
}

function uraikanKesalahan(kesalahan: unknown): {
    tipe: string;
    pesan: string;
    tumpukan?: string;
} {
    if (kesalahan instanceof Error) {
        return {
            tipe: kesalahan.name,
            pesan: kesalahan.message,
            tumpukan: kesalahan.stack,
        };
    }

    if (typeof kesalahan === 'string') {
        return { tipe: 'string', pesan: kesalahan };
    }

    try {
        return {
            tipe: typeof kesalahan,
            pesan: JSON.stringify(kesalahan) ?? '',
        };
    } catch {
        return { tipe: typeof kesalahan, pesan: String(kesalahan) };
    }
}

/**
 * Melaporkan satu kesalahan. Aman dipanggil kapan pun; tanpa pengumpul ia langsung pulang.
 *
 * @param sumber Dari mana kesalahan ditangkap.
 * @param tambahan Atribut khusus penangkapnya, mis. nama halaman module.
 */
export function laporkanKesalahan(
    kesalahan: unknown,
    sumber: SumberKesalahan,
    tambahan: Atribut = {},
): void {
    if (!aktif()) {
        return;
    }

    try {
        if (jumlahLaporan >= BATAS_LAPORAN) {
            return;
        }

        const { tipe, pesan, tumpukan } = uraikanKesalahan(kesalahan);

        // Kesalahan yang sama berulang — render yang terus gagal, permintaan yang terus
        // ditolak — cukup diketahui sekali per pemuatan halaman.
        const sidik = `${sumber}|${tipe}|${pesan}|${komponenHalaman}`;

        if (sidikYangSudahDikirim.has(sidik)) {
            return;
        }

        sidikYangSudahDikirim.add(sidik);
        jumlahLaporan += 1;

        kirim(
            muatanLog(pesan || tipe, {
                'exception.type': tipe,
                'exception.message': pesan,
                'exception.stacktrace': tumpukan,
                'coreerp.sumber_kesalahan': sumber,
                'coreerp.halaman': komponenHalaman || undefined,
                'coreerp.tenant_id': idTenant || undefined,
                'coreerp.tenant_name': namaTenant || undefined,
                'url.full': window.location.href,
                'url.path': window.location.pathname,
                'user_agent.original': navigator.userAgent,
                ...tambahan,
            }),
        );
    } catch {
        // Sama seperti `kirim`: kegagalan di sini tidak boleh menular ke pemanggilnya,
        // yang sedang menangani kesalahan lain.
    }
}

type PropsHalamanInertia = Record<string, unknown> | undefined;

function catatKonteks(komponen: string, props: PropsHalamanInertia): void {
    komponenHalaman = komponen;

    const auth = props?.auth as
        | { membership?: { tenant_id?: string; tenant_name?: string } | null }
        | undefined;

    idTenant = auth?.membership?.tenant_id ?? '';
    namaTenant = auth?.membership?.tenant_name ?? '';
}

/**
 * Konteks untuk kesalahan yang terjadi **sebelum** kunjungan Inertia pertama.
 *
 * Halaman awal tidak datang lewat `router.on('navigate')` pada setiap jalur — kunjungan
 * pertama sudah tercetak di HTML — jadi tanpa ini kesalahan paling awal, yang justru paling
 * sering berupa bundel gagal dimuat, terkirim tanpa tenant maupun nama halaman.
 *
 * Muatannya dibaca dari **isi** elemen, bukan dari atributnya. Inertia 3 mencetak
 * `<script data-page="app" type="application/json">{…}</script>`, dan di sana `data-page`
 * cuma penanda bernilai `"app"`. Bentuk lama — `<div id="app" data-page="{…}">` — menaruh
 * halamannya di atribut, sehingga kode yang membaca `dataset.page` selalu berakhir pada
 * `JSON.parse('app')`. Lemparannya ditelan `catch` di bawah, jadi kekeliruan itu tidak
 * berbunyi sama sekali: fungsi ini sekadar tidak pernah bekerja, dan setiap kesalahan
 * sebelum kunjungan pertama terkirim tanpa tenant maupun nama halaman — persis yang
 * hendak dicegahnya.
 */
function konteksAwalDariDom(): void {
    try {
        const wadah =
            document.querySelector<HTMLScriptElement>('script[data-page]');
        const mentah = wadah?.textContent;

        if (!mentah) {
            return;
        }

        const halaman = JSON.parse(mentah) as {
            component?: string;
            props?: Record<string, unknown>;
        };

        catatKonteks(halaman.component ?? '', halaman.props);
    } catch {
        // Halaman awal yang tidak terbaca hanya berarti atribut yang kurang lengkap.
    }
}

/**
 * Memasang penangkap kesalahan global. Dipanggil sekali dari `app.tsx`.
 *
 * Tiga penangkap, karena ketiganya menangkap hal yang berbeda dan tidak satu pun mencakup
 * yang lain:
 *
 * - `BatasKesalahan` — kesalahan render React di dalam halaman module. Ini satu-satunya
 *   yang menyebut *module* mana yang gagal, dan ia memanggil `laporkanKesalahan` sendiri.
 * - `error` pada `window` — kesalahan skrip biasa, kegagalan memuat sumber daya, dan
 *   kesalahan render yang tidak tertangkap pembatas mana pun (React 19 meneruskannya ke
 *   `window.reportError`, yang muncul sebagai event ini).
 * - `unhandledrejection` — promise yang ditolak tanpa penangkap. Seluruh pengambilan data
 *   ke Core lewat jalur ini; tanpanya, permintaan yang gagal senyap sepenuhnya.
 *
 * Dipakai `addEventListener`, bukan `window.onerror`, supaya penangkap lain — milik
 * peramban, ekstensi, atau kode yang datang kemudian — tidak tergusur.
 */
export function pasangPelaporanKesalahan(): void {
    if (!aktif() || sudahDipasang) {
        return;
    }

    sudahDipasang = true;

    konteksAwalDariDom();

    router.on('navigate', (peristiwa) => {
        catatKonteks(
            peristiwa.detail.page.component,
            peristiwa.detail.page.props,
        );
    });

    window.addEventListener(
        'error',
        (peristiwa) => {
            // Event yang sama dipakai dua hal yang berbeda. Kesalahan skrip membawa `error`
            // atau setidaknya `message`; kegagalan memuat sumber daya — gambar, potongan kode
            // — tidak membawa keduanya, dan satu-satunya keterangannya adalah elemen yang
            // gagal. Tanpa pemisahan ini, kegagalan sumber daya terkirim sebagai kesalahan
            // bernama "undefined" yang tidak menunjuk apa pun.
            const kesalahan = peristiwa.error ?? peristiwa.message;

            if (kesalahan) {
                laporkanKesalahan(kesalahan, 'kesalahan-jendela', {
                    'code.filepath': peristiwa.filename || undefined,
                    'code.lineno': peristiwa.lineno || undefined,
                    'code.column': peristiwa.colno || undefined,
                });

                return;
            }

            const elemen = peristiwa.target as HTMLElement | null;
            const alamat =
                elemen?.getAttribute?.('src') ?? elemen?.getAttribute?.('href');

            if (!alamat) {
                return;
            }

            laporkanKesalahan(
                `Sumber daya gagal dimuat: ${alamat}`,
                'kesalahan-jendela',
                {
                    'coreerp.sumber_daya': alamat,
                    'coreerp.elemen': elemen?.tagName?.toLowerCase(),
                },
            );

            // Fase tangkap, dan itu bukan pilihan gaya. Event `error` milik sumber daya yang
            // gagal dimuat tidak menggelembung, jadi penangkap tanpa `true` hanya menerima
            // kesalahan skrip — dan potongan kode yang gagal diunduh, yang justru paling ingin
            // diketahui setelah penyebaran baru, tidak pernah sampai.
        },
        true,
    );

    window.addEventListener('unhandledrejection', (peristiwa) => {
        laporkanKesalahan(peristiwa.reason, 'promise-tanpa-penangkap');
    });
}
