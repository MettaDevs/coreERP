import { Trash2 } from 'lucide-react';
import { useRef } from 'react';
import type { RefObject } from 'react';
import { ActionButton } from '@apperp/ui/action-button';
import {
    CollapsibleSection,
    CollapsibleSectionGroup,
} from '@apperp/ui/collapsible-section';
import { DataTable } from '@apperp/ui/data-table';
import { FieldError } from '@apperp/ui/field';
import type { ApiValidationErrors } from '../api';
import { newIdempotencyKey } from '../api';
import DynamicField from './DynamicField';
import type { FieldConfig, FieldValue } from './fields';
import { emptyValue, isVisible, payloadValue } from './fields';
import GroupBookMatrix from './GroupBookMatrix';
import type { GroupBookRow } from './GroupBookMatrix';
import type { MasterResource, Permission } from './masters';
import { useNumberPolicy } from './useNumberPolicy';

export type DraftSectionKey =
    | 'models'
    | 'attributes'
    | 'conditions'
    | 'counters'
    | 'job_types'
    | 'books'
    | 'values'
    | 'variants'
    | 'asset_types'
    | 'lines'
    | 'targets';
type DraftField = FieldConfig & { persist?: boolean };
type Row = { key: string; values: Record<string, FieldValue> };
export type DraftMasterDetailsValue = Partial<Record<DraftSectionKey, Row[]>>;
export type MasterDetailsPayload = Partial<
    Record<DraftSectionKey, Record<string, unknown>>
