import type { RefObject } from 'react';
import { Select } from '@apperp/ui/select';
import { optionLabel, useMasterOptions } from './useMasterOptions';

export default function AssetConditionSelect({
    assetTypeId,
    assetId,
    value,
    onChange,
    portalContainer,
    label,
}: {
    assetTypeId?: string;
    assetId?: string;
    value: string;
    onChange: (value: string) => void;
    portalContainer?: RefObject<HTMLDivElement | null>;
    label: string;
}) {
    const query = assetId
        ? `aset_id=${assetId}`
        : `jenis_aset_id=${assetTypeId ?? ''}`;
    const { options, error, selectProps } = useMasterOptions(
        'kondisi-aset',
        query,
        value,
    );
    const selected = options.find((item) => item.id === value);

    return (
        <div>
            <Select
                {...selectProps}
                items={options.map(optionLabel)}
                value={selected ? optionLabel(selected) : null}
                placeholder="Tidak dicatat"
                searchPlaceholder="Cari kondisi"
                emptyMessage="Kondisi tidak ditemukan."
                ariaLabel={label}
                portalContainer={portalContainer}
                onValueChange={(item) =>
                    onChange(
                        options.find((option) => optionLabel(option) === item)
                            ?.id ?? '',
                    )
                }
            />
            {error && <p className="text-destructive text-sm">{error}</p>}
        </div>
    );
}
