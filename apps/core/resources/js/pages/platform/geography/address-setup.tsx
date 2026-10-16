import { Badge } from '@apperp/ui/badge';
import { Button } from '@apperp/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@apperp/ui/card';
import { DataTable, DataTableText } from '@apperp/ui/data-table';
import {
    Empty,
    EmptyDescription,
    EmptyHeader,
    EmptyTitle,
} from '@apperp/ui/empty';
import { InlineLookup } from '@apperp/ui/inline-lookup';
import type { LookupLoader } from '@apperp/ui/inline-lookup';
import { Input } from '@apperp/ui/input';
import { ListPageLayout } from '@apperp/ui/list-page-layout';
import { MasterDetailLayout } from '@apperp/ui/master-detail-layout';
import { Switch } from '@apperp/ui/switch';
import { Textarea } from '@apperp/ui/textarea';
import { useIncrementalList } from '@apperp/ui/use-incremental-list';
import type { ListLoader } from '@apperp/ui/use-incremental-list';
import { Head, router } from '@inertiajs/react';
import { RotateCcw } from 'lucide-react';
import { useCallback, useEffect, useState } from 'react';
import Heading from '@/components/heading';
import { apiJson, errorText } from '@/lib/core-api';
import { loadLookupItems } from '@/lib/lookup-loader';
import { AddressSidebar } from './components/address-sidebar';
import {
    addressFieldLabels,
    addressParameterLabels,
    addressSections,
} from './sections';
import type { Parameter, Section } from './types';

type AddressRecord = Record<string, unknown> & {
    id: string;
    list_code: string;
    list_name: string | null;
};
type ScopeRecord = { id?: string; code: string; name: string };
type ScopeKey = 'country' | 'province' | 'regency' | 'district' | 'village';
type Scope = Record<ScopeKey, ScopeRecord | null>;
type Props = {
    section: Section;
    parameters: Parameter[];
    context: Scope;
    selectedId?: string | null;
};
const EMPTY_SCOPE: Scope = {
    country: null,
    province: null,
    regency: null,
    district: null,
    village: null,
};
const SCOPE_FIELDS: { key: ScopeKey; section: Section; label: string }[] = [
    { key: 'country', section: 'countries', label: 'Negara / wilayah' },
    { key: 'province', section: 'provinces', label: 'Provinsi' },
    { key: 'regency', section: 'regencies', label: 'Kabupaten / kota' },
    { key: 'district', section: 'districts', label: 'Kecamatan' },
    { key: 'village', section: 'villages', label: 'Kelurahan / desa' },
];

function scopeParams(scope: Scope): URLSearchParams {
    const params = new URLSearchParams();

    for (const { key } of SCOPE_FIELDS) {
        const item = scope[key];

        if (item) {
            params.set(
                key === 'country' ? 'country' : `${key}_id`,
                key === 'country' ? item.code : (item.id ?? ''),
            );
        }
    }

    return params;
}

function scopeDepth(section: Section): number {
    if (
        ['parameters', 'addressFormat', 'countries', 'provinces'].includes(
            section,
        )
    ) {
        return 1;
    }

    if (['regencies', 'cities'].includes(section)) {
        return 2;
    }

    if (section === 'districts') {
        return 3;
    }

    if (section === 'villages') {
        return 4;
    }

    return 5;
}

function sectionScope(section: Section, scope: Scope): Scope {
    return Object.fromEntries(
        SCOPE_FIELDS.map(({ key }, index) => [
            key,
            index < scopeDepth(section) ? scope[key] : null,
        ]),
    ) as Scope;
}

