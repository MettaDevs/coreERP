import { fail, sleep } from 'k6';
import exec from 'k6/execution';
import http from 'k6/http';
import { Counter, Trend } from 'k6/metrics';
import {
    ownerForTenant,
    recordOracleRows,
    requestParams,
    setupAnalytics,
} from './analytics-common.js';
import { BASE, RUN_ID } from './lib.js';

const VUS = Number(__ENV.VUS || 200);
const PROFILE = __ENV.PROFILE || 'saturation';
const DURATION = __ENV.DURATION || '90s';
const LATENCY_VUS = Number(__ENV.LATENCY_VUS || 8);
const THINK_TIME_SECONDS = Number(__ENV.THINK_TIME_SECONDS || 2);
const QUERY_CODE = 'asset-register-by-group';
const API = `${BASE}/api/internal/v1/analytics/publications`;

const publicationListRead = new Trend('analytics_external_list', true);
const publicationMetadataRead = new Trend('analytics_external_metadata', true);
const publicationRowsRead = new Trend('analytics_external_rows', true);
const publicationCsvRead = new Trend('analytics_external_csv', true);
const oracleValue = new Trend('analytics_oracle_value');
const applicationErrors = new Counter('application_5xx');
const gatewaySaturation = new Counter('gateway_502_504');
const clientTimeouts = new Counter('client_timeouts');
const rateLimited = new Counter('rate_limited');
const unexpectedStatuses = new Counter('unexpected_status');
const correctnessViolations = new Counter('correctness_violations');
const rowsRead = new Counter('analytics_external_rows_read');
const csvResponses = new Counter('analytics_external_csv_responses');
const cursorRequests = new Counter('analytics_external_cursor_requests');
const csvCursorHeaders = new Counter('analytics_external_csv_cursor_headers');
const crossTenantProbes = new Counter('analytics_external_cross_tenant_probes');
const crossTenantRejections = new Counter(
    'analytics_external_cross_tenant_rejections',
);

const cursors = new Map();
const oracleTraversalComplete = new Set();

const externalCorrectnessThresholds = {
    correctness_violations: ['count==0'],
    analytics_external_cursor_requests: ['count>0'],
    analytics_external_csv_cursor_headers: ['count>0'],
    analytics_external_cross_tenant_probes: ['count>0'],
    analytics_external_cross_tenant_rejections: ['count>0'],
};

export const options = {
    scenarios: {
        analytics_external: {
            executor: 'constant-vus',
            vus: PROFILE === 'latency' ? LATENCY_VUS : VUS,
            duration: DURATION,
            gracefulStop: '30s',
        },
    },
    thresholds:
        PROFILE === 'latency'
            ? {
                  application_5xx: ['count==0'],
                  gateway_502_504: ['count==0'],
                  client_timeouts: ['count==0'],
                  unexpected_status: ['count==0'],
                  analytics_external_rows: ['p(95)<2000', 'p(99)<4000'],
                  analytics_external_csv: ['p(95)<2000', 'p(99)<4000'],
                  ...externalCorrectnessThresholds,
              }
            : {
                  application_5xx: ['count==0'],
                  unexpected_status: ['count==0'],
                  ...externalCorrectnessThresholds,
              },
    summaryTrendStats: ['avg', 'min', 'med', 'p(90)', 'p(95)', 'p(99)', 'max'],
};

function requestsInBatches(requests) {
    const responses = [];

    for (let index = 0; index < requests.length; index += 64) {
        responses.push(...http.batch(requests.slice(index, index + 64)));
    }

    return responses;
}

function mustSucceed(label, responses, expectedStatus) {
    responses.forEach((response, index) => {
        if (response.status !== expectedStatus) {
            fail(
                `${label} gagal untuk tenant ${index}: HTTP ${response.status} ${String(response.body).slice(0, 250)}`,
            );
        }
    });

    return responses;
}

function externalParams(external, tenantId, extra = {}) {
    return {
        headers: {
            Accept: 'application/json',
            Authorization: `Bearer ${external.token}`,
        },
        tags: { tenant_id: tenantId, client_id: external.clientId },
        ...extra,
    };
}

function recordFailure(response) {
    if (response.status === 429) {
        rateLimited.add(1);
    } else if (response.status === 502 || response.status === 504) {
        gatewaySaturation.add(1, { status: String(response.status) });
    } else if (response.status === 0) {
        clientTimeouts.add(1);
    } else {
        if (response.status >= 500) {
            applicationErrors.add(1, { status: String(response.status) });
        }

        unexpectedStatuses.add(1, { status: String(response.status) });
    }
}

function codeForTenant(fixtureId, runId, index) {
    const fixture =
        `${fixtureId}-${runId}`
            .toLowerCase()
            .replace(/[^a-z0-9_-]/g, '-')
            .replace(/^-+/, '')
            .slice(0, 45) || 'run';

    return `load-${fixture}-${index}`;
}

function nameForTenant(fixtureId, index) {
    return `Load ${fixtureId} ${RUN_ID.slice(0, 24)} ${index}`;
}

