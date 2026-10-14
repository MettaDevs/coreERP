// Dua sesi perusahaan tetap per tenant; VU tidak mengganti konteks sesi milik VU lain.
import { check, fail } from 'k6';
import exec from 'k6/execution';
import http from 'k6/http';
import { Counter } from 'k6/metrics';
import {
    BASE,
    FIXTURE,
    RUN_ID,
    siapkanTenant,
    bangunJar,
    paramsUntuk,
    urlModule,
} from '../lib.js';

const TENANTS = Number(__ENV.TENANTS || 128);
const violations = new Counter('company_context_violations');
const serverErrors = new Counter('server_errors');
const probes = new Counter('company_context_probes');
const capacityErrors = new Counter('capacity_errors');
const completed = new Counter('company_context_completed');
const ASET = (path) => urlModule('management-aset', path);
const completionThresholds = {};

for (let index = 0; index < TENANTS; index++) {
    for (const company of ['A', 'B']) {
        completionThresholds[
            `company_context_completed{tenant_index:${index},company:${company}}`
        ] = ['count>0'];
    }
}

export const options = {
    scenarios: {
        companyContext: {
            executor: 'constant-vus',
            vus: Number(__ENV.VUS || 1000),
            duration: __ENV.DURATION || '90s',
            gracefulStop: '45s',
        },
    },
    setupTimeout: '15m',
    // Penyiapan tenant mahal; batasi batch tanpa menurunkan jumlah VU pada beban penuh.
    batch: 8,
    batchPerHost: 4,
    thresholds: {
        company_context_violations: ['count==0'],
        server_errors: ['count==0'],
        company_context_probes: ['count>0'],
        ...completionThresholds,
    },
};

function required(response, statuses, label) {
    if (!statuses.includes(response.status)) {
        fail(
            `setup ${label}: ${response.status} ${String(response.body).slice(0, 300)}`,
        );
    }

    return response;
}

function batch(label, requests, statuses = [200]) {
    return http
        .batch(requests)
        .map((response) => required(response, statuses, label));
}

function planningBody(tenant, companyId) {
    return {
        legal_entity_id: companyId,
        planning_org_unit_id: tenant.orgUnitId,
        planned_on: '2026-01-01',
        planning_year: 2026,
        planning_type: 'regular',
        description: 'company context load test',
        details: [
            {
                jenis_aset_id: tenant.assetTypeId,
                satuan_id: tenant.unitId,
                quantity: 1,
                requested_specification: 'company context',
                estimated_unit_price: 1000,
            },
        ],
    };
}

function activate(tenants) {
    const pages = batch(
        'membership',
        tenants.map((tenant) => [
            'GET',
            `${BASE}/dashboard`,
            null,
            paramsUntuk(tenant, {}, { Accept: 'text/html' }),
        ]),
    );
    // Komponen Blade Inertia saat ini mengirim JSON awal dalam script data-page.
    const memberships = pages.map((response) => {
        const payload = response
            .html()
            .find('script[data-page="app"][type="application/json"]')
            .text();
        const membershipId = JSON.parse(payload).props?.auth?.membership?.id;

        if (!membershipId) {
            fail('setup membership tidak ditemukan pada halaman dashboard');
        }

        return membershipId;
    });
    batch(
        'workspace',
        tenants.map((tenant, index) => [
            'PUT',
            `${BASE}/api/v1/workspace-context`,
            JSON.stringify({
                membership_id: memberships[index],
                legal_entity_id: tenant.legalEntityId,
                org_unit_id: tenant.orgUnitId,
            }),
            paramsUntuk(tenant),
        ]),
    );
}

