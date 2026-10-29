import type { RefObject } from 'react';
import RecordLookup from '../_shared/RecordLookup';
import type { MasterRecord } from './masters';
import { optionLabel } from './useMasterOptions';
import type { useMasterOptions } from './useMasterOptions';

/** Adapter master mempertahankan kaitan induk khusus; pencarian dan kartu dimiliki lookup bersama. */
export default function MasterLookup({
    source,
    value,
    onValueChange,
    label,
    required,
    placeholder,
    disabled,
    portalContainer,
    permissions,
    allowCreate = true,
    onCreated,
}: {
    source: ReturnType<typeof useMasterOptions>;
    value: string;
    onValueChange: (id: string) => void;
    label: string;
    required?: boolean;
    placeholder?: string;
    disabled: boolean;
    portalContainer?: RefObject<HTMLDivElement | null>;
    permissions: string[];
    allowCreate?: boolean;
    onCreated?: (record: MasterRecord) => Promise<void>;
}) {
    return (
        <RecordLookup
            source={source}
            value={value || null}
            label={label}
            required={required}
            items={[
                ...(!required
                    ? [
                          {
                              value: '',
                              label:
                                  placeholder ?? `Tanpa ${label.toLowerCase()}`,
                          },
                      ]
                    : []),
                ...source.options.map((record) => ({
                    value: record.id,
                    label: optionLabel(record),
                })),
            ]}
            onValueChange={(id) => onValueChange(id ?? '')}
            placeholder={placeholder}
            disabled={disabled}
            portalContainer={portalContainer}
            permissions={permissions}
            allowCreate={allowCreate}
            onCreated={onCreated}
        />
    );
}
