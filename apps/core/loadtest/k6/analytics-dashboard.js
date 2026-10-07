import { fail } from 'k6';
import { sleep } from 'k6';
import exec from 'k6/execution';
import http from 'k6/http';
import { Counter, Trend } from 'k6/metrics';
import {
    actorForVu,
    ownerForTenant,
    requestParams,
    setupAnalytics,
} from './analytics-common.js';
import { BASE } from './lib.js';

const PROFILE = __ENV.PROFILE || 'saturation';
const VUS = Number(__ENV.VUS || 1000);
const LATENCY_VUS = Number(__ENV.LATENCY_VUS || 8);
const DURATION = __ENV.DURATION || '90s';
const THINK_TIME_SECONDS = Number(__ENV.THINK_TIME_SECONDS || 10);
const RUN_ID = __ENV.RUN_ID || 'local';
const cachedWidgetRead = new Trend('analytics_widget_cached', true);
const refreshedWidgetRead = new Trend('analytics_widget_refresh', true);
const dashboardRead = new Trend('analytics_dashboard_read', true);
const dashboardCycle = new Trend('analytics_dashboard_cycle', true);
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
    analytics_widget_cached: ['p(95)<200', 'p(99)<500'],
    analytics_widget_refresh: ['p(95)<1000', 'p(99)<2000'],
    analytics_dashboard_cycle: ['p(95)<2500', 'p(99)<4000'],
};