export function setup() {
    const data = setupAnalytics();
    const owners = data.tenants.map((tenant) =>
        ownerForTenant(data, tenant.index),
    );
    const queries = data.tenants.map(() => ({
        dataset: data.dataset,
        dimensions: ['group_aset_id', 'currency_code'],
        measures: ['count', 'acquisition_value'],
    }));

    const savedQueries = mustSucceed(
        'analisis tersimpan',
        requestsInBatches(
            data.tenants.map((tenant, index) => [
                'POST',
                `${BASE}/api/v1/analytics/saved-queries`,
                JSON.stringify({
                    name: nameForTenant(data.fixtureId, tenant.index),
                    shared: false,
                    query: queries[index],
                }),
                requestParams(owners[index].session, {
                    tags: { op: 'setup-external-query' },
                }),
            ]),
        ),
        201,
    );
    const clients = mustSucceed(
        'klien integrasi',
        requestsInBatches(
            data.tenants.map((tenant, index) => [
                'POST',
                `${BASE}/api/v1/integration-clients`,
                JSON.stringify({
                    name: nameForTenant(data.fixtureId, tenant.index),
                    delivery_mode: 'pull',
                    scopes: ['analytics.read'],
                    allowed_ips: [],
                    posting_type_prefixes: [],
                    push_url: null,
                }),
                requestParams(owners[index].session, {
                    tags: { op: 'setup-external-client' },
                }),
            ]),
        ),
        201,
    );
    const clientTokens = clients.map((response, index) => {
        const token = response.json('token');

        if (typeof token !== 'string' || token.indexOf('.') < 1) {
            fail(`Token klien integrasi tidak tersedia untuk tenant ${index}.`);
        }

        return token;
    });
    const publications = mustSucceed(
        'publikasi analitik',
        requestsInBatches(
            data.tenants.map((tenant, index) => [
                'POST',
                `${BASE}/api/v1/analytics/publications`,
                JSON.stringify({
                    name: nameForTenant(data.fixtureId, tenant.index),
                    code: codeForTenant(data.fixtureId, RUN_ID, tenant.index),
                    saved_query_id: savedQueries[index].json('data.id'),
                    client_ids: [clients[index].json('data.id')],
                    formats: ['json', 'csv'],
                    min_group_size: 5,
                }),
                requestParams(owners[index].session, {
                    tags: { op: 'setup-external-publication' },
                }),
            ]),
        ),
        201,
    );

    data.external = publications.map((response, index) => ({
        tenantIndex: data.tenants[index].index,
        clientId: String(clients[index].json('data.id')),
        token: clientTokens[index],
        publicationCode: String(response.json('data.code')),
    }));
    console.log(
        `analytics external setup: ${data.external.length} clients and publications; bearer tokens stay in k6 setup data.`,
    );

    return data;
}

