import { fail } from 'k6';
import exec from 'k6/execution';
import http from 'k6/http';
import { Counter, Trend } from 'k6/metrics';
import { BASE, jsonHeaders } from './lib.js';

const PROFILE = __ENV.PROFILE || 'saturation';
const VUS = Number(__ENV.VUS || 1000);
const LATENCY_VUS = Number(__ENV.LATENCY_VUS || 8);
const DURATION = __ENV.DURATION || '90s';
const RUN_ID = __ENV.RUN_ID || 'local';
const PASSWORD = __ENV.LOADTEST_PASSWORD || 'Loadtest-Owner-2026!';
const QUERY_CODE = 'asset-register-by-group';

const queryLatency = new Trend('analytics_query', true);
const oracleValue = new Trend('analytics_oracle_value');
const applicationErrors = new Counter('application_5xx');
const gatewaySaturation = new Counter('gateway_502_504');
const clientTimeouts = new Counter('client_timeouts');
const busyResponses = new Counter('analytics_busy');
const unexpectedStatuses = new Counter('unexpected_status');

const latencyThresholds = {
    application_5xx: ['count==0'],
    gateway_502_504: ['count==0'],
    client_timeouts: ['count==0'],
    analytics_busy: ['count==0'],
    unexpected_status: ['count==0'],
    analytics_query: ['p(95)<200', 'p(99)<500'],
};

export const options = {
    scenarios: {
        analytics: {
            executor: 'constant-vus',
            vus: PROFILE === 'latency' ? LATENCY_VUS : VUS,
            duration: DURATION,
            gracefulStop: '30s',
        },
    },
    thresholds: PROFILE === 'latency'
        ? latencyThresholds
        : { application_5xx: ['count==0'] },
    summaryTrendStats: ['avg', 'min', 'med', 'p(90)', 'p(95)', 'p(99)', 'max'],
};

function batch(requests) {
    const responses = [];

    for (let index = 0; index < requests.length; index += 64) {
        responses.push(...http.batch(requests.slice(index, index + 64)));
    }

    return responses;
}

function csrfFrom(jar) {
    const value = jar.cookiesForURL(BASE)['XSRF-TOKEN']?.[0];

    return value ? decodeURIComponent(value) : '';
}

function cookiesFrom(jar) {
    return Object.fromEntries(
        Object.entries(jar.cookiesForURL(BASE)).map(([name, values]) => [name, values[0]]),
    );
}

function loadSessions(fixture) {
    const loginRows = fixture.tenants.flatMap((tenant) =>
        Object.entries(tenant.users).map(([kind, user]) => ({ tenant, kind, user, jar: new http.CookieJar() })),
    );
    const forms = batch(loginRows.map(({ jar }) => ['GET', `${BASE}/login`, null, { jar }]));

    forms.forEach((response, index) => {
        if (response.status !== 200 || !csrfFrom(loginRows[index].jar)) {
            fail(`form login fixture gagal untuk ${loginRows[index].user.email}: ${response.status}`);
        }
    });

    const logins = batch(loginRows.map(({ user, jar }) => [
        'POST',
        `${BASE}/login`,
        JSON.stringify({ email: user.email, password: PASSWORD }),
        { jar, headers: jsonHeaders(csrfFrom(jar)) },
    ]));
    const sessions = fixture.tenants.map(() => ({}));

    logins.forEach((response, index) => {
        const row = loginRows[index];

        if (response.status !== 200) {
            fail(`login fixture gagal untuk ${row.user.email}: ${response.status} ${String(response.body).slice(0, 250)}`);
        }

        sessions[row.tenant.index][row.kind] = {
            tenantId: row.tenant.tenant_id,
            email: row.user.email,
            cookies: cookiesFrom(row.jar),
            csrf: csrfFrom(row.jar),
        };
    });

    return sessions;
}

export function setup() {
    let fixture;

    try {
        fixture = JSON.parse(open('/results/analytics-fixture.json'));
    } catch (error) {
        fail(`Fixture analitik tidak terbaca. Siapkan stack dengan LOADTEST_ANALYTICS=1: ${String(error)}`);
    }

    if (fixture.tenants.length < 100) {
        fail(`Fixture hanya berisi ${fixture.tenants.length} tenant; gate meminta sedikitnya 100.`);
    }

    return {
        fixtureId: fixture.fixture_id,
        dataset: fixture.dataset || __ENV.ANALYTICS_DATASET,
        tenants: fixture.tenants,
        sessions: loadSessions(fixture),
    };
}

