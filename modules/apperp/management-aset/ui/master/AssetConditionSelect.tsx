import type { RefObject } from 'react';
import { api } from '../api';
import { useSaveFeedback } from '../use-save-feedback';
import MasterLookup from './MasterLookup';
import { useMasterOptions } from './useMasterOptions';

export default function AssetConditionSelect({
    assetTypeId,
    assetId,
    value,
    onChange,
    portalContainer,
    label,
    permissions = [],
}: {
    assetTypeId?: string;
    assetId?: string;
    value: string;
    onChange: (value: string) => void;
    portalContainer?: RefObject<HTMLDivElement | null>;
    label: string;
    permissions?: string[];
}) {
    const { scopeRef, feedbackSummary, reportSaveError, clearSaveFeedback } =
        useSaveFeedback(undefined, assetId ?? assetTypeId);
    const query = assetId
        ? `aset_id=${assetId}`
        : `jenis_aset_id=${assetTypeId ?? ''}`;
    const source = useMasterOptions('kondisi-aset', query, value);

    return (
        <div ref={scopeRef}>
            {feedbackSummary}
            <MasterLookup
                source={source}
                label={label}
                value={value}
                onValueChange={onChange}
                placeholder="Tidak dicatat"
                disabled={false}
                portalContainer={portalContainer}
                permissions={permissions}
                allowCreate={Boolean(
                    assetTypeId &&
                    permissions.includes('management-aset.jenis-aset.read') &&
                    permissions.includes('management-aset.jenis-aset.update') &&
                    permissions.includes('management-aset.kondisi-aset.read'),
                )}
                onCreated={async (record) => {
                    clearSaveFeedback();

                    if (!assetTypeId) {
                        return;
                    }

                    try {
                        const current = await api<{
                            data: { selected: { id: string }[] };
                            version: number;
                        }>(`/jenis-aset/${assetTypeId}/conditions`);
                        const ids = current.data.selected.map(
                            (item) => item.id,
                        );

                        if (ids.length === 0 || ids.includes(record.id)) {
                            return;
                        }

                        await api(`/jenis-aset/${assetTypeId}/conditions`, {
                            method: 'PUT',
                            body: JSON.stringify({
                                version: current.version,
                                condition_ids: [...ids, record.id],
                            }),
                        });
                    } catch (caught) {
                        reportSaveError(
                            caught,
                            'Kondisi belum dapat dikaitkan ke jenis aset.',
                        );
                    }
                }}
            />
            {source.error && (
                <p role="alert" className="text-destructive text-sm">
                    {source.error}
                </p>
            )}
        </div>
    );
}