>;
type Section = {
    key: DraftSectionKey;
    title: string;
    fields: DraftField[];
    defaults?: Record<string, FieldValue>;
} & (
    | { kind: 'rows'; payloadKey: 'rows' | 'lines' | 'values' | 'targets' }
    | { kind: 'links'; payloadKey: string }
);
const reference = (
    name: string,
    label: string,
    resource: string,
    required = true,
): DraftField => ({ name, label, resource, required, type: 'reference' });
const text = (name: string, label: string, required = false): DraftField => ({
    name,
    label,
    required,
    type: 'text',
});
const number = (name: string, label: string, required = false): DraftField => ({
    name,
    label,
    required,
    type: 'number',
    min: 0,
});
const optionLabels: Record<string, string> = {
    none: 'Tanpa penyesuaian',
    full_month: 'Bulan penuh',
    half_year: 'Setengah tahun',
    mid_quarter: 'Tengah kuartal',
    mid_month_1st: 'Tengah bulan (awal)',
    mid_month_15th: 'Tengah bulan (tanggal 15)',
    half_year_start_of_year: 'Setengah tahun (awal tahun)',
    half_year_next_year: 'Setengah tahun (tahun berikutnya)',
    pass: 'Lulus',
    fail: 'Tidak lulus',
    header: 'Judul',
    text: 'Teks',
    measurement: 'Pengukuran',
    variable: 'Variabel',
    template: 'Template',
    tanggal_mulai: 'Tanggal mulai',
    work_order_terakhir: 'Work order terakhir',
    nilai_counter: 'Nilai counter',
    hari: 'Hari',
    minggu: 'Minggu',
    bulan: 'Bulan',
    tahun: 'Tahun',
    type: 'Jenis aset',
    asset: 'Aset',
};
const choice = (
    name: string,
    label: string,
    options: string[],
): DraftField => ({
    name,
    label,
    type: 'select',
    options: options.map((value) => ({
        value,
        label:
            name === 'result_code' && value === 'none'
                ? 'Tanpa hasil'
                : (optionLabels[value] ?? value),
    })),
});
const active: DraftField = { name: 'aktif', label: 'Aktif', type: 'boolean' };
const modelFields = [
    text('kode', 'Kode'),
    text('nama', 'Nama', true),
    text('keterangan', 'Keterangan'),
    active,
];
const link = (
    key: DraftSectionKey,
    title: string,
    payloadKey: string,
    resource: string,
): Section => ({
    kind: 'links',
    key,
    title,
    payloadKey,
    fields: [reference('reference_id', title, resource)],
});
const sections: Partial<Record<MasterResource, Section[]>> = {
    'pabrikan-aset': [
        {
            kind: 'rows',
            key: 'models',
            title: 'Model',
            payloadKey: 'rows',
            fields: [
                ...modelFields,
                reference('jenis_aset_id', 'Jenis aset', 'jenis-aset', false),
            ],
        },
    ],
    'jenis-aset': [
        {
            kind: 'rows',
            key: 'attributes',
            title: 'Atribut aset',
            payloadKey: 'rows',
            fields: [
                reference('tipe_atribut_id', 'Tipe atribut', 'tipe-atribut'),
                { name: 'wajib', label: 'Wajib', type: 'boolean' },
                number('urutan', 'Urutan'),
            ],
        },
        link('models', 'Model', 'model_ids', 'model-aset'),
        link('conditions', 'Kondisi aset', 'condition_ids', 'kondisi-aset'),
        link('counters', 'Counter aset', 'jenis_counter_ids', 'jenis-counter'),
        link(
            'job_types',
            'Jenis pekerjaan maintenance',
            'jenis_aset_ids',
            'maintenance-job-types',
        ),
    ],
    'group-aset': [
        {
            kind: 'rows',
            key: 'books',
            title: 'Buku dan metode penyusutan',
            payloadKey: 'rows',
            defaults: { depreciate: true, convention: 'none' },
            fields: [
                reference('buku_id', 'Buku', 'buku-penyusutan'),
                reference(
                    'depreciation_profile_id',
                    'Profil penyusutan',
                    'profil-penyusutan',
                    false,
                ),
                reference(
                    'alternative_profile_id',
                    'Profil alternatif',
                    'profil-penyusutan',
                    false,
                ),
                number('useful_life_periods', 'Masa manfaat (periode)'),
                choice('convention', 'Konvensi', [
                    'none',
                    'full_month',
                    'half_year',
                    'mid_quarter',
                    'mid_month_1st',
                    'mid_month_15th',
                    'half_year_start_of_year',
                    'half_year_next_year',
                ]),
                { name: 'depreciate', label: 'Disusutkan', type: 'boolean' },
                number('round_off_depreciation', 'Pembulatan penyusutan'),
            ],
        },
    ],
    'tipe-atribut': [
        {
            kind: 'rows',
            key: 'values',
            title: 'Pilihan nilai',
            payloadKey: 'rows',
            fields: [text('nilai', 'Nilai', true), number('urutan', 'Urutan')],
        },
    ],
    'maintenance-job-types': [
        {
            kind: 'rows',
            key: 'variants',
            title: 'Varian pekerjaan',
            payloadKey: 'rows',
            fields: modelFields,
        },
        link('asset_types', 'Jenis aset', 'jenis_aset_ids', 'jenis-aset'),
    ],
    'maintenance-checklist-variables': [
        {
            kind: 'rows',
            key: 'values',
            title: 'Nilai checklist',
            payloadKey: 'values',
            defaults: { result_code: 'none' },
            fields: [
                number('line_number', 'Baris', true),
                text('value', 'Nilai', true),
                choice('result_code', 'Hasil', ['pass', 'fail', 'none']),
            ],
        },
    ],
    'maintenance-checklist-templates': [
        {
            kind: 'rows',
            key: 'lines',
            title: 'Baris checklist',
            payloadKey: 'lines',
            defaults: { type: 'text' },
            fields: [
                number('line_number', 'Baris', true),
                choice('type', 'Jenis', [
                    'header',
                    'text',
                    'measurement',
                    'variable',
                    'template',
                ]),
                text('nama', 'Nama', true),
                text('instruksi', 'Petunjuk'),
                { name: 'wajib', label: 'Wajib', type: 'boolean' },
                {
                    ...reference(
                        'unit_id',
                        'Satuan',
                        'reference-data/units-of-measure',
                        false,
                    ),
                    visibleWhen: (values) => values.type === 'measurement',
                },
                {
                    ...{
                        ...number('min_value', 'Nilai minimum'),
                        min: undefined,
                    },
                    visibleWhen: (values) => values.type === 'measurement',
                },
                {
                    ...{
                        ...number('max_value', 'Nilai maksimum'),
                        min: undefined,
                    },
                    visibleWhen: (values) => values.type === 'measurement',
                },
                {
                    ...reference(
                        'variable_id',
                        'Variabel checklist',
                        'maintenance-checklist-variables',
                    ),
                    visibleWhen: (values) => values.type === 'variable',
                },
                {
                    ...reference(
                        'nested_template_id',
                        'Template checklist',
                        'maintenance-checklist-templates',
                    ),
                    visibleWhen: (values) => values.type === 'template',
                },
            ],
        },
    ],
    'rencana-pemeliharaan': [
        {
            kind: 'rows',
            key: 'lines',
            title: 'Baris rencana',
            payloadKey: 'lines',
            defaults: {
                dasar: 'tanggal_mulai',
                satuan_interval: 'bulan',
                interval: 1,
            },
            fields: [
                choice('dasar', 'Dasar', [
                    'tanggal_mulai',
                    'work_order_terakhir',
                    'nilai_counter',
                ]),
                {
                    ...number('interval', 'Interval'),
                    visibleWhen: (values) => values.dasar !== 'nilai_counter',
                    required: true,
                },
                {
                    ...choice('satuan_interval', 'Satuan interval', [
                        'hari',
                        'minggu',
                        'bulan',
                        'tahun',
                    ]),
                    visibleWhen: (values) => values.dasar !== 'nilai_counter',
                    required: true,
                },
                {
                    ...reference(
                        'jenis_counter_id',
                        'Counter',
                        'jenis-counter',
                        false,
                    ),
                    visibleWhen: (values) => values.dasar === 'nilai_counter',
                    required: true,
                },
                {
                    ...number('interval_counter', 'Interval counter'),
                    visibleWhen: (values) => values.dasar === 'nilai_counter',
                    required: true,
                },
                {
                    ...number('toleransi_counter', 'Toleransi counter'),
                    visibleWhen: (values) => values.dasar === 'nilai_counter',
                    required: false,
                },
                reference(
                    'maintenance_job_type_id',
                    'Jenis pekerjaan',
                    'maintenance-job-types',
                ),
                reference(
                    'variant_id',
                    'Varian',
                    'maintenance-job-type-variants',
                    false,
                ),
                reference('trade_id', 'Keahlian', 'trade', false),
                reference(
                    'tipe_work_order_id',
                    'Tipe work order',
                    'tipe-work-order',
                ),
                reference(
                    'tingkat_layanan_id',
                    'Tingkat layanan',
                    'tingkat-layanan',
                    false,
                ),
                number('selesai_dalam_hari', 'Selesai dalam (hari)'),
                text('deskripsi', 'Keterangan'),
                active,
            ],
        },
        {
            kind: 'rows',
            key: 'targets',
            title: 'Berlaku untuk',
            payloadKey: 'targets',
            defaults: { kind: 'type' },
            fields: [
                {
                    ...choice('kind', 'Pilih berdasarkan', ['type', 'asset']),
                    persist: false,
                },
                {
                    ...reference('aset_id', 'Aset', 'aset'),
                    visibleWhen: (values) => values.kind === 'asset',
                },
                {
                    ...reference('jenis_aset_id', 'Jenis aset', 'jenis-aset'),
                    visibleWhen: (values) => values.kind !== 'asset',
                },
                { name: 'tanggal_mulai', label: 'Tanggal mulai', type: 'date' },
                active,
            ],
        },
    ],
};

