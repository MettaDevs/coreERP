import assert from 'node:assert/strict';
import { Buffer } from 'node:buffer';
import { readFile } from 'node:fs/promises';
import { test } from 'node:test';
import { ModuleKind, transpileModule } from 'typescript';

async function loadTypescript(name) {
    const source = await readFile(new URL(name, import.meta.url), 'utf8');
    const { outputText } = transpileModule(source, {
        compilerOptions: { module: ModuleKind.ESNext },
    });

    return import(
        `data:text/javascript;base64,${Buffer.from(outputText).toString('base64')}`
    );
}

const { REPORT_FILTER_PROFILES: profiles } = await loadTypescript(
    './reportFilterProfiles.ts',
);
const {
    normalizeReportFilters,
    reservedReportFields,
    showReportControl,
    filterTarget,
    reportControls,
} = await loadTypescript('./reportFilterState.ts');
const depreciation = profiles['laporan-penyusutan-aset'];

test('every profile assigns each parameter once and permits only date range endpoints on the same column', () => {
    for (const [code, profile] of Object.entries(profiles)) {
        const controls = reportControls(profile);
        const keys = controls.map(({ filter }) => filter.key);
        assert.equal(
            new Set(keys).size,
            keys.length,
            `${code}: parameter dobel`,
        );
        const sections = profile.sections.map((section) => section.key);
        assert.equal(
            new Set(sections).size,
            sections.length,
            `${code}: bagian dobel`,
        );

        for (const target of new Set(
            controls.map(({ target }) => target).filter(Boolean),
        )) {
            const owners = controls.filter(
                (control) => control.target === target,
            );

            if (owners.length > 1) {
                assert.deepEqual(
                    owners.map(({ filter }) => filter.kind),
                    ['date', 'date'],
                    `${code}/${target}`,
                );
                assert.deepEqual(
                    owners.map(({ filter }) => filter.key).sort(),
                    ['dari', 'sampai'],
                    `${code}/${target}`,
                );
            }
        }
    }
});

test('FA book reports expose report options and asset filters, without opening the internal book table', () => {
    for (const code of [
        'laporan-nilai-buku-aset',
        'laporan-penyusutan-aset',
        'laporan-proyeksi-penyusutan-aset',
    ]) {
        const profile = profiles[code];
        assert.ok(
            profile.options.some(
                (filter) => filter.kind === 'depreciation-book',
            ),
        );
        assert.deepEqual(
            profile.sections.map((section) => section.dataItem),
            ['aset'],
        );
        assert.ok(
            !profile.options.some((filter) => filter.key === 'jenis_aset_id'),
        );
        assert.ok(
            profile.sections[0].filters.some(
                (filter) => filter.key === 'jenis_aset_id',
            ),
        );
    }
});

test('operational reports have document and line filters without an empty Options section', () => {
    for (const code of [
        'laporan-monitoring-aset',
        'daftar-mutasi-aset',
        'laporan-pemeliharaan-aset',
    ]) {
        const profile = profiles[code];
        assert.deepEqual(profile.options, []);
        assert.ok(
            profile.sections.some((section) =>
                section.filters.some((filter) => filter.key === 'dari'),
            ),
        );
    }

    const procurement = profiles['laporan-pengadaan-aset'];
    assert.equal(
        procurement.options.find((filter) => filter.key === 'status').label,
        'Status pemenuhan',
    );
    assert.deepEqual(
        procurement.sections.map((section) => section.dataItem),
        ['rencana'],
    );
});

test('a restored field filter replaces the fixed filter, with no second hidden constraint', () => {
    const source = {
        jenis_aset_id: ['old'],
        'filters.aset.jenis_aset_id': ['new-a', 'new-b'],
        'filters.aset.kode': 'AST*',
    };
    const { filters, removed } = normalizeReportFilters(depreciation, source);
    assert.deepEqual(filters, {
        'filters.aset.jenis_aset_id': ['new-a', 'new-b'],
        'filters.aset.kode': 'AST*',
    });
    assert.deepEqual(removed, []);
    const section = depreciation.sections[0];
    const control = section.filters.find(
        (filter) => filter.key === 'jenis_aset_id',
    );
    assert.equal(showReportControl(control, filters, section), false);
    assert.ok(
        reservedReportFields(depreciation, filters, 'aset').has(
            'jenis_aset_id',
        ),
    );
});