export const options = {
    scenarios: {
        dashboard: {
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

function batch(requests) {
    const responses = [];

    for (let index = 0; index < requests.length; index += 64) {
        responses.push(...http.batch(requests.slice(index, index + 64)));
    }

    return responses;
}

function dashboardWidgets(dataset) {
    return [
        {
            title: 'Aset per kelompok',
            type: 'donut',
            query: {
                dataset,
                dimensions: ['group_aset_id'],
                measures: ['count'],
            },
            visual: {
                category: 'group_aset_id',
                value: 'count',
                max_slices: 10,
            },
        },
        {
            title: 'Aset per unit dan mata uang',
            type: 'column',
            query: {
                dataset,
                dimensions: ['responsible_org_unit_id', 'currency_code'],
                measures: ['count'],
            },
            visual: {
                x: 'responsible_org_unit_id',
                series: 'currency_code',
                y: ['count'],
            },
        },
        {
            title: 'Aset per status',
            type: 'bar',
            query: {
                dataset,
                dimensions: ['lifecycle_state'],
                measures: ['count'],
            },
            visual: { x: 'lifecycle_state', y: ['count'] },
        },
        {
            title: 'Nilai perolehan per bulan',
            type: 'line',
            query: {
                dataset,
                dimensions: [
                    { field: 'acquired_on', granularity: 'month' },
                    'currency_code',
                ],
                measures: ['acquisition_value'],
            },
            visual: {
                x: 'acquired_on',
                series: 'currency_code',
                y: ['acquisition_value'],
            },
        },
        {
            title: 'Jumlah aset',
            type: 'kpi',
            query: { dataset, measures: ['count'] },
        },
        {
            title: 'Sepuluh kelompok teratas',
            type: 'table',
            query: {
                dataset,
                dimensions: ['group_aset_id', 'currency_code'],
                measures: ['count', 'acquisition_value'],
                sort: [{ key: 'count', direction: 'desc' }],
                limit: 10,
            },
            visual: {
                columns: [
                    'group_aset_id',
                    'currency_code',
                    'count',
                    'acquisition_value',
                ],
                show_totals: true,
            },
        },
    ];
}

function createDashboards(data) {
    const dashboardResponses = batch(
        data.tenants.map((tenant) => {
            const owner = ownerForTenant(data, tenant.index);

            return [
                'POST',
                `${BASE}/api/v1/analytics/dashboards`,
                JSON.stringify({
                    name: `Analytics ${data.fixtureId} ${RUN_ID.slice(0, 24)} ${tenant.index}`,
                    shared: true,
                }),
                requestParams(owner.session),
            ];
        }),
    );

    const dashboards = dashboardResponses.map((response, index) => {
        if (response.status !== 201) {
            fail(
                `dashboard fixture gagal pada tenant ${index}: ${response.status} ${String(response.body).slice(0, 250)}`,
            );
        }

        return { id: String(response.json('data.id')), widgets: [] };
    });
    const definitions = dashboardWidgets(data.dataset);
    const widgetResponses = batch(
        data.tenants.flatMap((tenant) =>
            definitions.map((widget) => [
                'POST',
                `${BASE}/api/v1/analytics/dashboards/${dashboards[tenant.index].id}/widgets`,
                JSON.stringify(widget),
                requestParams(ownerForTenant(data, tenant.index).session),
            ]),
        ),
    );

    widgetResponses.forEach((response, index) => {
        if (response.status !== 201) {
            fail(
                `widget fixture gagal pada permintaan ${index}: ${response.status} ${String(response.body).slice(0, 300)}`,
            );
        }

        dashboards[Math.floor(index / definitions.length)].widgets.push(
            String(response.json('data.id')),
        );
    });

    return dashboards;
}

export function setup() {
    const data = setupAnalytics();
    data.dashboards = createDashboards(data);

    return data;
}

export default function (data) {
    const actor = actorForVu(data, exec.vu.idInTest);
    const dashboardId = data.dashboards[actor.tenant.index].id;
    const widgetIds = data.dashboards[actor.tenant.index].widgets;
    const cached = __ITER % 10 < 7;
    const cycleStarted = Date.now();
    const dashboard = http.get(
        `${BASE}/api/v1/analytics/dashboards/${dashboardId}`,
        requestParams(actor.session, { tags: { op: 'analytics-dashboard' } }),
    );
    dashboardRead.add(dashboard.timings.duration);

    if (dashboard.status !== 200) {
        if (dashboard.status === 429) {
            busyResponses.add(1);
        } else if (dashboard.status === 502 || dashboard.status === 504) {
            gatewaySaturation.add(1);
        } else if (dashboard.status === 0) {
            clientTimeouts.add(1);
        } else {
            if (dashboard.status >= 500) {
                applicationErrors.add(1);
            }

            unexpectedStatuses.add(1, { status: String(dashboard.status) });
        }
    } else if ((dashboard.json('data.widgets') || []).length !== 6) {
        unexpectedStatuses.add(1, { status: 'dashboard_widget_count' });
    }

    const responses = http.batch(
        widgetIds.map((widgetId, index) => [
            cached ? 'GET' : 'POST',
            `${BASE}/api/v1/analytics/widgets/${widgetId}/${cached ? 'data' : 'refresh'}`,
            null,
            requestParams(actor.session, {
                tags: {
                    op: cached
                        ? 'analytics-widget'
                        : 'analytics-widget-refresh',
                    widget: String(index),
                },
            }),
        ]),
    );

    responses.forEach((response, index) => {
        if (response.status === 200) {
            (cached ? cachedWidgetRead : refreshedWidgetRead).add(
                response.timings.duration,
                { widget: String(index) },
            );

            return;
        }

        if (response.status === 429) {
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
    });
    dashboardCycle.add(Date.now() - cycleStarted);
    sleep(THINK_TIME_SECONDS);
}

export function handleSummary(data) {
    const value = (name, stat) => data.metrics[name]?.values?.[stat] ?? null;
    const summary = {
        profile: PROFILE,
        run_id: RUN_ID,
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
        dashboard_read_ms: {
            p95: value('analytics_dashboard_read', 'p(95)'),
            p99: value('analytics_dashboard_read', 'p(99)'),
        },
        cached_widget_ms: {
            p95: value('analytics_widget_cached', 'p(95)'),
            p99: value('analytics_widget_cached', 'p(99)'),
        },
        refreshed_widget_ms: {
            p95: value('analytics_widget_refresh', 'p(95)'),
            p99: value('analytics_widget_refresh', 'p(99)'),
        },
        dashboard_cycle_ms: {
            p95: value('analytics_dashboard_cycle', 'p(95)'),
            p99: value('analytics_dashboard_cycle', 'p(99)'),
        },
    };

    return {
        stdout: `\n===== ANALYTICS DASHBOARD (${PROFILE}) =====\n${JSON.stringify(summary, null, 2)}\n`,
        [`/results/analytics-dashboard-${RUN_ID}.json`]: JSON.stringify(
            { summary, metrics: data.metrics },
            null,
            2,
        ),
    };
}