function ScopeLookup({
    scope,
    field,
    disabled,
    onChoose,
}: {
    scope: Scope;
    field: (typeof SCOPE_FIELDS)[number];
    disabled: boolean;
    onChoose: (record: ScopeRecord) => void;
}) {
    const position = SCOPE_FIELDS.findIndex(
        (candidate) => candidate.key === field.key,
    );
    const parentScope = Object.fromEntries(
        SCOPE_FIELDS.map(({ key }, index) => [
            key,
            index < position ? scope[key] : null,
        ]),
    ) as Scope;
    const parentQuery = scopeParams(parentScope).toString();
    const loader = useCallback<LookupLoader<AddressRecord>>(
        (request) =>
            loadLookupItems(
                `/settings/address-setup/divisions?section=${field.section}&include_cities=1&${parentQuery}`,
                request,
            ),
        [field.section, parentQuery],
    );
    const value = scope[field.key];

    return (
        <div className="w-full min-w-0 sm:w-52">
            <InlineLookup<AddressRecord>
                label={field.label}
                disabled={disabled}
                loadItems={loader}
                value={
                    value
                        ? {
                              ...value,
                              id: value.id ?? value.code,
                              list_code: value.code,
                              list_name: value.name,
                          }
                        : null
                }
                getItemValue={(item) => item.id}
                getItemLabel={(item) => item.list_name ?? item.list_code}
                columns={[
                    {
                        id: 'code',
                        header: 'Kode',
                        cell: (item) => item.list_code,
                        width: 140,
                    },
                    {
                        id: 'name',
                        header: 'Nama',
                        cell: (item) => item.list_name,
                        width: 280,
                    },
                ]}
                onValueChange={(item) =>
                    onChoose({
                        id: item.id,
                        code: item.list_code,
                        name: item.list_name ?? item.list_code,
                    })
                }
            />
        </div>
    );
}

function RecordDetails({ record }: { record: AddressRecord | null }) {
    return (
        <Card className="h-full min-h-0 overflow-hidden">
            <CardHeader>
                <CardTitle>{record?.list_name ?? 'Rincian wilayah'}</CardTitle>
            </CardHeader>
            <CardContent className="min-h-0 flex-1 overflow-y-auto">
                <div className="grid content-start gap-4 py-2 sm:grid-cols-2">
                    {record ? (
                        Object.entries(addressFieldLabels)
                            .filter(
                                ([key]) =>
                                    record[key] !== undefined &&
                                    record[key] !== null &&
                                    record[key] !== '',
                            )
                            .map(([key, label]) =>
                                typeof record[key] === 'boolean' ? (
                                    <div
                                        key={key}
                                        className="flex items-center justify-between gap-3"
                                    >
                                        <span className="text-sm">{label}</span>
                                        <Badge variant="secondary">
                                            {record[key] ? 'Ya' : 'Tidak'}
                                        </Badge>
                                    </div>
                                ) : (
                                    <Input
                                        key={key}
                                        label={label}
                                        value={String(record[key])}
                                        readOnly
                                    />
                                ),
                            )
                    ) : (
                        <Empty className="sm:col-span-2">
                            <EmptyHeader>
                                <EmptyTitle>
                                    Pilih record untuk melihat rincian
                                </EmptyTitle>
                                <EmptyDescription>
                                    Klik satu baris pada daftar di sebelah kiri.
                                </EmptyDescription>
                            </EmptyHeader>
                        </Empty>
                    )}
                </div>
            </CardContent>
        </Card>
    );
}

