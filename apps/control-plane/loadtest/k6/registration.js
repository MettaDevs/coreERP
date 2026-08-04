import http from 'k6/http';
import { check } from 'k6';
import { Counter, Trend } from 'k6/metrics';
import exec from 'k6/execution';

const BASE = __ENV.BASE_URL || 'http://lb';
const PROFILE = __ENV.PROFILE || 'registration';
const TENANTS = Number(__ENV.TENANTS || 100);
const VUS = Number(__ENV.VUS || 1000);
const LATENCY_VUS = Number(__ENV.LATENCY_VUS || 1);
const DURATION = __ENV.DURATION || '90s';
const RUN_ID = (__ENV.RUN_ID || 'local').replace(/[^a-zA-Z0-9-]/g, '-');

const csrfLatency = new Trend('csrf_read', true);
const registrationLatency = new Trend('registration_write', true);
const registrations = new Counter('registrations_created');
const serverErrors = new Counter('application_5xx');
const gatewaySaturation = new Counter('gateway_502_504');
const clientTimeouts = new Counter('client_timeouts');
const rateLimited = new Counter('rate_limited');
const unexpected = new Counter('unexpected_status');

const correctnessThresholds = {
    application_5xx: ['count==0'],
    rate_limited: ['count==0'],
    unexpected_status: ['count==0'],
};
const deliveryThresholds = {
    http_req_failed: ['rate==0'],
    checks: ['rate==1'],
};

export const options =
    PROFILE === 'registration'
        ? {
              scenarios: {
                  hundred_tenants: {
                      executor: 'per-vu-iterations',
                      vus: TENANTS,
                      iterations: 1,
                      maxDuration: '3m',
                  },
              },
              thresholds: {
                  ...correctnessThresholds,
                  ...deliveryThresholds,
                  registrations_created: [`count==${TENANTS}`],
              },
              summaryTrendStats: ['avg', 'min', 'med', 'p(90)', 'p(95)', 'p(99)', 'max'],
          }
        : PROFILE === 'latency'
          ? {
                scenarios: {
                    latency: {
                        executor: 'constant-vus',
                        vus: LATENCY_VUS,
                        duration: DURATION,
                        gracefulStop: '30s',
                    },
                },
                thresholds: {
                    ...correctnessThresholds,
                    ...deliveryThresholds,
                    csrf_read: ['p(95)<200', 'p(99)<500'],
                    registration_write: ['p(95)<400', 'p(99)<900'],
                },
                summaryTrendStats: ['avg', 'min', 'med', 'p(90)', 'p(95)', 'p(99)', 'max'],
            }
          : {
                scenarios: {
                    saturation: {
                        executor: 'constant-vus',
                        vus: VUS,
                        duration: DURATION,
                        gracefulStop: '60s',
                    },
                },
                thresholds: correctnessThresholds,
                summaryTrendStats: ['avg', 'min', 'med', 'p(90)', 'p(95)', 'p(99)', 'max'],
            };

function markFailure(response) {
    if (response.status === 502 || response.status === 504) {
        gatewaySaturation.add(1);
    } else if (response.status >= 500) {
        serverErrors.add(1);
    }
    if (response.status === 0) {
        clientTimeouts.add(1);
    }
    if (response.status === 429) {
        rateLimited.add(1);
    }
    if (![0, 201, 502, 504].includes(response.status)) {
        unexpected.add(1, { status: String(response.status) });
    }
}

export default function () {
    const iteration = exec.scenario.iterationInTest;
    const identity = `${RUN_ID}-${iteration}`;
    const clientIp = `10.${Math.floor(iteration / 65536) % 256}.${Math.floor(iteration / 256) % 256}.${iteration % 256}`;

    const form = http.get(`${BASE}/register`, {
        headers: { 'X-Forwarded-For': clientIp },
        tags: { op: 'csrf' },
    });
    csrfLatency.add(form.timings.duration);
    if (!check(form, { 'form registrasi 200': (response) => response.status === 200 })) {
        if (form.status === 502 || form.status === 504) {
            gatewaySaturation.add(1);
        } else if (form.status >= 500) {
            serverErrors.add(1);
        } else if (form.status === 0) {
            clientTimeouts.add(1);
        } else {
            unexpected.add(1, { status: String(form.status) });
        }
        return;
    }

    const cookies = http.cookieJar().cookiesForURL(BASE);
    const csrf = cookies['XSRF-TOKEN']?.[0];
    if (!csrf) {
        unexpected.add(1, { status: 'missing_csrf' });
        return;
    }

    const response = http.post(
        `${BASE}/api/v1/business-registrations`,
        JSON.stringify({
            name: `Owner ${identity}`,
            business_name: `Bisnis ${identity}`,
            app_ids: ['management-aset'],
            email: `core-load-${identity}@example.test`,
            password: 'Loadtest-Owner-2026!',
            password_confirmation: 'Loadtest-Owner-2026!',
        }),
        {
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                'X-XSRF-TOKEN': decodeURIComponent(csrf),
                'X-Forwarded-For': clientIp,
            },
            tags: { op: 'registration' },
        },
    );
    registrationLatency.add(response.timings.duration);
    markFailure(response);
    if (check(response, { 'registrasi 201': (result) => result.status === 201 })) {
        registrations.add(1);
    }
}

export function handleSummary(data) {
    const metric = (name, stat) => {
        const value = data.metrics[name]?.values?.[stat];
        return value === undefined ? null : Number(value.toFixed(2));
    };
    const summary = {
        profile: PROFILE,
        run_id: RUN_ID,
        tenants_requested: PROFILE === 'registration' ? TENANTS : null,
        vus_configured: PROFILE === 'saturation' ? VUS : PROFILE === 'latency' ? LATENCY_VUS : TENANTS,
        duration_s: Number((data.state?.testRunDurationMs ?? 0) / 1000).toFixed(1),
        registrations_created: data.metrics.registrations_created?.values?.count ?? 0,
        application_5xx: data.metrics.application_5xx?.values?.count ?? 0,
        gateway_502_504: data.metrics.gateway_502_504?.values?.count ?? 0,
        client_timeouts: data.metrics.client_timeouts?.values?.count ?? 0,
        rate_limited: data.metrics.rate_limited?.values?.count ?? 0,
        unexpected_status: data.metrics.unexpected_status?.values?.count ?? 0,
        csrf: {
            p95: metric('csrf_read', 'p(95)'),
            p99: metric('csrf_read', 'p(99)'),
        },
        registration: {
            p50: metric('registration_write', 'med'),
            p95: metric('registration_write', 'p(95)'),
            p99: metric('registration_write', 'p(99)'),
            max: metric('registration_write', 'max'),
        },
        thresholds_failed: Object.entries(data.metrics)
            .filter(([, value]) => value.thresholds && Object.values(value.thresholds).some((threshold) => threshold.ok === false))
            .map(([name]) => name),
    };

    return {
        stdout: `\n===== RINGKASAN REGISTRATION LOAD (${PROFILE}) =====\n${JSON.stringify(summary, null, 2)}\n`,
        [`/results/summary-${PROFILE}-${RUN_ID}.json`]: JSON.stringify({ summary, metrics: data.metrics }, null, 2),
    };
}