export function setup() {
    const owners = siapkanTenant(TENANTS, ['management-aset'], 'company').map(
        bangunJar,
    );
    const organizations = batch(
        'organizations',
        owners.map((tenant) => [
            'GET',
            `${BASE}/api/v1/organizations`,
            null,
            paramsUntuk(tenant),
        ]),
    );
    const secondIds = organizations.map(
        (response, index) =>
            (response.json('data') || []).find(
                (row) => row.legal_entity?.company_code === `CCB${index}`,
            )?.id,
    );
    const missing = owners
        .map((tenant, index) => ({ tenant, index }))
        .filter(({ index }) => !secondIds[index]);
    const created = batch(
        'company B',
        missing.map(({ tenant, index }) => [
            'POST',
            `${BASE}/api/v1/organizations`,
            JSON.stringify({
                classification: 'legal_entity',
                name: `ZZ Company B ${index}`,
                company_code: `CCB${index}`,
                country_code: 'ID',
            }),
            paramsUntuk(tenant),
        ]),
        [201],
    );
    missing.forEach(({ index }, position) => {
        secondIds[index] = created[position].json('data.id');
    });

    const types = batch(
        'shared asset type',
        owners.map((tenant, index) => [
            'POST',
            ASET('jenis-aset'),
            JSON.stringify({ nama: `Company probe ${index}` }),
            paramsUntuk(
                tenant,
                {},
                { 'Idempotency-Key': `cp-${FIXTURE}-${index}-master` },
            ),
        ]),
        [200, 201],
    );
    const units = batch(
        'units',
        owners.map((tenant) => [
            'GET',
            ASET('reference-data/units-of-measure'),
            null,
            paramsUntuk(tenant),
        ]),
    );
    const companyA = owners.map((tenant, index) => ({
        ...tenant,
        assetTypeId: types[index].json('data.id'),
        unitId: units[index].json('data.0.id'),
    }));
    activate(companyA);
    const docsA = batch(
        'planning A',
        companyA.map((tenant, index) => [
            'POST',
            ASET('perencanaan-aset'),
            JSON.stringify(planningBody(tenant, tenant.legalEntityId)),
            paramsUntuk(
                tenant,
                {},
                { 'Idempotency-Key': `cp-${FIXTURE}-${index}-A` },
            ),
        ]),
        [200, 201],
    );

    // Login kedua memakai identity sama, tetapi session id berbeda, lalu menetapkan perusahaan B.
    const companyB = siapkanTenant(TENANTS, ['management-aset'], 'company').map(
        (tenant, index) =>
            bangunJar({
                ...tenant,
                legalEntityId: String(secondIds[index]),
                assetTypeId: companyA[index].assetTypeId,
                unitId: companyA[index].unitId,
            }),
    );
    activate(companyB);
    const docsB = batch(
        'planning B',
        companyB.map((tenant, index) => [
            'POST',
            ASET('perencanaan-aset'),
            JSON.stringify(planningBody(tenant, tenant.legalEntityId)),
            paramsUntuk(
                tenant,
                {},
                { 'Idempotency-Key': `cp-${FIXTURE}-${index}-B` },
            ),
        ]),
        [200, 201],
    );

    return companyA.map((tenant, index) => ({
        a: ownersToData(tenant),
        b: ownersToData(companyB[index]),
        aId: docsA[index].json('data.id'),
        bId: docsB[index].json('data.id'),
        aVersion: docsA[index].json('data.version'),
        bVersion: docsB[index].json('data.version'),
    }));
}

function ownersToData(tenant) {
    const data = { ...tenant };
    delete data.jar;

    return data;
}

function expect(response, status, label, predicate = () => true) {
    if (
        response.status === 0 ||
        response.status === 502 ||
        response.status === 504
    ) {
        capacityErrors.add(1);

        return false;
    }

    if (response.status >= 500) {
        serverErrors.add(1);
    }

    if (
        !check(response, {
            [label]: (result) => result.status === status && predicate(result),
        })
    ) {
        violations.add(1, { label, status: response.status });

        return false;
    }

    return true;
}

