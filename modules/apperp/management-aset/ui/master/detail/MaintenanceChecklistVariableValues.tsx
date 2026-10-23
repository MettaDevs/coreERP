import { useEffect, useRef, useState } from 'react';
import { ActionButton } from '@apperp/ui/action-button';
import type { DataTableColumn } from '@apperp/ui/data-table';
import { DataTable } from '@apperp/ui/data-table';

import { Empty, EmptyDescription } from '@apperp/ui/empty';
import { FormControl } from '@apperp/ui/form-control';
import { Input } from '@apperp/ui/input';
import { Select } from '@apperp/ui/select';

import { api, errorMessage } from '../../api';
import type { ApiValidationErrors } from '../../api';
import type { MasterDetailsPayload } from '../DraftMasterDetails';

type Value = {
    id?: string;
    line_number: number;
    value: string;
    result_code: 'pass' | 'fail' | 'none';
};

export default function MaintenanceChecklistVariableValues({
    variableId,
    canEdit,
    onChange,
    validationErrors,
}: {
    variableId: string;
    canEdit: boolean;
    onChange: (details: MasterDetailsPayload) => void;
    validationErrors?: ApiValidationErrors;
}) {
    const portal = useRef<HTMLDivElement | null>(null);
    const [values, setValues] = useState<Value[]>([]);
    const fieldError = (field: string) =>
        Object.entries(validationErrors ?? {})
            .filter(
                ([name]) =>
                    name === `details.values.${field}` ||
                    name.startsWith(`details.values.${field}.`),
            )
            .flatMap(([, messages]) => messages)
            .join(' ') || undefined;
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState('');
    useEffect(() => {
        api<{ data: Value[] }>(
            `/maintenance-checklist-variables/${variableId}/values`,
        )
            .then((result) => setValues(result.data))
            .catch((caught) =>
                setError(
                    errorMessage(caught, 'Nilai checklist belum dapat dimuat.'),
                ),
            )
            .finally(() => setLoading(false));
    }, [variableId]);
    function changeValues(change: Value[] | ((current: Value[]) => Value[])) {
        const next = typeof change === 'function' ? change(values) : change;
        setValues(next);
        onChange({ values: { values: next } });
    }

    function update(index: number, changes: Partial<Value>) {
        changeValues((current) =>
            current.map((item, currentIndex) =>
                currentIndex === index ? { ...item, ...changes } : item,
            ),
        );
    }

    if (loading) {
        return <p className="text-muted-foreground text-sm">Memuat rincian�</p>;
    }

    if (error) {
        return <p className="text-destructive text-sm">{error}</p>;
    }

    const labelHasil = (result: Value['result_code']): string =>
        ({ pass: 'Lulus', fail: 'Gagal', none: 'Tidak dinilai' })[result];
    const kodeHasil = (label: string | null): Value['result_code'] =>
        label === 'Lulus' ? 'pass' : label === 'Gagal' ? 'fail' : 'none';

    return (
        <div
            ref={(node) => {
                portal.current =
                    node?.closest<HTMLDivElement>(
                        '[data-slot="page-dialog-contents"]',
                    ) ?? null;
            }}
            className="space-y-3"
        >
            <div className="flex items-center justify-between">
                <h3 className="font-semibold">Nilai checklist</h3>
                {canEdit && (
                    <div className="flex gap-2">
                        <ActionButton
                            action="add"
                            type="button"
                            onClick={() =>
                                changeValues((current) => [
                                    ...current,
                                    {
                                        line_number: current.length + 1,
                                        value: '',
                                        result_code: 'pass',
                                    },
                                ])
                            }
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
                                            data-error-field={`details.values.values.${index}.line_number`}
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
                                            data-error-field={`details.values.values.${index}.value`}
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
                                            data-error-field={`details.values.values.${index}.result_code`}
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
            {error && <p className="text-destructive text-sm">{error}</p>}
        </div>
    );
}
