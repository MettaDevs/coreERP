import { InlineLookup } from '@apperp/ui/inline-lookup';
import type { LookupLoader } from '@apperp/ui/inline-lookup';
import { useCallback, useState } from 'react';
import type { RefObject } from 'react';
import { loadLookupItems } from '@/lib/lookup-loader';
import type { Organization } from './organization-types';

/** Pilihan organisasi berbagi scope dengan daftar, termasuk pengecualian penempatan draft. */
export function OrganizationLookup({
    value,
    onChange,
    label,
    portalContainer,
    excludeVersionId,
}: {
    value: string;
    onChange: (value: string) => void;
    label: string;
    portalContainer?: RefObject<HTMLElement | null>;
    excludeVersionId?: string;
}) {
    const [selected, setSelected] = useState<Organization | null>(null);
    const loader = useCallback<LookupLoader<Organization>>(
        (request) =>
            loadLookupItems(
                '/settings/organization-hierarchies?incremental=1&resource=organizations' +
                    (excludeVersionId
                        ? `&exclude_version_id=${encodeURIComponent(excludeVersionId)}`
                        : ''),
                request,
            ),
        [excludeVersionId],
    );

    return (
        <InlineLookup
            label={label}
            required
            value={selected?.id === value ? selected : null}
            onValueChange={(item) => {
                setSelected(item);
                onChange(item.id);
            }}
            loadItems={loader}
            getItemValue={(item) => item.id}
            getItemLabel={(item) => item.name}
            columns={[
                {
                    id: 'code',
                    header: 'Kode',
                    cell: (item) =>
                        item.legal_entity?.company_code ??
                        item.operating_unit?.number ??
                        '-',
                },
                {
                    id: 'name',
                    header: 'Nama organisasi',
                    cell: (item) => item.name,
                },
            ]}
            portalContainer={portalContainer}
            showDetails={false}
        />
    );
}