test('filter normalization keeps independent conditions and is idempotent for preview, print, and presets', () => {
    const source = {
        periode: '2026-10',
        buku_id: 'commercial',
        group_aset_id: ['a', 'b'],
        'filters.aset.kode': '100..200|300',
        'filters.aset.acquisition_value': '>1000000',
    };
    const normalized = normalizeReportFilters(depreciation, source);
    assert.deepEqual(normalized.filters, source);
    assert.deepEqual(
        normalizeReportFilters(depreciation, normalized.filters),
        normalized,
    );
});

test('the old book selection becomes a report option regardless of property order', () => {
    for (const source of [
        { buku_id: 'old', 'filters.buku.buku_id': ['new'] },
        { 'filters.buku.buku_id': ['new'], buku_id: 'old' },
    ]) {
        assert.deepEqual(normalizeReportFilters(depreciation, source), {
            filters: { buku_id: 'new' },
            removed: [],
        });
    }
});

test('filters from undisplayed internal sections cannot invisibly narrow the dataset', () => {
    const { filters, removed } = normalizeReportFilters(depreciation, {
        periode: '2026-10',
        'filters.buku.useful_life_periods': '>12',
        'filters.buku.buku_id': ['a', 'b'],
    });
    assert.deepEqual(filters, { periode: '2026-10' });
    assert.deepEqual(removed, [
        'filters.buku.useful_life_periods',
        'filters.buku.buku_id',
    ]);
});

test('aliases reserve the actual field, including a control displayed in another section', () => {
    const profile = profiles['laporan-pemeliharaan-aset'];
    assert.ok(
        reservedReportFields(profile, {}, 'baris').has('ditugaskan_ke_user_id'),
    );
    assert.ok(reservedReportFields(profile, {}, 'baris').has('aset_id'));
    assert.ok(
        reservedReportFields(profile, {}, 'work_order').has(
            'responsible_org_unit_id',
        ),
    );
    const result = normalizeReportFilters(profile, {
        teknisi_user_id: ['old'],
        'filters.baris.ditugaskan_ke_user_id': ['new'],
    });
    assert.deepEqual(result.filters, {
        'filters.baris.ditugaskan_ke_user_id': ['new'],
    });
});

test('a stored date expression replaces both range endpoints on the same document date', () => {
    const profile = profiles['laporan-monitoring-aset'];
    const { filters } = normalizeReportFilters(profile, {
        dari: '2026-01-01',
        sampai: '2026-12-31',
        'filters.monitoring.tanggal': '01/10/2026..31/10/2026',
    });
    assert.deepEqual(filters, {
        'filters.monitoring.tanggal': '01/10/2026..31/10/2026',
    });

    for (const filter of profile.sections[0].filters.filter((filter) =>
        ['dari', 'sampai'].includes(filter.key),
    )) {
        assert.equal(
            showReportControl(filter, filters, profile.sections[0]),
            false,
        );
    }
});

test('asset codes accept BC expressions without a duplicate saved record picker', () => {
    const section = depreciation.sections[0];
    const picker = section.filters.find((filter) => filter.key === 'asset_id');
    assert.equal(filterTarget(picker, section), 'filters.aset.kode');
    assert.equal(showReportControl(picker, {}, section), false);
    assert.ok(!reservedReportFields(depreciation, {}, 'aset').has('kode'));
    assert.equal(showReportControl(picker, { asset_id: 'old' }, section), true);
    const { filters } = normalizeReportFilters(depreciation, {
        asset_id: 'old',
        'filters.aset.kode': 'AST*',
    });
    assert.deepEqual(filters, { 'filters.aset.kode': 'AST*' });
});
