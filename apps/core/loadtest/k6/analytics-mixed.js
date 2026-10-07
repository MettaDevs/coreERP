import { sleep } from 'k6';
import exec from 'k6/execution';
import http from 'k6/http';
import { Counter, Trend } from 'k6/metrics';
import {
    actorForVu,
    ownerForTenant,
    recordOracleRows,
    requestParams,
    setupAnalytics,
} from './analytics-common.js';
import { BASE, urlModule } from './lib.js';

const PROFILE = __ENV.PROFILE || 'saturation';
const DURATION = __ENV.DURATION || '90s';
const ANALYTICS_VUS = Number(
    __ENV.ANALYTICS_VUS || (PROFILE === 'latency' ? 6 : 700),
);
const ASSET_VUS = Number(__ENV.ASSET_VUS || (PROFILE === 'latency' ? 2 : 300));
const ANALYTICS_THINK_TIME = Number(__ENV.ANALYTICS_THINK_TIME || 2);
const ASSET_THINK_TIME = Number(__ENV.ASSET_THINK_TIME || 1);
const RUN_ID = __ENV.RUN_ID || 'local';
const QUERY_CODE = 'asset-register-by-group';

const analyticsLatency = new Trend('mixed_analytics_read', true);
const assetReadLatency = new Trend('mixed_asset_read', true);
const assetWriteLatency = new Trend('mixed_asset_write', true);
const oracleValue = new Trend('analytics_oracle_value');
const applicationErrors = new Counter('application_5xx');
const gatewaySaturation = new Counter('gateway_502_504');
const clientTimeouts = new Counter('client_timeouts');
const analyticsBusy = new Counter('analytics_busy');
const rateLimited = new Counter('rate_limited');
const unexpectedStatuses = new Counter('unexpected_status');

const thresholds = {
    application_5xx: ['count==0'],
    unexpected_status: ['count==0'],
};

if (PROFILE === 'latency') {
    Object.assign(thresholds, {
        gateway_502_504: ['count==0'],
        client_timeouts: ['count==0'],
        analytics_busy: ['count==0'],
        unexpected_status: ['count==0'],
        mixed_analytics_read: ['p(95)<200', 'p(99)<500'],
        mixed_asset_read: ['p(95)<200', 'p(99)<500'],
        mixed_asset_write: ['p(95)<400', 'p(99)<900'],
    });
}

export const options = {
    scenarios: {
        analytics: {
            executor: 'constant-vus',
            exec: 'analytics',
            vus: ANALYTICS_VUS,
            duration: DURATION,
            gracefulStop: '30s',
        },
        asset_transactions: {
            executor: 'constant-vus',
            exec: 'assetTransaction',
            vus: ASSET_VUS,
            duration: DURATION,
            gracefulStop: '30s',
        },
    },
    thresholds,
    summaryTrendStats: ['avg', 'min', 'med', 'p(90)', 'p(95)', 'p(99)', 'max'],
};

export function setup() {
    return setupAnalytics();
}

function trackFailure(response, analyticsRequest = false) {
    if (response.status === 429) {
        (analyticsRequest ? analyticsBusy : rateLimited).add(1);
    } else if (response.status === 502 || response.status === 504) {
        gatewaySaturation.add(1);
    } else if (response.status === 0) {
        clientTimeouts.add(1);
    } else {
        if (response.status >= 500) {
            applicationErrors.add(1);
        }

        unexpectedStatuses.add(1, { status: String(response.status) });
    }
}

export function analytics(data) {
    const actor = actorForVu(data, exec.vu.idInTest);
    const response = http.post(
        `${BASE}/api/v1/analytics/query`,
        JSON.stringify({
            dataset: data.dataset,
            dimensions: ['group_aset_id'],
            measures: ['count', 'acquisition_value'],
        }),
        requestParams(actor.session, { tags: { op: 'analytics-mixed-query' } }),
    );

    if (response.status === 200) {
        analyticsLatency.add(response.timings.duration);

        if (__ITER === 0) {
            recordOracleRows(
                oracleValue,
                data,
                actor,
                QUERY_CODE,
                'mixed',
                response.json('rows') || [],
            );
        }
    } else {
        trackFailure(response, true);
    }

    sleep(ANALYTICS_THINK_TIME);
}

export function assetTransaction(data) {
    const vuIndex = exec.vu.idInTest - 1;
    const tenantIndex = vuIndex % data.tenants.length;
    const tenant = data.tenants[tenantIndex];
    const actor = ownerForTenant(data, tenantIndex);
    const assetSlot =
        Math.floor(vuIndex / data.tenants.length) %
        tenant.sample_asset_ids.length;
    const assetId = tenant.sample_asset_ids[assetSlot];
    const moduleId = data.dataset.split('.', 2)[0];
    const url = urlModule(moduleId, `aset/${assetId}`);
    const current = http.get(
        url,
        requestParams(actor.session, { tags: { op: 'asset-read' } }),
    );

    if (current.status !== 200) {
        trackFailure(current);
        sleep(ASSET_THINK_TIME);

        return;
    }

    assetReadLatency.add(current.timings.duration);
    const version = Number(current.json('data.version'));

    if (!Number.isInteger(version)) {
        unexpectedStatuses.add(1, { status: 'missing_version' });
        sleep(ASSET_THINK_TIME);

        return;
    }

    const write = http.patch(
        url,
        JSON.stringify({
            version,
            keterangan: `Load ${RUN_ID} ${vuIndex} ${__ITER}`,
        }),
        requestParams(actor.session, { tags: { op: 'asset-update' } }),
    );

    if (write.status === 200) {
        assetWriteLatency.add(write.timings.duration);
    } else {
        trackFailure(write);
    }

    sleep(ASSET_THINK_TIME);
}

export function handleSummary(data) {
    const value = (name, stat) => data.metrics[name]?.values?.[stat] ?? null;
    const summary = {
        profile: PROFILE,
        run_id: RUN_ID,
        fixture_id: __ENV.ANALYTICS_FIXTURE_ID || null,
        tenants: Number(__ENV.TENANTS || 128),
        analytics_vus: ANALYTICS_VUS,
        asset_vus: ASSET_VUS,
        duration_s: Number(
            ((data.state?.testRunDurationMs ?? 0) / 1000).toFixed(1),
        ),
        application_5xx: value('application_5xx', 'count') || 0,
        gateway_502_504: value('gateway_502_504', 'count') || 0,
        client_timeouts: value('client_timeouts', 'count') || 0,
        analytics_busy: value('analytics_busy', 'count') || 0,
        rate_limited: value('rate_limited', 'count') || 0,
        unexpected_status: value('unexpected_status', 'count') || 0,
        latency_ms: {
            analytics_read: {
                p95: value('mixed_analytics_read', 'p(95)'),
                p99: value('mixed_analytics_read', 'p(99)'),
            },
            asset_read: {
                p95: value('mixed_asset_read', 'p(95)'),
                p99: value('mixed_asset_read', 'p(99)'),
            },
            asset_write: {
                p95: value('mixed_asset_write', 'p(95)'),
                p99: value('mixed_asset_write', 'p(99)'),
            },
        },
    };

    return {
        stdout: `\n===== ANALYTICS MIXED (${PROFILE}) =====\n${JSON.stringify(summary, null, 2)}\n`,
        [`/results/analytics-mixed-${RUN_ID}.json`]: JSON.stringify(
            { summary, metrics: data.metrics },
            null,
            2,
        ),
    };
}
