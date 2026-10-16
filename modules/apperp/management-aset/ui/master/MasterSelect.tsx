import { InlineLookup } from '@apperp/ui/inline-lookup';
import type { LookupLoader } from '@apperp/ui/inline-lookup';
import { useCallback, useEffect, useState } from 'react';
import type { RefObject } from 'react';
import { api } from '../api';
import { optionLabel } from './useMasterOptions';
import type { MasterOption } from './useMasterOptions';

/** Pemilih referensi master memakai loader SDK; tidak mengunduh seluruh master. */
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
}) {
    const source = `${resource}?aktif=true&${query}`;
    const [selected, setSelected] = useState<{
        source: string;
        item: MasterOption;
    } | null>(null);
    const [error, setError] = useState('');
    useEffect(() => {
        if (!resource || !value || initialValue?.id === value) {
            return;
        }

        const request = new AbortController();
        const params = new URLSearchParams(query);
        params.set('record_id', value);
        params.set('per_page', '1');
        api<{ data: MasterOption[] }>(`/${resource}?${params}`, {
            signal: request.signal,
        })
            .then((result) => {
                if (!request.signal.aborted && result.data[0]) {
                    setSelected({ source, item: result.data[0] });
                    setError('');
                }
            })
            .catch(() => {
                if (!request.signal.aborted) {
                    setError('Pilihan tersimpan belum dapat dimuat.');
                }
            });

        return () => request.abort();
    }, [resource, source, query, value, initialValue?.id]);
    const loadItems = useCallback<LookupLoader<MasterOption>>(
        async ({ query: search, cursor, signal }) => {
            if (!resource) {
                return { items: [], nextCursor: null };
            }

            const params = new URLSearchParams(query);
            params.set('aktif', 'true');
            params.set('q', search);

            if (cursor) {
                params.set('cursor', cursor);
            }

            const batch = await api<{
                data: MasterOption[];
                next_cursor: string | null;
            }>(`/${resource}?${params}`, { signal });

            return {
                items: [
                    ...(!cursor && emptyLabel
                        ? [
                              {
                                  id: '',
                                  kode: '',
                                  nama: emptyLabel,
                                  display_label: emptyLabel,
                              },
                          ]
                        : []),
                    ...batch.data,
                ],
                nextCursor: batch.next_cursor,
            };
        },
        [resource, query, emptyLabel],
    );
    const item =
        !value && emptyLabel
            ? { id: '', kode: '', nama: emptyLabel, display_label: emptyLabel }
            : initialValue?.id === value
              ? (initialValue as MasterOption)
              : selected?.source === source && selected.item.id === value
                ? selected.item
                : null;

    return (
        <div>
            <InlineLookup<MasterOption>
                label={label}
                placeholder={placeholder}
                disabled={!resource}
                required={required}
                value={item}
                loadItems={loadItems}
                getItemValue={(row) => row.id}
                getItemLabel={optionLabel}
                columns={[
                    {
                        id: 'kode',
                        header: 'Kode',
                        cell: (row) => row.kode,
                        width: 160,
                    },
                    {
                        id: 'nama',
                        header: 'Nama',
                        cell: (row) => row.nama,
                        width: 260,
                    },
                ]}
                onValueChange={(row) => {
                    setSelected({ source, item: row });
                    setError('');
                    onValueChange(row.id);
                }}
                portalContainer={portalContainer}
                showDetails={false}
            />
            {error && (
                <p role="alert" className="text-destructive text-sm">
                    {error}
                </p>
            )}
        </div>
    );
}