export default function AddressSetup({
    section,
    parameters,
    context,
    selectedId,
}: Props) {
    const [activeSection, setActiveSection] = useState<Section>(section);
    const [scope, setScope] = useState<Scope>(
        sectionScope(section, { ...EMPTY_SCOPE, ...context }),
    );
    const [search, setSearch] = useState('');
    const [showDetail, setShowDetail] = useState(false);
    const [selected, setSelected] = useState<AddressRecord | null>(null);
    const [detailError, setDetailError] = useState('');
    const setup =
        activeSection === 'parameters' || activeSection === 'addressFormat';
    const currentSection =
        addressSections.find((item) => item.key === activeSection) ??
        addressSections[2];
    const scopeQuery = scopeParams(scope).toString();
    const loader = useCallback<ListLoader<AddressRecord>>(
        async (request) => {
            const params = new URLSearchParams(scopeQuery);
            params.set('section', activeSection);
            params.set('q', request.query);

            if (request.cursor) {
                params.set('cursor', request.cursor);
            }

            if (request.sort) {
                params.set('sort', request.sort.columnId);
                params.set('direction', request.sort.direction);
            }

            const result = await apiJson<{
                data: AddressRecord[];
                next_cursor: string | null;
            }>(`/settings/address-setup/divisions?${params}`, {
                signal: request.signal,
            });

            return { items: result.data, nextCursor: result.next_cursor };
        },
        [scopeQuery, activeSection],
    );
    const list = useIncrementalList({
        loadItems: loader,
        query: search,
        active: !setup,
        getKey: (record: AddressRecord) => record.id,
    });

    useEffect(() => {
        if (!selectedId || setup) {
            return;
        }

        const controller = new AbortController();
        const params = new URLSearchParams(scopeQuery);
        params.set('section', activeSection);
        params.set('record_id', selectedId);
        apiJson<{ data: AddressRecord[] }>(
            `/settings/address-setup/divisions?${params}`,
            { signal: controller.signal },
        )
            .then((result) => {
                if (!controller.signal.aborted) {
                    setSelected(result.data[0] ?? null);
                    setShowDetail(!!result.data[0]);
                }
            })
            .catch((error) => {
                if (!controller.signal.aborted) {
                    setDetailError(
                        errorText(error, 'Rincian belum dapat dimuat.'),
                    );
                }
            });

        return () => controller.abort();
    }, [selectedId, setup, scopeQuery, activeSection]);

    function navigate(nextSection: Section, nextScope: Scope) {
        nextScope = sectionScope(nextSection, nextScope);
        setActiveSection(nextSection);
        setScope(nextScope);
        setSearch('');
        setSelected(null);
        setShowDetail(false);
        setDetailError('');
        const params = scopeParams(nextScope);
        params.set('section', nextSection);
        router.get(
            `/settings/address-setup?${params}`,
            {},
            { preserveState: true, preserveScroll: true, replace: true },
        );
    }
    function chooseScope(position: number, record: ScopeRecord) {
        const next = { ...scope };
        SCOPE_FIELDS.forEach(({ key }, index) => {
            if (index >= position) {
                next[key] = index === position ? record : null;
            }
        });
        navigate(activeSection, next);
    }
    const parameter = parameters.find(
        (item) => item.country_code === scope.country?.code,
    );
    const visibleScopeCount = scopeDepth(activeSection);

    return (
        <>
            <Head title="Pengaturan alamat" />
            <div className="flex h-full min-h-0 flex-col overflow-hidden bg-background text-foreground lg:flex-row">
                <AddressSidebar
                    activeSection={activeSection}
                    onSelectSection={(next) => navigate(next, scope)}
                />
                <ListPageLayout
                    className="min-w-0 flex-1"
                    header={
                        <div className="flex flex-wrap items-center justify-between gap-3 border-b p-4">
                            <Heading title={currentSection.label} />
                            <div className="flex items-center gap-2">
                                <Badge variant="secondary">
                                    Data acuan · baca saja
                                </Badge>
                                <Button
                                    variant="outline"
                                    onClick={() => {
                                        list.reload();
                                        router.reload({
                                            only: ['parameters', 'context'],
                                        });
                                    }}
                                >
                                    <RotateCcw />
                                    Muat ulang
                                </Button>
                            </div>
                        </div>
                    }
                    filters={
                        <div className="flex flex-wrap items-center gap-3 border-b p-4">
                            {!setup && (
                                <div className="w-full sm:w-64">
                                    <Input
                                        aria-label={`Cari ${currentSection.label.toLowerCase()}`}
                                        placeholder="Cari kode atau nama…"
                                        value={search}
                                        onChange={(event) => {
                                            setSearch(event.target.value);
                                            setSelected(null);
                                            setShowDetail(false);
                                        }}
                                    />
                                </div>
                            )}
                            {SCOPE_FIELDS.slice(0, visibleScopeCount).map(
                                (field, index) => (
                                    <ScopeLookup
                                        key={field.key}
                                        scope={scope}
                                        field={field}
                                        disabled={
                                            index > 0 &&
                                            !scope[SCOPE_FIELDS[index - 1].key]
                                        }
                                        onChoose={(record) =>
                                            chooseScope(index, record)
                                        }
                                    />
                                ),
                            )}
                            <Button
                                variant="outline"
                                disabled={
                                    !Object.values(scope).some(Boolean) &&
                                    !search
                                }
                                onClick={() =>
                                    navigate(activeSection, EMPTY_SCOPE)
                                }
                            >
                                Reset filter
                            </Button>
                        </div>
                    }
                >
                    {setup ? (
                        <div className="min-h-0 flex-1 overflow-y-auto p-4">
                            <Card>
                                <CardHeader>
                                    <CardTitle>
                                        {currentSection.label}
                                    </CardTitle>
                                </CardHeader>
                                <CardContent className="space-y-4">
                                    {!scope.country ? (
                                        <p className="text-sm text-muted-foreground">
                                            Pilih negara untuk melihat
                                            pengaturan alamatnya.
                                        </p>
                                    ) : !parameter ? (
                                        <p className="text-sm text-muted-foreground">
                                            Pengaturan alamat untuk negara ini
                                            belum tersedia.
                                        </p>
                                    ) : activeSection === 'parameters' ? (
                                        <div className="grid gap-4 sm:grid-cols-2">
                                            {Object.entries(
                                                addressParameterLabels,
                                            ).map(([key, label]) => (
                                                <div
                                                    key={key}
                                                    className="flex items-center justify-between gap-3"
                                                >
                                                    <span className="text-sm">
                                                        {label}
                                                    </span>
                                                    <Switch
                                                        aria-label={label}
                                                        checked={Boolean(
                                                            parameter[
                                                                key as keyof typeof addressParameterLabels
                                                            ],
                                                        )}
                                                        disabled
                                                    />
                                                </div>
                                            ))}
                                        </div>
                                    ) : (
                                        <Textarea
                                            label="Format alamat tercetak"
                                            value={
                                                parameter.address_format ?? ''
                                            }
                                            readOnly
                                            className="min-h-32"
                                        />
                                    )}
                                </CardContent>
                            </Card>
                        </div>
                    ) : (
                        <MasterDetailLayout
                            detailOpen={showDetail}
                            onDetailOpenChange={setShowDetail}
                            list={
                                <div className="flex h-full min-h-0 min-w-0 flex-col overflow-hidden">
                                    <DataTable<AddressRecord>
                                        data={list.items}
                                        getRowKey={(record) => record.id}
                                        scrollMode="viewport"
                                        columns={[
                                            {
                                                id: 'code',
                                                header:
                                                    currentSection.codeLabel ??
                                                    'Kode',
                                                cell: (record) => (
                                                    <DataTableText>
                                                        {record.list_code ||
                                                            '—'}
                                                    </DataTableText>
                                                ),
                                                width: 160,
                                            },
                                            {
                                                id: 'name',
                                                header: 'Nama',
                                                cell: (record) => (
                                                    <DataTableText>
                                                        {record.list_name ??
                                                            '—'}
                                                    </DataTableText>
                                                ),
                                                width: 280,
                                            },
                                        ]}
                                        rowActivation="single-click"
                                        activeRowKey={selected?.id}
                                        onRowClick={(record) => {
                                            setSelected(record);
                                            setShowDetail(true);
                                        }}
                                        sort={list.sort}
                                        onSortChange={list.onSortChange}
                                        serverSort
                                        incremental={list}
                                    />
                                </div>
                            }
                        >
                            <div className="flex h-full min-h-0 min-w-0 flex-col gap-3 overflow-hidden p-4">
                                {detailError && (
                                    <p
                                        role="alert"
                                        className="text-sm text-destructive"
                                    >
                                        {detailError}
                                    </p>
                                )}
                                <RecordDetails record={selected} />
                            </div>
                        </MasterDetailLayout>
                    )}
                </ListPageLayout>
            </div>
        </>
    );
}
