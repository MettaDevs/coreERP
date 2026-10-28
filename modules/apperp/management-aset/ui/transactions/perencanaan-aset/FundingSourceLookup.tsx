import { useState } from 'react';
import type { RefObject } from 'react';
import { FundingSourceCreateCard } from '@/pages/foundation/funding-source/funding-sources';
import type { FundingSourceRecord } from '@/pages/foundation/funding-source/funding-sources';
import { InlineLookup } from '@apperp/ui/inline-lookup';
import type { useRecordOptions } from '../../use-record-list';

export function FundingSourceLookup({
    source,
    value,
    displayValue,
    canCreate,
    portalContainer,
    onValueChange,
}: {
    source: ReturnType<typeof useRecordOptions<FundingSourceRecord>>;
    value: string | null;
    displayValue?: string;
    canCreate: boolean;
    portalContainer: RefObject<HTMLDivElement | null>;
    onValueChange: (record: FundingSourceRecord) => void;
}) {
    const [creating, setCreating] = useState(false);

    return (
        <>
            <InlineLookup
                label="Sumber dana"
                required
                value={
                    source.options.find((record) => record.id === value) ?? null
                }
                displayValue={displayValue}
                loadItems={source.loadItems}
                columns={[
                    {
                        id: 'name',
                        header: 'Nama',
                        cell: (record) => record.name,
                        width: 240,
                    },
                    {
                        id: 'description',
                        header: 'Deskripsi',
                        cell: (record) => record.description ?? '',
                        width: 320,
                    },
                ]}
                getItemValue={(record) => record.id}
                getItemLabel={source.getLabel}
                onValueChange={(record) => {
                    source.acceptItems([record]);
                    onValueChange(record);
                }}
                placeholder="Pilih sumber dana"
                emptyMessage="Sumber dana tidak ditemukan."
                portalContainer={portalContainer}
                onCreate={canCreate ? () => setCreating(true) : undefined}
            />
            {creating && (
                <FundingSourceCreateCard
                    open
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
