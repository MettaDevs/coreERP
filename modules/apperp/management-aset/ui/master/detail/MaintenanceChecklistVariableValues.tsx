import { useEffect, useState } from 'react';
import { ActionButton } from '@apperp/ui/action-button';
import type { DataTableColumn } from '@apperp/ui/data-table';
import { DataTable } from '@apperp/ui/data-table';

import { Empty, EmptyDescription } from '@apperp/ui/empty';
import { Input } from '@apperp/ui/input';
import { Select } from '@apperp/ui/select';

import { api, errorMessage } from '../../api';

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
    const [values, setValues] = useState<Value[]>([]);
    const [saving, setSaving] = useState(false);
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
            setError(
                errorMessage(caught, 'Nilai checklist belum dapat disimpan.'),
            );
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
        <div className="space-y-3">
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
                            id: 'column-0',
                            header: 'Nomor baris',
                            cell: ({ item, index }) => (
                                <>
                                    {canEdit ? (
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
                                        />
                                    ) : (
                                        item.line_number
                                    )}
                                </>
                            ),
                        } satisfies DataTableColumn<(typeof sdkRows)[number]>,
                        {
                            id: 'column-1',
                            header: 'Nilai',
                            cell: ({ item, index }) => (
                                <>
                                    {canEdit ? (
                                        <Input
                                            aria-label={`Nilai baris ${index + 1}`}
                                            value={item.value}
                                            onChange={(event) =>
                                                update(index, {
                                                    value: event.target.value,
                                                })
                                            }
                                        />
                                    ) : (
                                        item.value
                                    )}
                                </>
                            ),
                        } satisfies DataTableColumn<(typeof sdkRows)[number]>,
                        {
                            id: 'column-2',
                            header: 'Hasil',
                            cell: ({ item, index }) => (
                                <>
                                    {canEdit ? (
                                        <Select
                                            items={[
                                                'Lulus',
                                                'Gagal',
                                                'Tidak dinilai',
                                            ]}
                                            value={labelHasil(item.result_code)}
                                            ariaLabel={`Hasil baris ${index + 1}`}
                                            onValueChange={(value) =>
                                                update(index, {
                                                    result_code:
                                                        kodeHasil(value),
                                                })
                                            }
                                        />
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