let cached;
let iteration = 0;
export default function (data) {
    if (!cached) {
        const fixture = data[(exec.vu.idInTest - 1) % data.length];
        // Gelombang pertama sudah mencakup kedua perusahaan setiap tenant.
        iteration = Math.floor((exec.vu.idInTest - 1) / data.length) % 2;
        cached = {
            ...fixture,
            a: bangunJar(fixture.a),
            b: bangunJar(fixture.b),
        };
    }

    const current = cached;
    const own = iteration++ % 2 === 0 ? current.a : current.b;
    const other = own === current.a ? current.b : current.a;
    const ownId = own === current.a ? current.aId : current.bId;
    const otherId = own === current.a ? current.bId : current.aId;
    const otherVersion =
        own === current.a ? current.bVersion : current.aVersion;
    probes.add(1);
    const responses = http.batch([
        ['GET', `${ASET('perencanaan-aset')}/${ownId}`, null, paramsUntuk(own)],
        [
            'GET',
            `${ASET('perencanaan-aset')}/${otherId}`,
            null,
            paramsUntuk(own, { responseCallback: http.expectedStatuses(404) }),
        ],
        [
            'GET',
            `${ASET('perencanaan-aset')}?per_page=100`,
            null,
            paramsUntuk(own),
        ],
        [
            'GET',
            `${ASET('jenis-aset')}/${own.assetTypeId}`,
            null,
            paramsUntuk(own),
        ],
        [
            'PATCH',
            `${ASET('perencanaan-aset')}/${otherId}`,
            JSON.stringify({
                ...planningBody(own, other.legalEntityId),
                version: otherVersion,
            }),
            paramsUntuk(own, { responseCallback: http.expectedStatuses(404) }),
        ],
        [
            'POST',
            ASET('perencanaan-aset'),
            JSON.stringify(planningBody(own, other.legalEntityId)),
            paramsUntuk(
                own,
                { responseCallback: http.expectedStatuses(422) },
                {
                    'Idempotency-Key': `cp-${FIXTURE}-reject-${RUN_ID}-${exec.vu.idInTest}-${exec.scenario.iterationInTest}`,
                },
            ),
        ],
    ]);
    const outcomes = [
        expect(
            responses[0],
            200,
            'own company visible',
            (response) =>
                response.json('data.legal_entity_id') === own.legalEntityId,
        ),
        expect(responses[1], 404, 'other company hidden'),
        expect(
            responses[2],
            200,
            'list limited to active company',
            (response) => {
                const rows = response.json('data') || [];

                return (
                    rows.some((row) => row.id === ownId) &&
                    rows.every(
                        (row) => row.legal_entity_id === own.legalEntityId,
                    )
                );
            },
        ),
        expect(
            responses[3],
            200,
            'shared master visible',
            (response) => response.json('data.id') === own.assetTypeId,
        ),
        expect(responses[4], 404, 'other company update rejected'),
        expect(responses[5], 422, 'other company create rejected'),
    ];

    if (outcomes.every(Boolean)) {
        completed.add(1, {
            tenant_index: own.index,
            company: own === current.a ? 'A' : 'B',
        });
    }
}

export function handleSummary(data) {
    return {
        stdout: JSON.stringify(
            {
                run_id: RUN_ID,
                probes: data.metrics.company_context_probes?.values?.count ?? 0,
                completed:
                    data.metrics.company_context_completed?.values?.count ?? 0,
                violations:
                    data.metrics.company_context_violations?.values?.count ?? 0,
                server_errors: data.metrics.server_errors?.values?.count ?? 0,
                capacity_errors:
                    data.metrics.capacity_errors?.values?.count ?? 0,
                failed_thresholds: Object.entries(data.metrics)
                    .filter(([, metric]) =>
                        Object.values(metric.thresholds || {}).some(
                            (threshold) => !threshold.ok,
                        ),
                    )
                    .map(([name]) => name),
            },
            null,
            2,
        ),
        [`/results/summary-company-context-${RUN_ID}.json`]: JSON.stringify(
            data,
            null,
            2,
        ),
    };
}
