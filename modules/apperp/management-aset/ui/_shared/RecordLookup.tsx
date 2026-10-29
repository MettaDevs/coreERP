import { usePage } from '@inertiajs/react';
import {
    lazy,
    Suspense,
    useCallback,
    useEffect,
    useLayoutEffect,
    useRef,
    useState,
} from 'react';
import type { ComponentProps } from 'react';
import type { Auth } from '@/types';
import { Alert, AlertDescription } from '@apperp/ui/alert';
import { Button } from '@apperp/ui/button';
import { InlineLookup } from '@apperp/ui/inline-lookup';
import type { LookupLoader } from '@apperp/ui/inline-lookup';
import type { Select } from '@apperp/ui/select';
import { api, errorMessage } from '../api';
import { MASTERS, permission } from '../master/masters';
import type { MasterRecord } from '../master/masters';
import { loadMatchingBatch } from './lookup-batches';

const MasterForm = lazy(() => import('../master/MasterForm'));
const UnitCatalogForm = lazy(() =>
    import('@/pages/foundation/unit-of-measure/catalog-form').then(
        (module) => ({
            default: module.CatalogForm,
        }),
    ),
);
const VendorCreateCard = lazy(() =>
    import('@/pages/foundation/vendor/vendors').then((module) => ({
        default: module.VendorCreateCard,
    })),
);
type Option = { id: string; label: string; code: string };
type Source<T extends { id: string }> = {
    sourcePath: string | null;
    options: T[];
    getLabel: (record: T) => string;
    loadItems: LookupLoader<T>;
    acceptItems: (records: T[]) => void;
    reload: () => void;
};
export type RecordLookupProps<T extends { id: string }> = Omit<
    ComponentProps<typeof Select>,
    | 'incremental'
    | 'searchValue'
    | 'onSearchChange'
    | 'visibleValues'
    | 'knownValues'
> & {
    source: Source<T>;
    disabled?: boolean;
    permissions?: string[];
    filterRecord?: (record: T) => boolean;
    filterKey?: string;
    allowCreate?: boolean;
    onCreate?: () => void;
    onCreated?: (record: MasterRecord) => Promise<void>;
};

function codeOf(record: { id: string }): string {
    if ('kode' in record && typeof record.kode === 'string') {
        return record.kode;
    }

    if ('code' in record && typeof record.code === 'string') {
        return record.code;
    }

    if ('number' in record && typeof record.number === 'string') {
        return record.number;
    }

    if ('nomor' in record && typeof record.nomor === 'string') {
        return record.nomor;
    }

    return '';
}