export default function (data) {
    const vuIndex = exec.vu.idInTest - 1;
    const tenantIndex = vuIndex % data.tenants.length;
    const kind = ['owner', 'two_units', 'no_grant'][Math.floor(vuIndex / data.tenants.length) % 3];
    const tenant = data.tenants[tenantIndex];
    const session = data.sessions[tenantIndex][kind];
    const jar = new http.CookieJar();

    Object.entries(session.cookies).forEach(([name, value]) => jar.set(BASE, name, value));

    const response = http.post(
        `${BASE}/api/v1/analytics/query`,
        JSON.stringify({
            dataset: data.dataset,
            dimensions: ['group_aset_id'],
            measures: ['count', 'acquisition_value'],
        }),
        {
            jar,
            headers: jsonHeaders(session.csrf),
            tags: { op: QUERY_CODE },
        },
    );

    if (response.status === 200) {
        queryLatency.add(response.timings.duration);

        if (__ITER === 0) {
            const rows = response.json('rows') || [];

            if (rows.length === 0) {
                oracleValue.add(0, {
                    fixture_id: data.fixtureId,
                    run_id: RUN_ID,
                    scenario: 'explore',
                    query_code: QUERY_CODE,
                    metric: 'empty',
                    tenant_id: session.tenantId,
                    user_email: session.email,
                    access_kind: kind === 'owner' ? 'all' : kind === 'two_units' ? 'two_units' : 'none',
                    group_key: '',
                    currency_code: '',
                });
            }

            rows.forEach((row) => {
                const tags = {
                    fixture_id: data.fixtureId,
                    run_id: RUN_ID,
                    scenario: 'explore',
                    query_code: QUERY_CODE,
                    tenant_id: session.tenantId,
                    user_email: session.email,
                    access_kind: kind === 'owner' ? 'all' : kind === 'two_units' ? 'two_units' : 'none',
                    group_key: String(row.group_aset_id || ''),
                    currency_code: String(row.currency_code || ''),
                };

                oracleValue.add(Number(row.count), { ...tags, metric: 'count' });
                oracleValue.add(Number(row.acquisition_value), { ...tags, metric: 'acquisition_value' });
            });
        }

        return;
    }

    if (response.status === 429) {
        busyResponses.add(1);

        return;
    }

    if (response.status === 502 || response.status === 504) {
        gatewaySaturation.add(1);

        return;
    }

    if (response.status === 0) {
        clientTimeouts.add(1);

        return;
    }

    if (response.status >= 500) {
        applicationErrors.add(1);
    }

    unexpectedStatuses.add(1, { status: String(response.status) });
}

export function handleSummary(data) {
    const value = (name, stat) => data.metrics[name]?.values?.[stat] ?? null;
    const summary = {
        profile: PROFILE,
        run_id: RUN_ID,
        fixture_id: __ENV.ANALYTICS_FIXTURE_ID || 'a10',
        tenants: Number(__ENV.TENANTS || 128),
        vus: PROFILE === 'latency' ? LATENCY_VUS : VUS,
        duration_s: Number(((data.state?.testRunDurationMs ?? 0) / 1000).toFixed(1)),
        application_5xx: value('application_5xx', 'count') || 0,
        gateway_502_504: value('gateway_502_504', 'count') || 0,
        client_timeouts: value('client_timeouts', 'count') || 0,
        analytics_busy: value('analytics_busy', 'count') || 0,
        unexpected_status: value('unexpected_status', 'count') || 0,
        query_ms: {
            p95: value('analytics_query', 'p(95)'),
            p99: value('analytics_query', 'p(99)'),
        },
        thresholds_failed: Object.entries(data.metrics)
            .filter(([, metric]) => metric.thresholds && Object.values(metric.thresholds).some((threshold) => !threshold.ok))
            .map(([name]) => name),
    };

    return {
        stdout: `\n===== ANALYTICS EXPLORE (${PROFILE}) =====\n${JSON.stringify(summary, null, 2)}\n`,
        [`/results/analytics-summary-${RUN_ID}.json`]: JSON.stringify({ summary, metrics: data.metrics }, null, 2),
    };
}