export default function (data) {
    const vuId = exec.vu.idInTest;
    const external = data.external[(vuId - 1) % data.external.length];
    const tenant = data.tenants[external.tenantIndex];
    const owner = ownerForTenant(data, external.tenantIndex);
    const code = encodeURIComponent(external.publicationCode);
    const auth = externalParams(external, tenant.tenant_id);

    if (__ITER === 0) {
        const list = http.get(API, {
            ...auth,
            tags: { ...auth.tags, op: 'analytics-external-list' },
        });
        publicationListRead.add(list.timings.duration, {
            tenant_id: tenant.tenant_id,
        });

        if (
            list.status === 200 &&
            !(list.json('data') || []).some(
                (item) => item.code === external.publicationCode,
            )
        ) {
            correctnessViolations.add(1, { kind: 'publication_not_listed' });
        } else if (list.status !== 200) {
            recordFailure(list);
        }

        const description = http.get(`${API}/${code}`, {
            ...auth,
            tags: { ...auth.tags, op: 'analytics-external-metadata' },
        });
        publicationMetadataRead.add(description.timings.duration, {
            tenant_id: tenant.tenant_id,
        });

        if (
            description.status === 200 &&
            description.json('data.code') !== external.publicationCode
        ) {
            correctnessViolations.add(1, {
                kind: 'publication_metadata_mismatch',
            });
        } else if (description.status !== 200) {
            recordFailure(description);
        }

        const otherPublication =
            data.external[(external.tenantIndex + 1) % data.external.length];
        const crossTenant = http.get(
            `${API}/${encodeURIComponent(otherPublication.publicationCode)}/rows?limit=1`,
            {
                ...auth,
                tags: { ...auth.tags, op: 'analytics-external-cross-tenant' },
            },
        );
        crossTenantProbes.add(1, { tenant_id: tenant.tenant_id });

        if (crossTenant.status === 404) {
            crossTenantRejections.add(1, { tenant_id: tenant.tenant_id });
        } else {
            correctnessViolations.add(1, {
                kind: 'cross_tenant_publication_status',
                status: String(crossTenant.status),
            });

            if (
                crossTenant.status >= 500 ||
                crossTenant.status === 0 ||
                crossTenant.status === 429
            ) {
                recordFailure(crossTenant);
            } else {
                unexpectedStatuses.add(1, {
                    status: String(crossTenant.status),
                    op: 'cross_tenant_probe',
                });
            }
        }
    }

    const cursor = cursors.get(vuId);
    const limit = 10;
    const params = new URLSearchParams({ limit: String(limit) });

    if (cursor) {
        cursorRequests.add(1, { tenant_id: tenant.tenant_id });
        params.set('cursor', cursor);
    }

    const response = http.get(`${API}/${code}/rows?${params.toString()}`, {
        ...auth,
        tags: { ...auth.tags, op: 'analytics-external-rows' },
    });
    publicationRowsRead.add(response.timings.duration, {
        tenant_id: tenant.tenant_id,
    });

    if (response.status === 200) {
        const page = response.json();
        const rows = page.rows || [];
        rowsRead.add(rows.length, { tenant_id: tenant.tenant_id });

        if (rows.length > limit) {
            correctnessViolations.add(1, {
                kind: 'publication_page_limit_exceeded',
            });
        }

        if (page.meta?.publication !== external.publicationCode) {
            correctnessViolations.add(1, { kind: 'publication_rows_mismatch' });
        }

        const next = page.meta?.next_cursor;

        const hasNext = typeof next === 'string' && next !== '';

        if (hasNext) {
            cursors.set(vuId, next);
        } else {
            cursors.delete(vuId);
        }

        if (!cursor && (rows.length !== limit || !hasNext)) {
            correctnessViolations.add(1, {
                kind: 'publication_pagination_not_exercised',
            });
        }

        if (!oracleTraversalComplete.has(vuId)) {
            recordOracleRows(
                oracleValue,
                data,
                owner,
                QUERY_CODE,
                'external',
                rows,
            );

            if (!hasNext) {
                oracleTraversalComplete.add(vuId);
            }
        }
    } else {
        recordFailure(response);
    }

    if (__ITER % 10 === 5) {
        const csv = http.get(`${API}/${code}/rows?format=csv&limit=10`, {
            ...auth,
            headers: { ...auth.headers, Accept: 'text/csv' },
            tags: { ...auth.tags, op: 'analytics-external-csv' },
        });
        publicationCsvRead.add(csv.timings.duration, {
            tenant_id: tenant.tenant_id,
        });
        const contentType = Object.entries(csv.headers).find(
            ([name]) => name.toLowerCase() === 'content-type',
        )?.[1];
        const nextCursor = Object.entries(csv.headers).find(
            ([name]) => name.toLowerCase() === 'x-next-cursor',
        )?.[1];
        const csvBody = String(csv.body || '').trim();
        const csvLines = csvBody === '' ? [] : csvBody.split(/\r?\n/);
        const csvHeader = csvLines[0] || '';

        if (
            csv.status === 200 &&
            String(contentType || '').startsWith('text/csv') &&
            csvLines.length === limit + 1 &&
            [
                'group_aset_id',
                'currency_code',
                'count',
                'acquisition_value',
            ].every((key) => csvHeader.includes(key)) &&
            typeof nextCursor === 'string' &&
            nextCursor !== ''
        ) {
            csvResponses.add(1, { tenant_id: tenant.tenant_id });
            csvCursorHeaders.add(1, { tenant_id: tenant.tenant_id });
        } else if (csv.status === 200) {
            correctnessViolations.add(1, { kind: 'csv_content_type' });
        } else {
            recordFailure(csv);
        }
    }

    sleep(THINK_TIME_SECONDS);
}

export function handleSummary(data) {
    const value = (name, stat) => data.metrics[name]?.values?.[stat] ?? null;
    const summary = {
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
        rate_limited: value('rate_limited', 'count') || 0,
        unexpected_status: value('unexpected_status', 'count') || 0,
        correctness_violations: value('correctness_violations', 'count') || 0,
        cross_tenant_probes:
            value('analytics_external_cross_tenant_probes', 'count') || 0,
        cross_tenant_rejections:
            value('analytics_external_cross_tenant_rejections', 'count') || 0,
        cursor_requests:
            value('analytics_external_cursor_requests', 'count') || 0,
        csv_cursor_headers:
            value('analytics_external_csv_cursor_headers', 'count') || 0,
        rows_read: value('analytics_external_rows_read', 'count') || 0,
        csv_responses: value('analytics_external_csv_responses', 'count') || 0,
        latency_ms: {
            list: {
                p95: value('analytics_external_list', 'p(95)'),
                p99: value('analytics_external_list', 'p(99)'),
            },
            metadata: {
                p95: value('analytics_external_metadata', 'p(95)'),
                p99: value('analytics_external_metadata', 'p(99)'),
            },
            rows: {
                p95: value('analytics_external_rows', 'p(95)'),
                p99: value('analytics_external_rows', 'p(99)'),
            },
            csv: {
                p95: value('analytics_external_csv', 'p(95)'),
                p99: value('analytics_external_csv', 'p(99)'),
            },
        },
    };
    const result = JSON.stringify(summary, null, 2);

    return {
        stdout: `\n===== ANALYTICS EXTERNAL (${PROFILE}) =====\n${result}\n`,
        [`/results/analytics-external-${RUN_ID}.json`]: result,
    };
}
