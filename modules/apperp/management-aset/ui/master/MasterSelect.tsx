import type { RefObject } from 'react';
import RecordLookup from '../_shared/RecordLookup';
import { optionLabel, useMasterOptions } from './useMasterOptions';

/** Referensi master memakai lookup dan pembuat record yang sama pada setiap form. */
export default function MasterSelect({
    resource,
    value,
    onValueChange,
    label,
    query = '',
    required = false,
    portalContainer,
    emptyLabel,
    initialValue,
    placeholder,
    permissions,
    disabled = false,
}: {
    resource: string;
    value: string;
    onValueChange: (id: string) => void;
    label: string;
    query?: string;
    required?: boolean;
    portalContainer?: RefObject<HTMLDivElement | null>;
    emptyLabel?: string;
    initialValue?: { id: string; kode: string; nama: string } | null;
    placeholder?: string;
    permissions?: string[];
    disabled?: boolean;
}) {
    const source = useMasterOptions(resource, query, value);
    const options =
        source.options.some((item) => item.id === initialValue?.id) ||
        !initialValue
            ? source.options
            : [...source.options, initialValue];

    return (
        <div>
            <RecordLookup
                source={source}
                label={label}
                required={required}
                value={value || null}
                items={[
                    ...(!required && emptyLabel
                        ? [{ value: '', label: emptyLabel }]
                        : []),
                    ...options.map((item) => ({
                        value: item.id,
                        label: optionLabel(item),
                    })),
                ]}
                onValueChange={(id) => onValueChange(id ?? '')}
                portalContainer={portalContainer}
                placeholder={placeholder}
                permissions={permissions}
                disabled={disabled || !resource}
            />
            {source.error && (
                <p role="alert" className="text-destructive text-sm">
                    {source.error}
                </p>
            )}
        </div>
    );
}
