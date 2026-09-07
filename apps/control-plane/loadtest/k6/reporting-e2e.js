import { check, sleep } from 'k6';
import exec from 'k6/execution';
import http from 'k6/http';
import { Counter, Trend } from 'k6/metrics';

/**
 * Uji end-to-end laporan lintas app pada stack lokal (erp-dev), tanpa menyentuh UI:
 *
 *   daftar bisnis baru -> login owner -> katalog laporan Core
 *   -> minta ekspor Excel dan PDF "daftar work order" -> worker Core memanggil
 *   management-aset untuk dataset -> render (PDF lewat core-renderer) -> unduh.
 *
 * Setiap VU adalah tenant baru dengan owner-nya sendiri, jadi skenario ini juga
 * membuktikan isolasi tenant pada katalog dan riwayat ekspor: owner tidak pernah
 * melihat ekspor VU lain.
 *
 * Jalankan dari host (Docker) terhadap stack erp-dev:
 *   docker run --rm -i -v "$PWD/k6:/scripts" -e BASE_URL=http://host.docker.internal:8000 \
 *     grafana/k6:0.55.0 run /scripts/reporting-e2e.js
 */

const BASE = __ENV.BASE_URL || 'http://host.docker.internal:8000';
const APP_ID = __ENV.APP_ID || 'management-aset';
const REPORT = `${APP_ID}.${__ENV.REPORT || 'daftar-work-order'}`;
const VUS = Number(__ENV.VUS || 3);
const ITERATIONS = Number(__ENV.ITERATIONS || 1);
const WAIT_SECONDS = Number(__ENV.WAIT_SECONDS || 90);
const RUN_ID = (__ENV.RUN_ID || `e2e-${Date.now()}`).replace(/[^a-zA-Z0-9-]/g, '-');

const exportsDone = new Counter('exports_done');
const exportsFailed = new Counter('exports_failed');
const exportsTimedOut = new Counter('exports_timed_out');
const serverErrors = new Counter('application_5xx');
const queueLatency = new Trend('export_queue_to_done', true);
const downloadBytes = new Trend('download_bytes');

export const options = {
    scenarios: {
        reporting: {
            executor: 'per-vu-iterations',
            vus: VUS,
            iterations: ITERATIONS,
            maxDuration: '10m',
        },
    },
    thresholds: {
        application_5xx: ['count==0'],
        exports_failed: ['count==0'],
        exports_timed_out: ['count==0'],
        checks: ['rate==1'],
        exports_done: [`count==${VUS * ITERATIONS * 2}`],
    },
    summaryTrendStats: ['avg', 'min', 'med', 'p(90)', 'p(95)', 'max'],
};

const jsonHeaders = (csrf) => ({
    Accept: 'application/json',
    'Content-Type': 'application/json',
    'X-Requested-With': 'XMLHttpRequest',
    'X-XSRF-TOKEN': decodeURIComponent(csrf),
});

function csrfToken() {
    const cookies = http.cookieJar().cookiesForURL(BASE);

    return cookies['XSRF-TOKEN']?.[0] ?? '';
}

function track(response) {
    if (response.status >= 500) {
        serverErrors.add(1, { status: String(response.status) });
    }
}

