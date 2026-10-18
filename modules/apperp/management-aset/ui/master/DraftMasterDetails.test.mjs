import assert from 'node:assert/strict';
import { fileURLToPath } from 'node:url';
import { createServer } from 'vite';

const server = await createServer({
    root: fileURLToPath(new URL('../../', import.meta.url)),
    configFile: false,
    server: { middlewareMode: true, hmr: false },
    optimizeDeps: { noDiscovery: true },
    appType: 'custom',
});

try {
    const { draftMasterPayload, nextDraftLineNumber } =
        await server.ssrLoadModule('/ui/master/DraftMasterDetails.tsx');
    const row = (values, key = 'local-row-key') => ({ key, values });
    assert.deepEqual(draftMasterPayload('jenis-aset', {}), {});
    const links = draftMasterPayload('jenis-aset', {
        models: [
            row({ reference_id: 'model-a' }),
            row({ reference_id: 'model-b' }),
        ],
        conditions: [],
    });
    assert.deepEqual(links, { models: { model_ids: ['model-a', 'model-b'] } });

    const counter = draftMasterPayload('rencana-pemeliharaan', {
        lines: [
            row({
                dasar: 'nilai_counter',
                interval: 6,
                satuan_interval: 'bulan',
                jenis_counter_id: 'counter-a',
                interval_counter: 100,
                toleransi_counter: 5,
                maintenance_job_type_id: 'job-a',
                tipe_work_order_id: 'type-a',
                aktif: true,
            }),
        ],
    }).lines.lines[0];
    assert.equal(counter.interval, null);
    assert.equal(counter.satuan_interval, null);
    assert.equal(counter.jenis_counter_id, 'counter-a');
    assert.equal(counter.interval_counter, 100);
    const time = draftMasterPayload('rencana-pemeliharaan', {
        lines: [
            row({
                dasar: 'tanggal_mulai',
                interval: '3',
                satuan_interval: 'bulan',
                jenis_counter_id: 'stale-counter',
                interval_counter: 100,
                toleransi_counter: 5,
                maintenance_job_type_id: 'job-a',
                tipe_work_order_id: 'type-a',
                aktif: true,
            }),
        ],
    }).lines.lines[0];
    assert.equal(time.interval, 3);
    assert.equal(time.jenis_counter_id, null);
    assert.equal(time.interval_counter, null);
    assert.equal(time.toleransi_counter, null);

    const checklist = draftMasterPayload('maintenance-checklist-templates', {
        lines: [
            row({
                line_number: 1,
                type: 'text',
                nama: 'Catatan',
                instruksi: 'Isi catatan',
                wajib: true,
                unit_id: 'stale-unit',
                min_value: -10,
                max_value: 20,
                variable_id: 'stale-variable',
                nested_template_id: 'stale-template',
            }),
            row({
                line_number: 4,
                type: 'measurement',
                nama: 'Suhu',
                unit_id: 'unit-temperature',
                min_value: -10,
                max_value: 20,
                wajib: true,
            }),
            row({
                line_number: 7,
                type: 'template',
                nama: 'Pemeriksaan',
                instruksi: 'stale-instruction',
                wajib: true,
                nested_template_id: 'template-a',
                unit_id: 'stale-unit',
            }),
        ],
    }).lines.lines;
    assert.equal(checklist[0].unit_id, null);
    assert.equal(checklist[0].variable_id, null);
    assert.equal(checklist[0].nested_template_id, null);
    assert.equal(checklist[1].unit_id, 'unit-temperature');
    assert.equal(checklist[1].min_value, -10);
    assert.equal(checklist[2].instruksi, null);
    assert.equal(checklist[2].wajib, false);
    assert.equal(checklist[2].nested_template_id, 'template-a');
    assert.deepEqual(
        checklist.map((line) => line.line_number),
        [1, 4, 7],
    );
    assert.equal(
        nextDraftLineNumber([row({ line_number: 1 }), row({ line_number: 3 })]),
        4,
    );
    assert.equal(nextDraftLineNumber([]), 1);
    const values = { values: [row({ nilai: 'Pilihan', urutan: 1 })] };
    assert.deepEqual(
        draftMasterPayload('tipe-atribut', values, { data_type: 'integer' }),
        {},
    );
    assert.equal(
        draftMasterPayload('tipe-atribut', values, { data_type: 'string' })
            .values.rows[0].nilai,
        'Pilihan',
    );
    const model = draftMasterPayload('pabrikan-aset', {
        models: [row({ nama: 'Model baru', kode: '', aktif: true })],
    });
    assert.equal(Object.hasOwn(model.models.rows[0], 'kode'), false);
    assert.equal(JSON.stringify(model).includes('local-row-key'), false);
    console.log(
        'Draft master payload: empty sections, link IDs, conditional fields, counter/time basis, unit references, row numbering, and automatic code omission passed.',
    );
} finally {
    await server.close();
}