/** Loader dan kartu master asli dipakai bersama agar semua referensi mengikuti pola SDK. */
export default function RecordLookup<T extends { id: string }>({
    source,
    items,
    value,
    onValueChange,
    label,
    ariaLabel,
    required,
    placeholder,
    portalContainer,
    disabled = false,
    className,
    emptyMessage,
    permissions: providedPermissions,
    filterRecord,
    filterKey = '',
    allowCreate = true,
    onCreate,
    onCreated,
}: RecordLookupProps<T>) {
    const page = usePage<{ permissions?: string[]; auth: Auth }>();
    const permissions = providedPermissions ?? page.props.permissions ?? [];
    const [creating, setCreating] = useState(false);
    const [inferredPortal, setInferredPortal] = useState<HTMLElement | null>(
        null,
    );
    const attachRoot = useCallback((node: HTMLDivElement | null) => {
        setInferredPortal(
            node?.closest<HTMLElement>(
                '[data-slot="page-dialog-contents"], [data-slot="dialog-content"], [data-slot="sheet-content"]',
            ) ?? null,
        );
    }, []);
    const [pending, setPending] = useState<{
        id: string;
        sourcePath: string;
        record?: MasterRecord;
        error: string;
    } | null>(null);
    const visiblePending =
        pending?.sourcePath === source.sourcePath ? pending : null;
    const [resolving, setResolving] = useState(false);
    const resource = source.sourcePath?.split('?')[0].replace(/^\//, '');
    const masterResource = resource?.endsWith('/job-types')
        ? 'maintenance-job-types'
        : resource?.match(/^maintenance-job-types\/[^/]+\/variants$/)
          ? 'maintenance-job-type-variants'
          : resource;
    const config = MASTERS.find((master) => master.resource === masterResource);
    const legalEntityId = new URLSearchParams(
        source.sourcePath?.split('?')[1] ?? '',
    ).get('legal_entity_id');
    const canCreateVendor = Boolean(
        resource?.endsWith('/vendor') &&
        legalEntityId &&
        page.props.auth?.membership?.permissions.includes('core.vendor.update'),
    );
    const canCreateUnit = Boolean(
        resource === 'reference-data/units-of-measure' &&
        page.props.auth?.membership?.permissions.includes(
            'core.reference-data.update',
        ),
    );
    const canCreate = Boolean(
        config &&
        permissions.includes(permission(config.resource, 'create')) &&
        permissions.includes(permission(config.resource, 'read')),
    );
    const options = items.map((item) =>
        typeof item === 'string'
            ? { value: item, label: item }
            : { value: item.value, label: item.label },
    );
    const emptyOption = options.find((item) => !item.value);
    const currentSource = useRef({
        getLabel: source.getLabel,
        filterRecord,
        filterKey,
        sourcePath: source.sourcePath,
    });
    useLayoutEffect(() => {
        currentSource.current = {
            getLabel: source.getLabel,
            filterRecord,
            filterKey,
            sourcePath: source.sourcePath,
        };
    });
    const sourceLoader = source.loadItems;
    const emptyLabel = String(emptyOption?.label ?? '');
    const loadItems = useCallback<LookupLoader<Option>>(
        async (request) => {
            if (currentSource.current.filterKey !== filterKey) {
                throw new DOMException('Aborted', 'AbortError');
            }

            const result = await loadMatchingBatch(
                sourceLoader,
                request,
                (record) =>
                    !currentSource.current.filterRecord ||
                    currentSource.current.filterRecord(record),
            );

            if (currentSource.current.filterKey !== filterKey) {
                throw new DOMException('Aborted', 'AbortError');
            }

            const current = currentSource.current;

            return {
                ...result,
                items: [
                    ...(!required &&
                    emptyLabel.length > 0 &&
                    !request.cursor &&
                    !request.query
                        ? [{ id: '', code: '', label: emptyLabel }]
                        : []),
                    ...result.items
                        .filter(
                            (record) =>
                                !current.filterRecord ||
                                current.filterRecord(record),
                        )
                        .map((record) => ({
                            id: record.id,
                            code: codeOf(record),
                            label: current.getLabel(record),
                        })),
                ],
            };
        },
        [sourceLoader, required, emptyLabel, filterKey],
    );
    const selectedRecord = source.options.find((record) => record.id === value);
    const selectedLabel = options.find((item) => item.value === value);
    const selected = selectedRecord
        ? {
              id: selectedRecord.id,
              code: codeOf(selectedRecord),
              label: source.getLabel(selectedRecord),
          }
        : selectedLabel
          ? { id: selectedLabel.value, code: '', label: selectedLabel.label }
          : null;
    const creationRequest = useRef<AbortController | null>(null);
    useEffect(
        () => () => creationRequest.current?.abort(),
        [source.sourcePath],
    );
    async function chooseCreated(id: string, createdRecord?: MasterRecord) {
        if (!source.sourcePath) {
            return;
        }

        creationRequest.current?.abort();
        const request = new AbortController();
        creationRequest.current = request;
        setResolving(true);

        try {
            if (createdRecord && onCreated) {
                await onCreated(createdRecord);
            }

            if (request.signal.aborted) {
                return;
            }

            const [path, query = ''] = source.sourcePath.split('?');
            const params = new URLSearchParams(query);
            params.delete('q');
            params.append('record_ids[]', id);
            const result = await api<{ data: T[] }>(`${path}?${params}`, {
                signal: request.signal,
            });

            if (currentSource.current.sourcePath !== source.sourcePath) {
                return;
            }

            const record = result.data.find(
                (item) =>
                    item.id === id && (!filterRecord || filterRecord(item)),
            );

            if (!record) {
                throw new Error(
                    'Record sudah dibuat, tetapi belum sesuai dengan pilihan pada field ini.',
                );
            }

            source.acceptItems([record]);
            source.reload();
            onValueChange?.(record.id);
            setPending(null);
        } catch (caught) {
            if (request.signal.aborted) {
                return;
            }

            setPending({
                id,
                sourcePath: source.sourcePath,
                record: createdRecord,
                error: errorMessage(
                    caught,
                    'Record sudah dibuat, tetapi belum dapat dipilih. Coba lagi.',
                ),
            });
        } finally {
            if (creationRequest.current === request) {
                setResolving(false);
            }
        }
    }
    const sourceParams = new URLSearchParams(
        source.sourcePath?.split('?')[1] ?? '',
    );
    const scopedVariantParent = resource?.match(
        /^maintenance-job-types\/([^/]+)\/variants$/,
    )?.[1];
    const initialParentIds = Object.fromEntries(
        (config?.parents ?? []).flatMap((parent) => {
            const id =
                sourceParams.get(parent.field) ??
                (parent.field === 'maintenance_job_type_id'
                    ? scopedVariantParent
                    : null);

            return id ? [[parent.field, id]] : [];
        }),
    );
    const formPermissions = MASTERS.flatMap((master) => [
        permission(master.resource, 'read'),
        permission(master.resource, 'create'),
        permission(master.resource, 'update'),
        permission(master.resource, 'archive'),
    ]).filter((item) => permissions.includes(item));

    return (
        <div ref={attachRoot} className={className}>
            <InlineLookup<Option>
                label={label ?? ariaLabel ?? 'Pilihan'}
                required={required}
                value={selected}
                loadItems={loadItems}
                columns={[
                    {
                        id: 'code',
                        header: 'Kode',
                        width: 112,
                        cell: (item) => item.code,
                    },
                    {
                        id: 'label',
                        header: 'Nama',
                        width: 280,
                        cell: (item) =>
                            item.code && item.label.startsWith(item.code)
                                ? item.label
                                      .slice(item.code.length)
                                      .replace(/^\s*[—·-]\s*/, '')
                                : item.label,
                    },
                ]}
                getItemValue={(item) => item.id}
                getItemLabel={(item) => item.label}
                onValueChange={(item) => {
                    setPending(null);
                    onValueChange?.(item.id || null);
                }}
                placeholder={placeholder}
                emptyMessage={emptyMessage}
                portalContainer={portalContainer ?? inferredPortal}
                disabled={disabled || !source.sourcePath || resolving}
                showDetails={false}
                onCreate={
                    !disabled &&
                    allowCreate &&
                    (canCreate ||
                        canCreateVendor ||
                        canCreateUnit ||
                        onCreate) &&
                    !visiblePending
                        ? () => (onCreate ? onCreate() : setCreating(true))
                        : undefined
                }
            />
            {visiblePending && (
                <Alert variant="destructive">
                    <AlertDescription>{visiblePending.error}</AlertDescription>
                    <Button
                        type="button"
                        variant="ghost"
                        disabled={resolving}
                        onClick={() =>
                            void chooseCreated(
                                visiblePending.id,
                                visiblePending.record,
                            )
                        }
                    >
                        Coba pilih lagi
                    </Button>
                </Alert>
            )}
            {creating && canCreateVendor && legalEntityId && (
                <Suspense fallback={<p role="status">Menyiapkan kartu…</p>}>
                    <VendorCreateCard
                        legalEntityId={legalEntityId}
                        onClose={() => setCreating(false)}
                        onCreated={(vendor) => {
                            if (
                                vendor.legal_entity_id !== legalEntityId ||
                                vendor.status !== 'active'
                            ) {
                                throw new Error(
                                    'Pilih vendor aktif untuk perusahaan ini.',
                                );
                            }

                            setCreating(false);
                            void chooseCreated(vendor.id);
                        }}
                    />
                </Suspense>
            )}
            {creating && canCreateUnit && (
                <Suspense fallback={<p role="status">Menyiapkan kartu…</p>}>
                    <UnitCatalogForm
                        draft={{ kind: 'units', record: null }}
                        onClose={() => setCreating(false)}
                        onSaved={(record) => {
                            setCreating(false);
                            void chooseCreated(record.id);
                        }}
                    />
                </Suspense>
            )}
            {creating && config && canCreate && (
                <Suspense fallback={<p role="status">Menyiapkan kartu…</p>}>
                    <MasterForm
                        config={config}
                        initialParentIds={initialParentIds}
                        value={null}
                        canStageDetails
                        permissions={formPermissions}
                        onClose={() => setCreating(false)}
                        onSaved={async (record: MasterRecord) => {
                            setCreating(false);
                            await chooseCreated(record.id, record);
                        }}
                    />
                </Suspense>
            )}
        </div>
    );
}

export { RecordLookup };
