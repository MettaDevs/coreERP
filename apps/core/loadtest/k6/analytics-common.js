import { fail } from 'k6';
import http from 'k6/http';
import { BASE, jsonHeaders } from './lib.js';

const PASSWORD = __ENV.LOADTEST_PASSWORD || 'Loadtest-Owner-2026!';
const fixturePath = '/results/analytics-fixture.json';
const fixture = (() => {
    try {
        return JSON.parse(open(fixturePath));
    } catch (error) {
        fail(
            `Fixture analitik tidak terbaca. Siapkan stack dengan LOADTEST_ANALYTICS=1: ${String(error)}`,
        );
    }
})();
const jarCache = new Map();

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
        Object.entries(jar.cookiesForURL(BASE)).map(([name, values]) => [
            name,
            values[0],
        ]),
    );
}

function loginSessions(fixture) {
    const loginRows = fixture.tenants.flatMap((tenant) =>
        Object.entries(tenant.users).map(([kind, user]) => ({
            tenant,
            kind,
            user,
            jar: new http.CookieJar(),
        })),
    );
    const forms = batch(
        loginRows.map(({ jar }) => ['GET', `${BASE}/login`, null, { jar }]),
    );

    forms.forEach((response, index) => {
        if (response.status !== 200 || !csrfFrom(loginRows[index].jar)) {
            fail(
                `form login fixture gagal untuk ${loginRows[index].user.email}: ${response.status}`,
            );
        }
    });

    const logins = batch(
        loginRows.map(({ user, jar }) => [
            'POST',
            `${BASE}/login`,
            JSON.stringify({ email: user.email, password: PASSWORD }),
            { jar, headers: jsonHeaders(csrfFrom(jar)) },
        ]),
    );
    const sessions = fixture.tenants.map(() => ({}));

    logins.forEach((response, index) => {
        const row = loginRows[index];

        if (response.status !== 200) {
            fail(
                `login fixture gagal untuk ${row.user.email}: ${response.status} ${String(response.body).slice(0, 250)}`,
            );
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

export function setupAnalytics() {
    if (fixture.tenants.length < 100) {
        fail(
            `Fixture hanya berisi ${fixture.tenants.length} tenant; gate meminta sedikitnya 100.`,
        );
    }

    return {
        fixtureId: fixture.fixture_id,
        dataset: fixture.dataset || __ENV.ANALYTICS_DATASET,
        tenants: fixture.tenants,
        sessions: loginSessions(fixture),
    };
}

export function actorForTenant(data, tenantIndex, kind) {
    const tenant = data.tenants[tenantIndex];
    const session = data.sessions[tenantIndex][kind];

    return {
        tenant,
        kind,
        session,
        accessKind:
            kind === 'owner'
                ? 'all'
                : kind === 'two_units'
                  ? 'two_units'
                  : 'none',
    };
}

export function actorForVu(data, vuId) {
    const vuIndex = vuId - 1;
    const tenantIndex = vuIndex % data.tenants.length;
    const kind = ['owner', 'two_units', 'no_grant'][
        Math.floor(vuIndex / data.tenants.length) % 3
    ];

    return actorForTenant(data, tenantIndex, kind);
}

export function ownerForTenant(data, tenantIndex) {
    return actorForTenant(data, tenantIndex, 'owner');
}

export function requestParams(session, extra = {}) {
    if (!jarCache.has(session.email)) {
        const jar = new http.CookieJar();
        Object.entries(session.cookies).forEach(([name, value]) =>
            jar.set(BASE, name, value),
        );
        jarCache.set(session.email, jar);
    }

    return {
        jar: jarCache.get(session.email),
        headers: jsonHeaders(session.csrf),
        ...extra,
    };
}

export function recordOracleRows(
    metric,
    data,
    actor,
    queryCode,
    scenario,
    rows,
) {
    const baseTags = {
        fixture_id: data.fixtureId,
        run_id: __ENV.RUN_ID || 'local',
        scenario,
        query_code: queryCode,
        tenant_id: actor.session.tenantId,
        user_email: actor.session.email,
        access_kind: actor.accessKind,
    };

    if (rows.length === 0) {
        metric.add(0, {
            ...baseTags,
            metric: 'empty',
            group_key: '',
            currency_code: '',
        });

        return;
    }

    rows.forEach((row) => {
        const tags = {
            ...baseTags,
            group_key: String(row.group_aset_id || ''),
            currency_code: String(row.currency_code || ''),
        };

        metric.add(Number(row.count), { ...tags, metric: 'count' });
        metric.add(Number(row.acquisition_value), {
            ...tags,
            metric: 'acquisition_value',
        });
    });
}
