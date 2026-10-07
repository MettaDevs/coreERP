import { sleep } from 'k6';
import exec from 'k6/execution';
import http from 'k6/http';
import { Counter, Trend } from 'k6/metrics';
import {
    actorForVu,
    recordOracleRows,
    requestParams,
    setupAnalytics,
} from './analytics-common.js';
import { BASE } from './lib.js';

const PROFILE = __ENV.PROFILE || 'saturation';
const VUS = Number(__ENV.VUS || 300);
const LATENCY_VUS = Number(__ENV.LATENCY_VUS || 8);
const DURATION = __ENV.DURATION || '90s';
const THINK_TIME_SECONDS = Number(__ENV.THINK_TIME_SECONDS || 2);
const QUERY_CODE = 'asset-register-by-group';
const EXPLORE_DIMENSIONS = [
    'group_aset_id',
    'lifecycle_state',
    'responsible_org_unit_id',
    'financial_dimension_org_unit_id',
    'acquired_on',
];
const EXPLORE_LIMITS = [10, 50, 100, 500];

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
    thresholds:
        PROFILE === 'latency'
            ? latencyThresholds
            : {
                  application_5xx: ['count==0'],
                  unexpected_status: ['count==0'],
              },
    summaryTrendStats: ['avg', 'min', 'med', 'p(90)', 'p(95)', 'p(99)', 'max'],
};

export function setup() {
    return setupAnalytics();
}

function queryFor(data, vuId, iteration) {
    if (iteration === 0) {
        return {
            dataset: data.dataset,
            dimensions: ['group_aset_id'],
            measures: ['count', 'acquisition_value'],
        };
    }

    const shape = (vuId - 1 + iteration) % 40;
    const dimension = EXPLORE_DIMENSIONS[shape % EXPLORE_DIMENSIONS.length];
    const measures =
        Math.floor(shape / EXPLORE_DIMENSIONS.length) % 2 === 0
            ? ['count']
            : ['count', 'acquisition_value'];
    const limit = EXPLORE_LIMITS[Math.floor(shape / 10)];

    return { dataset: data.dataset, dimensions: [dimension], measures, limit };
}

export default function (data) {
    const actor = actorForVu(data, exec.vu.idInTest);
    const response = http.post(
        `${BASE}/api/v1/analytics/query`,
        JSON.stringify(queryFor(data, exec.vu.idInTest, __ITER)),
        requestParams(actor.session, { tags: { op: QUERY_CODE } }),
    );

    if (response.status === 200) {
        queryLatency.add(response.timings.duration);

        if (__ITER === 0) {
            recordOracleRows(
                oracleValue,
                data,
                actor,
                QUERY_CODE,
                'explore',
                response.json('rows') || [],
            );
        }
    } else if (response.status === 429) {
        busyResponses.add(1);
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

    sleep(THINK_TIME_SECONDS);
}

export function handleSummary(data) {
    const value = (name, stat) => data.metrics[name]?.values?.[stat] ?? null;
    const summary = {
        profile: PROFILE,
        run_id: __ENV.RUN_ID || 'local',
        fixture_id: __ENV.ANALYTICS_FIXTURE_ID || null,
        tenants: Number(__ENV.TENANTS || 128),
        vus: PROFILE === 'latency' ? LATENCY_VUS : VUS,
        duration_s: Number(
            ((data.state?.testRunDurationMs ?? 0) / 1000).toFixed(1),
        ),
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
            .filter(
                ([, metric]) =>
                    metric.thresholds &&
                    Object.values(metric.thresholds).some(
                        (threshold) => !threshold.ok,
                    ),
            )
            .map(([name]) => name),
    };

    return {
        stdout: `\n===== ANALYTICS EXPLORE (${PROFILE}) =====\n${JSON.stringify(summary, null, 2)}\n`,
        [`/results/analytics-summary-${__ENV.RUN_ID || 'local'}.json`]:
            JSON.stringify({ summary, metrics: data.metrics }, null, 2),
    };
}
