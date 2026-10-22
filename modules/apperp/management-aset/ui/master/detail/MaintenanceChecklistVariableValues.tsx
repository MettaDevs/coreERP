import { useEffect, useRef, useState } from 'react';
import { ActionButton } from '@apperp/ui/action-button';
import type { DataTableColumn } from '@apperp/ui/data-table';
import { DataTable } from '@apperp/ui/data-table';

import { Empty, EmptyDescription } from '@apperp/ui/empty';
import { FormControl } from '@apperp/ui/form-control';
import { Input } from '@apperp/ui/input';
import { Select } from '@apperp/ui/select';

import { api, errorMessage } from '../../api';
import { useSaveFeedback } from '../../use-save-feedback';

type Value = {
    id?: string;
    line_number: number;
    value: string;
    result_code: 'pass' | 'fail' | 'none';
};

export default function MaintenanceChecklistVariableValues({
    variableId,
    canEdit,
    version,
    onVersionChange,
}: {
    variableId: string;
    canEdit: boolean;
    /** Versi record pemilik; penyimpanan rincian ini mengklaimnya. */
    version: number;
    onVersionChange: (version: number) => void;
}) {
    const portal = useRef<HTMLDivElement | null>(null);
    const [values, setValues] = useState<Value[]>([]);
    const [saving, setSaving] = useState(false);
    const {
        scopeRef: feedbackScopeRef,
        feedbackSummary,
        reportSaveError,
        clearSaveFeedback,
        fieldError,
    } = useSaveFeedback(undefined, variableId);
    const [error, setError] = useState('');
    const [saved, setSaved] = useState(false);
    useEffect(() => {
        api<{ data: Value[] }>(
            `/maintenance-checklist-variables/${variableId}/values`,
        )
            .then((result) => setValues(result.data))
            .catch((caught) =>
                setError(
                    errorMessage(caught, 'Nilai checklist belum dapat dimuat.'),
                ),
            );
    }, [variableId]);
    function update(index: number, changes: Partial<Value>) {
        setSaved(false);
        setValues((current) =>
            current.map((item, currentIndex) =>
                currentIndex === index ? { ...item, ...changes } : item,
            ),
        );
    }
    async function save() {
        clearSaveFeedback();
        setSaving(true);
        setError('');

        try {
            const result = await api<{ version: number }>(
                `/maintenance-checklist-variables/${variableId}/values`,
                { method: 'PUT', body: JSON.stringify({ version, values }) },
            );
            onVersionChange(result.version);
            setSaved(true);
        } catch (caught) {
            reportSaveError(caught, 'Nilai checklist belum dapat disimpan.');
        } finally {
            setSaving(false);
        }
    }

    if (error && values.length === 0) {
        return <p className="text-destructive text-sm">{error}</p>;
    }

    const labelHasil = (result: Value['result_code']): string =>
        ({ pass: 'Lulus', fail: 'Gagal', none: 'Tidak dinilai' })[result];
    const kodeHasil = (label: string | null): Value['result_code'] =>
        label === 'Lulus' ? 'pass' : label === 'Gagal' ? 'fail' : 'none';

    return (
        <div
            ref={(node) => {
                feedbackScopeRef.current = node;
                portal.current =
                    node?.closest<HTMLDivElement>(
                        '[data-slot="page-dialog-contents"]',
                    ) ?? null;
            }}
            className="space-y-3"
        >
            {feedbackSummary}
            <div className="flex items-center justify-between">
                <h3 className="font-semibold">Nilai checklist</h3>
                {canEdit && (
                    <div className="flex gap-2">
                        <ActionButton
                            action="add"
                            type="button"
                            onClick={() =>
                                setValues((current) => [
                                    ...current,
                                    {
                                        line_number: current.length + 1,
                                        value: '',
                                        result_code: 'pass',
                                    },
                                ])
                            }
                        />
                        <ActionButton
                            action="save"
                            type="button"
                            disabled={saving}
                            onClick={() => void save()}
                            pending={saving}
                        />
                    </div>
                )}
            </div>
            {values.length === 0 ? (
                <Empty>
                    <EmptyDescription>
                        Belum ada pilihan nilai checklist.
                    </EmptyDescription>
                </Empty>
            ) : (
                (() => {
                    const sdkRows = values.map((item, index) => {
                        return { item, index };
                    });
                    const sdkColumns: DataTableColumn<
                        (typeof sdkRows)[number]
                    >[] = [
                        {
                            content: 'control',
                            id: 'column-0',
                            header: 'Nomor baris',
                            cell: ({ item, index }) => (
                                <>
                                    {canEdit ? (
                                        <FormControl
                                            data-error-field={`values.${index}.line_number`}
                                            error={fieldError(
                                                `values.${index}.line_number`,
                                            )}
                                        >
                                            <Input
                                                aria-label={`Nomor baris ${index + 1}`}
                                                type="number"
                                                min="1"
                                                value={item.line_number}
                                                onChange={(event) =>
                                                    update(index, {
                                                        line_number: Number(
                                                            event.target.value,
                                                        ),
                                                    })
                                                }
                                                aria-invalid={
                                                    !!fieldError(
                                                        `values.${index}.line_number`,
                                                    )
                                                }
                                            />
                                        </FormControl>
                                    ) : (
                                        item.line_number
                                    )}
                                </>
                            ),
                        } satisfies DataTableColumn<(typeof sdkRows)[number]>,
                        {
                            content: 'control',
                            id: 'column-1',
                            header: 'Nilai',
                            cell: ({ item, index }) => (
                                <>
                                    {canEdit ? (
                                        <FormControl
                                            data-error-field={`values.${index}.value`}
                                            error={fieldError(
                                                `values.${index}.value`,
                                            )}
                                        >
                                            <Input
                                                aria-label={`Nilai baris ${index + 1}`}
                                                value={item.value}
                                                onChange={(event) =>
                                                    update(index, {
                                                        value: event.target
                                                            .value,
                                                    })
                                                }
                                                aria-invalid={
                                                    !!fieldError(
                                                        `values.${index}.value`,
                                                    )
                                                }
                                            />
                                        </FormControl>
                                    ) : (
                                        item.value
                                    )}
                                </>
                            ),
                        } satisfies DataTableColumn<(typeof sdkRows)[number]>,
                        {
                            content: 'control',
                            id: 'column-2',
                            header: 'Hasil',
                            cell: ({ item, index }) => (
                                <>
                                    {canEdit ? (
                                        <FormControl
                                            data-error-field={`values.${index}.result_code`}
                                            error={fieldError(
                                                `values.${index}.result_code`,
                                            )}
                                        >
                                            <Select
                                                portalContainer={portal}
                                                items={[
                                                    'Lulus',
                                                    'Gagal',
                                                    'Tidak dinilai',
                                                ]}
                                                value={labelHasil(
                                                    item.result_code,
                                                )}
                                                ariaLabel={`Hasil baris ${index + 1}`}
                                                onValueChange={(value) =>
                                                    update(index, {
                                                        result_code:
                                                            kodeHasil(value),
                                                    })
                                                }
                                            />
                                        </FormControl>
                                    ) : (
                                        labelHasil(item.result_code)
                                    )}
                                </>
                            ),
                        } satisfies DataTableColumn<(typeof sdkRows)[number]>,
                    ];

                    return (
                        <DataTable
                            scrollMode="inline"
                            showRowNumbers={false}
                            serverSort
                            data={sdkRows}
                            columns={sdkColumns}
                            getRowKey={({ item, index }) =>
                                item.id ?? `${item.line_number}-${index}`
                            }
                        />
                    );
                })()
            )}
            {saved && (
                <p className="text-muted-foreground text-sm">
                    Nilai checklist tersimpan.
                </p>
            )}
            {error && <p className="text-destructive text-sm">{error}</p>}
        </div>
    );
}