export function nextDraftLineNumber(
    rows: readonly { values: Record<string, FieldValue> }[],
) {
    return (
        rows.reduce(
            (maximum, row) =>
                Math.max(maximum, Number(row.values.line_number) || 0),
            0,
        ) + 1
    );
}

export function draftSectionsFor(
    resource: MasterResource,
    parentValues: Record<string, FieldValue> = {},
) {
    if (resource === 'tipe-atribut' && parentValues.data_type !== 'string') {
        return [];
    }

    return sections[resource] ?? [];
}

function changeDraftValues(
    resource: MasterResource,
    values: Record<string, FieldValue>,
    name: string,
    next: FieldValue,
) {
    const result = { ...values, [name]: next };

    if (name === 'maintenance_job_type_id') {
        result.variant_id = '';
    }

    if (resource === 'rencana-pemeliharaan' && name === 'dasar') {
        if (next === 'nilai_counter') {
            result.interval = null;
            result.satuan_interval = null;
        } else {
            result.jenis_counter_id = null;
            result.interval_counter = null;
            result.toleransi_counter = null;
            result.interval = values.interval || 1;
            result.satuan_interval = values.satuan_interval || 'bulan';
        }
    }

    if (resource === 'maintenance-checklist-templates' && name === 'type') {
        if (next === 'header' || next === 'template') {
            result.instruksi = null;
            result.wajib = false;
        }

        if (next !== 'measurement') {
            result.unit_id = null;
            result.min_value = null;
            result.max_value = null;
        }

        if (next !== 'variable') {
            result.variable_id = null;
        }

        if (next !== 'template') {
            result.nested_template_id = null;
        }
    }

    return result;
}

