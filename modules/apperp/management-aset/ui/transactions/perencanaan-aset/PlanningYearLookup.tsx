import { useState } from 'react';
import type { RefObject } from 'react';
import { PlanningYearCreateCard } from '@/pages/foundation/planning-year/planning-years';
import type { PlanningYearRecord } from '@/pages/foundation/planning-year/planning-years';
import { InlineLookup } from '@apperp/ui/inline-lookup';
import type { useRecordOptions } from '../../use-record-list';

export function PlanningYearLookup({
    source,
    value,
    displayValue,
    legalEntityId,
    canCreate,
    portalContainer,
    onValueChange,
}: {
    source: ReturnType<typeof useRecordOptions<PlanningYearRecord>>;
    value: string | null;
    displayValue?: string;
    legalEntityId: string | null;
    canCreate: boolean;
    portalContainer: RefObject<HTMLDivElement | null>;
    onValueChange: (record: PlanningYearRecord) => void;
}) {
    const [creating, setCreating] = useState(false);

    return (
        <>
            <InlineLookup
                label="Tahun rencana"
                required
                value={
                    source.options.find((record) => record.id === value) ?? null
                }
                displayValue={displayValue}
                loadItems={source.loadItems}
                columns={[
                    {
                        id: 'year',
                        header: 'Tahun',
                        cell: (record) => record.year,
                        width: 160,
                    },
                ]}
                getItemValue={(record) => record.id}
                getItemLabel={(record) => String(record.year)}
                onValueChange={(record) => {
                    source.acceptItems([record]);
                    onValueChange(record);
                }}
                disabled={!legalEntityId}
                placeholder={
                    legalEntityId
                        ? 'Pilih tahun rencana'
                        : 'Pilih perusahaan terlebih dahulu'
                }
                emptyMessage="Belum ada tahun rencana yang tersedia."
                portalContainer={portalContainer}
                onCreate={
                    canCreate && legalEntityId
                        ? () => setCreating(true)
                        : undefined
                }
            />
            {creating && legalEntityId && (
                <PlanningYearCreateCard
                    open
                    legalEntityId={legalEntityId}
                    onOpenChange={setCreating}
                    onCreated={(record) => {
                        source.acceptItems([record]);
                        source.reload();
                        onValueChange(record);
                        setCreating(false);
                    }}
                />
            )}
        </>
    );
}