export default function () {
    const identity = `${RUN_ID}-${exec.vu.idInTest}-${exec.scenario.iterationInInstance}`;

    // 1. Sesi baru: owner tenant baru, entitlement management-aset, login otomatis.
    const form = http.get(`${BASE}/register`, { tags: { op: 'csrf' } });
    check(form, { 'form registrasi 200': (r) => r.status === 200 });
    const registration = http.post(
        `${BASE}/api/v1/business-registrations`,
        JSON.stringify({
            name: `Owner ${identity}`,
            business_name: `Bisnis ${identity}`,
            app_ids: [APP_ID],
            email: `reporting-${identity}@example.test`,
            password: 'Loadtest-Owner-2026!',
            password_confirmation: 'Loadtest-Owner-2026!',
        }),
        { headers: jsonHeaders(csrfToken()), tags: { op: 'registration' } },
    );
    track(registration);

    if (!check(registration, { 'registrasi 201': (r) => r.status === 201 })) {
        return;
    }

    // Registrasi lewat API tidak membuka sesi; masuk seperti pengguna dengan akun
    // yang baru dibuat skrip ini sendiri.
    http.get(`${BASE}/login`, { tags: { op: 'csrf' } });
    const login = http.post(
        `${BASE}/login`,
        JSON.stringify({ email: `reporting-${identity}@example.test`, password: 'Loadtest-Owner-2026!' }),
        { headers: jsonHeaders(csrfToken()), tags: { op: 'login' } },
    );
    track(login);

    if (!check(login, { 'login 200': (r) => r.status === 200 })) {
        return;
    }

    // 2. Katalog laporan Core menyebut laporan app dan owner boleh menjalankannya.
    const catalog = http.get(`${BASE}/api/v1/reports`, {
        headers: { Accept: 'application/json' },
        tags: { op: 'catalog' },
    });
    track(catalog);
    const reports = catalog.status === 200 ? catalog.json('data') : [];
    const report = Array.isArray(reports) ? reports.find((item) => item.code === REPORT) : undefined;

    if (
        !check(catalog, {
            'katalog 200': (r) => r.status === 200,
            'laporan ada di katalog': () => report !== undefined,
            'owner boleh menjalankan': () => report?.can_run === true,
        })
    ) {
        return;
    }

    // 3. Layout default dan placeholder datang dari app lewat Core.
    const layouts = http.get(`${BASE}/api/v1/reports/${REPORT}/layouts`, {
        headers: { Accept: 'application/json' },
        tags: { op: 'layouts' },
    });
    track(layouts);
    check(layouts, {
        'layout 200': (r) => r.status === 200,
        'ada layout bawaan': (r) => (r.json('data') ?? []).some((l) => l.source === 'builtin'),
    });
    const fields = http.get(`${BASE}/api/v1/reports/${REPORT}/fields`, {
        headers: { Accept: 'application/json' },
        tags: { op: 'fields' },
    });
    track(fields);
    check(fields, {
        'fields 200 (Core memanggil app)': (r) => r.status === 200,
        'placeholder terisi': (r) => (r.json('data') ?? []).length > 0,
    });

    // 4. Dua ekspor: Excel langsung dari layout, PDF lewat core-renderer.
    for (const format of ['xlsx', 'pdf']) {
        const requested = Date.now();
        const created = http.post(
            `${BASE}/api/v1/reports/${REPORT}/exports`,
            JSON.stringify({ format, layout_ref: null, parameters: { status: null } }),
            { headers: jsonHeaders(csrfToken()), tags: { op: 'export-create', format } },
        );
        track(created);

        if (!check(created, { [`ekspor ${format} 202`]: (r) => r.status === 202 })) {
            exportsFailed.add(1, { format, stage: 'create' });
            continue;
        }

        const id = created.json('data.id');

        // 5. Worker Core mengerjakannya; kita hanya memantau.
        let status = created.json('data.status');
        let last = created.json('data');
        const deadline = Date.now() + WAIT_SECONDS * 1000;

        while ((status === 'queued' || status === 'running') && Date.now() < deadline) {
            sleep(1);
            const poll = http.get(`${BASE}/api/v1/report-exports/${id}`, {
                headers: { Accept: 'application/json' },
                tags: { op: 'export-poll' },
            });
            track(poll);

            if (poll.status !== 200) {
                break;
            }

            last = poll.json('data');
            status = last.status;
        }

        if (status === 'done') {
            exportsDone.add(1, { format });
            queueLatency.add(Date.now() - requested, { format });
        } else if (status === 'failed') {
            exportsFailed.add(1, { format, stage: 'worker' });
            console.error(`Ekspor ${format} gagal: ${last.failure_message}`);
            continue;
        } else {
            exportsTimedOut.add(1, { format });
            console.error(`Ekspor ${format} tidak selesai dalam ${WAIT_SECONDS}s (status ${status}).`);
            continue;
        }

        // 6. Berkas hasil dapat diunduh dengan tipe yang benar.
        const download = http.get(`${BASE}/api/v1/report-exports/${id}/download`, {
            tags: { op: 'export-download', format },
            responseType: 'binary',
        });
        track(download);
        const expectedType =
            format === 'pdf'
                ? 'application/pdf'
                : 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';
        check(download, {
            [`unduh ${format} 200`]: (r) => r.status === 200,
            [`tipe ${format} benar`]: (r) => (r.headers['Content-Type'] ?? '').startsWith(expectedType),
            [`isi ${format} tidak kosong`]: (r) => r.body && r.body.byteLength > 100,
        });

        if (download.body) {
            downloadBytes.add(download.body.byteLength, { format });
        }
    }

    // 7. Riwayat hanya berisi milik owner ini: dua ekspor, tidak lebih.
    const history = http.get(`${BASE}/api/v1/report-exports`, {
        headers: { Accept: 'application/json' },
        tags: { op: 'history' },
    });
    track(history);
    check(history, {
        'riwayat 200': (r) => r.status === 200,
        'riwayat hanya milik tenant ini': (r) => (r.json('data') ?? []).length === 2,
    });
}

export function handleSummary(data) {
    const metric = (name, stat) => {
        const value = data.metrics[name]?.values?.[stat];

        return value === undefined ? null : Number(value.toFixed(2));
    };
    const summary = {
        run_id: RUN_ID,
        vus: VUS,
        iterations: ITERATIONS,
        exports_done: data.metrics.exports_done?.values?.count ?? 0,
        exports_failed: data.metrics.exports_failed?.values?.count ?? 0,
        exports_timed_out: data.metrics.exports_timed_out?.values?.count ?? 0,
        application_5xx: data.metrics.application_5xx?.values?.count ?? 0,
        checks_rate: metric('checks', 'rate'),
        queue_to_done_ms: {
            med: metric('export_queue_to_done', 'med'),
            p95: metric('export_queue_to_done', 'p(95)'),
            max: metric('export_queue_to_done', 'max'),
        },
        download_bytes_avg: metric('download_bytes', 'avg'),
        // Nama check yang gagal, supaya ringkasan satu baris ini cukup untuk tahu di mana
        // alur putus tanpa membaca keluaran k6 selengkapnya.
        failed_checks: Object.values(data.root_group?.checks ?? {})
            .filter((item) => item.fails > 0)
            .map((item) => `${item.name} (${item.fails})`),
    };
    const line = `${JSON.stringify(summary)}\n`;

    return {
        stdout: line,
        [`/results/summary-reporting-${RUN_ID}.json`]: line,
    };
}