function draftRowPayload(resource: MasterResource, section: Section, row: Row) {
    const result: Record<string, unknown> = Object.fromEntries(
        section.fields
            .filter(
                (field) =>
                    field.persist !== false &&
                    isVisible(field, row.values) &&
                    !(field.name === 'kode' && !row.values.kode),
            )
            .map((field) => [
                field.name,
                row.values[field.name] === ''
                    ? null
                    : payloadValue(field, row.values[field.name]),
            ]),
    );

    if (resource === 'rencana-pemeliharaan' && section.key === 'lines') {
        const counter = row.values.dasar === 'nilai_counter';
        result.interval = counter ? null : Number(row.values.interval);
        result.satuan_interval = counter ? null : row.values.satuan_interval;

        for (const key of [
            'jenis_counter_id',
            'interval_counter',
            'toleransi_counter',
        ]) {
            result[key] = counter ? row.values[key] || null : null;
        }
    }

    if (
        resource === 'maintenance-checklist-templates' &&
        section.key === 'lines'
    ) {
        const type = row.values.type;

        if (type === 'header' || type === 'template') {
            result.instruksi = null;
            result.wajib = false;
        }

        for (const key of ['unit_id', 'min_value', 'max_value']) {
            if (type !== 'measurement') {
                result[key] = null;
            }
        }

        if (type !== 'variable') {
            result.variable_id = null;
        }

        if (type !== 'template') {
            result.nested_template_id = null;
        }
    }

    return result;
}

export function draftMasterPayload(
    resource: MasterResource,
    value: DraftMasterDetailsValue,
    parentValues: Record<string, FieldValue> = {},
) {
    return Object.fromEntries(
        draftSectionsFor(resource, parentValues)
            .filter((section) => value[section.key]?.length)
            .map((section) => {
                const rows = value[section.key] ?? [];

                return [
                    section.key,
                    {
                        [section.payloadKey]:
                            section.kind === 'links'
                                ? rows.map((row) => row.values.reference_id)
                                : rows.map((row) =>
                                      draftRowPayload(resource, section, row),
                                  ),
                    },
                ];
            }),
    );
}

export default function DraftMasterDetails({
    resource,
    value,
    onChange,
    sectionKeys,
    parentValues = {},
    errors = {},
    portalContainer,
    canEdit = true,
    permissions,
}: {
    resource: MasterResource;
    canEdit?: boolean;
    permissions: readonly Permission[];
    value: DraftMasterDetailsValue;
    onChange: (value: DraftMasterDetailsValue) => void;
    sectionKeys?: DraftSectionKey[];
    parentValues?: Record<string, FieldValue>;
    errors?: ApiValidationErrors;
    portalContainer?: RefObject<HTMLDivElement | null>;
}) {
    const localPortal = useRef<HTMLDivElement>(null);
    const held = new Set<string>(permissions);
    const allowed = (section: Section) => {
        if (resource === 'pabrikan-aset' && section.key === 'models') {
            return held.has('management-aset.model-aset.create');
        }

        if (
            resource === 'maintenance-job-types' &&
            section.key === 'variants'
        ) {
            return held.has(
                'management-aset.maintenance-job-type-variants.create',
            );
        }

        if (resource === 'jenis-aset' && section.key === 'models') {
            return held.has('management-aset.model-aset.read');
        }

        if (resource === 'jenis-aset' && section.key === 'conditions') {
            return held.has('management-aset.kondisi-aset.read');
        }

        return true;
    };
    const childResource =
        resource === 'pabrikan-aset'
            ? 'model-aset'
            : resource === 'maintenance-job-types'
              ? 'maintenance-job-type-variants'
              : null;
    const childRows =
        resource === 'pabrikan-aset' ? value.models : value.variants;
    const childPolicy = useNumberPolicy(
        childResource ?? '',
        canEdit && childResource !== null && Boolean(childRows?.length),
    );

    if (!canEdit) {
        return null;
    }

    return (
        <div
            ref={(node) => {
                localPortal.current =
                    node?.closest<HTMLDivElement>(
                        '[data-slot="page-dialog-contents"]',
                    ) ?? node;
            }}
            className="space-y-3"
        >
            <FieldError>{childPolicy.error}</FieldError>
            <CollapsibleSectionGroup
                defaultValue={draftSectionsFor(resource, parentValues).map(
                    (section) => `draft-${section.key}`,
                )}
            >
                {draftSectionsFor(resource, parentValues)
                    .filter(
                        (section) =>
                            (!sectionKeys ||
                                sectionKeys.includes(section.key)) &&
                            allowed(section),
                    )
                    .map((section) => {
                        const rows = value[section.key] ?? [];
                        const indexes = new Map(
                            rows.map((row, index) => [row.key, index]),
                        );
                        const update = (next: Row[]) =>
                            onChange({ ...value, [section.key]: next });

                        const body =
                            resource === 'group-aset' &&
                            section.key === 'books' ? (
                                <GroupBookMatrix
                                    canEdit={canEdit}
                                    draft={{
                                        rows: rows.map((row): GroupBookRow => ({
                                            buku_id: String(
                                                row.values.buku_id ?? '',
                                            ),
                                            depreciation_profile_id: String(
                                                row.values
                                                    .depreciation_profile_id ??
                                                    '',
                                            ),
                                            alternative_profile_id: String(
                                                row.values
                                                    .alternative_profile_id ??
                                                    '',
                                            ),
                                            useful_life_periods: String(
                                                row.values
                                                    .useful_life_periods ?? '',
                                            ),
                                            convention:
                                                row.values.convention === 'none'
                                                    ? ''
                                                    : String(
                                                          row.values
                                                              .convention ?? '',
                                                      ),
                                            depreciate:
                                                row.values.depreciate !== false,
                                            round_off_depreciation: String(
                                                row.values
                                                    .round_off_depreciation ??
                                                    '',
                                            ),
                                        })),
                                        onChange: (next) =>
                                            update(
                                                next.map((row) => ({
                                                    key: row.buku_id,
                                                    values: {
                                                        ...row,
                                                        convention:
                                                            row.convention,
                                                    },
                                                })),
                                            ),
                                        errors: Object.fromEntries(
                                            rows.map((_, index) => [
                                                index,
                                                Object.entries(errors)
                                                    .filter(([path]) =>
                                                        path.startsWith(
                                                            `details.books.rows.${index}.`,
                                                        ),
                                                    )
                                                    .flatMap(
                                                        ([, messages]) =>
                                                            messages,
                                                    ),
                                            ]),
                                        ),
                                        error:
                                            errors['details.books.rows'] ??
                                            errors['details.books'],
                                    }}
                                />
                            ) : (
                                <div key={section.key} className="space-y-3">
                                    <ActionButton
                                        action="add"
                                        type="button"
                                        onClick={() =>
                                            update([
                                                ...rows,
                                                {
                                                    key: newIdempotencyKey(),
                                                    values: {
                                                        ...Object.fromEntries(
                                                            section.fields.map(
                                                                (field) => [
                                                                    field.name,
                                                                    emptyValue(
                                                                        field,
                                                                    ),
                                                                ],
                                                            ),
                                                        ),
                                                        line_number:
                                                            nextDraftLineNumber(
                                                                rows,
                                                            ),
                                                        urutan: rows.length + 1,
                                                        ...section.defaults,
                                                    },
                                                },
                                            ])
                                        }
                                    />
                                    <DataTable<Row>
                                        data={rows}
                                        getRowKey={(row) => row.key}
                                        getRowLabel={(row) =>
                                            String(
                                                row.values.nama ||
                                                    row.values.value ||
                                                    row.values.nilai ||
                                                    'Rincian',
                                            )
                                        }
                                        scrollMode="inline"
                                        showRowNumbers
                                        columns={section.fields.map(
                                            (field) => ({
                                                id: field.name,
                                                header: field.label,
                                                width: 230,
                                                cell: (row) => {
                                                    const index =
                                                        indexes.get(row.key) ??
                                                        0;
                                                    const path = `details.${section.key}.${section.payloadKey}.${index}${section.kind === 'links' ? '' : `.${field.name}`}`;

                                                    return isVisible(
                                                        field,
                                                        row.values,
                                                    ) ? (
                                                        <div
                                                            className="min-w-48 py-2"
                                                            data-error-path={
                                                                path
                                                            }
                                                        >
                                                            <DynamicField
                                                                readOnly={
                                                                    field.name ===
                                                                        'kode' &&
                                                                    childResource !==
                                                                        null &&
                                                                    (!childPolicy.allowManual ||
                                                                        childPolicy.loading ||
                                                                        Boolean(
                                                                            childPolicy.error,
                                                                        ))
                                                                }
                                                                config={{
                                                                    ...field,
                                                                    name: `${section.key}-${row.key}-${field.name}`,
                                                                    required:
                                                                        field.name ===
                                                                            'kode' &&
                                                                        childResource
                                                                            ? childPolicy.allowManual &&
                                                                              !childPolicy.allowAutomatic
                                                                            : field.required,
                                                                    min: [
                                                                        'line_number',
                                                                        'interval',
                                                                        'urutan',
                                                                    ].includes(
                                                                        field.name,
                                                                    )
                                                                        ? 1
                                                                        : field.min,
                                                                }}
                                                                value={
                                                                    row.values[
                                                                        field
                                                                            .name
                                                                    ] ?? ''
                                                                }
                                                                portalContainer={
                                                                    portalContainer ??
                                                                    localPortal
                                                                }
                                                                referenceQuery={
                                                                    field.name ===
                                                                        'variant_id' &&
                                                                    row.values
                                                                        .maintenance_job_type_id
                                                                        ? `maintenance_job_type_id=${row.values.maintenance_job_type_id}`
                                                                        : undefined
                                                                }
                                                                onChange={(
                                                                    next,
                                                                ) =>
                                                                    update(
                                                                        rows.map(
                                                                            (
                                                                                item,
                                                                            ) =>
                                                                                item.key ===
                                                                                row.key
                                                                                    ? {
                                                                                          ...item,
                                                                                          values: changeDraftValues(
                                                                                              resource,
                                                                                              item.values,
                                                                                              field.name,
                                                                                              next,
                                                                                          ),
                                                                                      }
                                                                                    : item,
                                                                        ),
                                                                    )
                                                                }
                                                            />
                                                            <FieldError>
                                                                {errors[
                                                                    path
                                                                ]?.join(' ')}
                                                            </FieldError>
                                                        </div>
                                                    ) : (
                                                        '—'
                                                    );
                                                },
                                            }),
                                        )}
                                        actions={[
                                            {
                                                id: 'remove',
                                                label: 'Delete',
                                                icon: (
                                                    <Trash2 aria-hidden="true" />
                                                ),
                                            },
                                        ]}
                                        onRowAction={(_action, row) =>
                                            update(
                                                rows.filter(
                                                    (item) =>
                                                        item.key !== row.key,
                                                ),
                                            )
                                        }
                                        emptyMessage="Belum ada rincian. Isi sebelum menyimpan record ini."
                                    />
                                    <FieldError>
                                        {errors[`details.${section.key}`]?.join(
                                            ' ',
                                        )}
                                    </FieldError>
                                </div>
                            );

                        return sectionKeys?.length === 1 ? (
                            body
                        ) : (
                            <CollapsibleSection
                                key={section.key}
                                value={`draft-${section.key}`}
                                title={section.title}
                            >
                                {body}
                            </CollapsibleSection>
                        );
                    })}
            </CollapsibleSectionGroup>
        </div>
    );
}
