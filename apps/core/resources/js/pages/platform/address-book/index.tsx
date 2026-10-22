import { ActionButton } from '@apperp/ui/action-button';
import { Badge } from '@apperp/ui/badge';
import { Button } from '@apperp/ui/button';
import { DataTable, DataTableText } from '@apperp/ui/data-table';
import { Input } from '@apperp/ui/input';
import { ListPageLayout } from '@apperp/ui/list-page-layout';
import { NativeSelect, NativeSelectOption } from '@apperp/ui/native-select';
import { PageDialog, PageDialogContent } from '@apperp/ui/page-dialog';
import { RecordActionBar } from '@apperp/ui/record-action-bar';
import { useRecordNavigation } from '@apperp/ui/use-record-navigation';
import { useRecordTransition } from '@apperp/ui/use-record-transition';
import type { InfiniteScrollRef } from '@inertiajs/core';
import { Head, router } from '@inertiajs/react';
import { useRef, useState } from 'react';
import { FormErrorHeader } from '@/components/form-error-header';
import { RecordListScroll } from '@/components/record-list-scroll';
import { apiJson, errorText } from '@/lib/core-api';
import type { BreadcrumbItem } from '@/types/navigation';
import { AddressBookHint } from './components/address-book-hint';
import { AddressBookManager } from './components/address-book-manager';
import { PartyCard } from './components/party-card';
import { PartyForm } from './components/party-form';
import type { DirectoryMetadata, Party } from './types';
import { partyTypeLabel } from './types';

type Props = DirectoryMetadata & {
    parties: {
        data: Party[];
        total: number;
        from: number | null;
        to: number | null;
        prev_page_url: string | null;
        next_page_url: string | null;
    };
    filters: {
        q?: string;
        type?: string;
        status?: string;
        role?: string;
        address_book?: string;
        party?: string;
        section?: string;
        archived?: string;
    };
};

export default function GlobalAddressBook(props: Props) {
    const { parties, filters, canManage, partyTypes, addressBooks } = props;
    const [creating, setCreating] = useState(false);
    const [createKey, setCreateKey] = useState(0);
    const [partyDirty, setPartyDirty] = useState(false);

    const listRef = useRef<InfiniteScrollRef>(null);
    const [listLoading, setListLoading] = useState(false);
    const [partySaving, setPartySaving] = useState(false);
    const transition = useRecordTransition(partyDirty, partySaving);
    const closeParty = () =>
        router.get(
            '/settings/global-address-book',
            { ...filters, party: undefined, section: undefined },
            { preserveState: true, preserveScroll: true },
        );
    const [booksOpen, setBooksOpen] = useState(false);
    const [query, setQuery] = useState(filters.q ?? '');
    const [numbering, setNumbering] = useState(false);
    const [error, setError] = useState('');
    const [restoringId, setRestoringId] = useState<string | null>(null);
    const applyFilter = (name: string, value: string) =>
        router.get(
            '/settings/global-address-book',
            {
                ...filters,
                party: undefined,
                section: undefined,
                [name]: value || undefined,
            },
            { preserveState: true, reset: ['parties'], replace: true },
        );
    const openParty = (party: Party) => {
        if (party.deleted_at) {
            return;
        }

        router.get(
            '/settings/global-address-book',
            {
                ...filters,
                archived: undefined,
                party: party.id,
                section: 'general',
            },
            { preserveState: true, preserveScroll: true },
        );
    };
    const startNew = () =>
        transition.request(() => {
            const open = () => {
                setCreateKey((key) => key + 1);
                setCreating(true);
            };

            if (filters.party) {
                router.get(
                    '/settings/global-address-book',
                    { ...filters, party: undefined, section: undefined },
                    {
                        preserveState: true,
                        preserveScroll: true,
                        onSuccess: open,
                    },
                );
            } else {
                open();
            }
        });
    const navigation = useRecordNavigation({
        items: parties.data.filter((party) => !party.deleted_at),
        currentId: filters.party ?? null,
        getId: (party) => party.id,
        onNavigate: (id) => {
            const party = parties.data.find((row) => row.id === id);

            if (party) {
                openParty(party);
            }
        },
        scopeKey: JSON.stringify({
            ...filters,
            party: undefined,
            section: undefined,
        }),
        disabled: partySaving || partyDirty || creating,
        hasMore: Boolean(parties.next_page_url),
        loading: listLoading,
        loadMore: () => {
            if (!listRef.current?.hasNext()) {
                return;
            }

            setListLoading(true);
            listRef.current.fetchNext({
                onFinish: () => setListLoading(false),
                onCancel: () => setListLoading(false),
            });
        },
    });
    async function restoreParty(party: Party) {
        if (restoringId) {
            return;
        }

        setRestoringId(party.id);
        setError('');

        try {
            await apiJson(`/api/v1/parties/${party.id}/restore`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ version: party.version }),
            });
            router.reload({ only: ['parties'] });
        } catch (caught) {
            setError(errorText(caught, 'Pihak belum dapat dipulihkan.'));
        } finally {
            setRestoringId(null);
        }
    }
    async function assignNumbers() {
        setNumbering(true);
        setError('');

        try {
            await apiJson('/api/v1/parties/assign-numbers', { method: 'POST' });
            router.reload({ only: ['parties'] });
        } catch (caught) {
            setError(errorText(caught, 'Nomor pihak belum dapat diterbitkan.'));
        } finally {
            setNumbering(false);
        }
    }

    return (
        <>
            <Head title="Buku alamat global" />
            <ListPageLayout
                header={
                    <RecordActionBar
                        feedback={
                            <FormErrorHeader
                                message={error}
                                operation="Tindakan pada pihak belum berhasil"
                            />
                        }
                    >
                        <div className="flex items-center gap-2">
                            <h1 className="text-sm font-semibold">
                                Buku alamat global
                            </h1>
                            <AddressBookHint label="buku alamat global">
                                Identitas, alamat, dan kontak pihak yang
                                berhubungan dengan perusahaan Anda.
                            </AddressBookHint>
                        </div>
                        {canManage && (
                            <div className="flex flex-wrap gap-2">
                                <Button
                                    variant="outline"
                                    onClick={() => setBooksOpen(true)}
                                >
                                    Buku alamat
                                </Button>
                                {filters.archived !== 'only' &&
                                    parties.data.some(
                                        (party) => !party.number,
                                    ) && (
                                        <Button
                                            variant="outline"
                                            disabled={numbering}
                                            onClick={() => void assignNumbers()}
                                        >
                                            {numbering
                                                ? 'Menerbitkan…'
                                                : 'Beri nomor pihak lama'}
                                        </Button>
                                    )}
                                <ActionButton
                                    action="create"
                                    onClick={startNew}
                                />
                            </div>
                        )}
                    </RecordActionBar>
                }
                filters={
                    <div className="space-y-2 px-4 pb-4">
                        <form
                            noValidate
                            onSubmit={(event) => {
                                event.preventDefault();
                                applyFilter('q', query);
                            }}
                            className="flex flex-col gap-3 sm:flex-row sm:flex-wrap"
                        >
                            <div className="w-full sm:w-64">
                                <Input
                                    label="Cari nama atau nomor pihak"
                                    value={query}
                                    onChange={(event) =>
                                        setQuery(event.target.value)
                                    }
                                />
                            </div>
                            <Button type="submit" variant="outline">
                                Cari
                            </Button>
                            <div className="w-full sm:w-44">
                                <NativeSelect
                                    label="Peran bisnis"
                                    value={filters.role ?? ''}
                                    onChange={(event) =>
                                        applyFilter('role', event.target.value)
                                    }
                                >
                                    <NativeSelectOption value="">
                                        Semua peran
                                    </NativeSelectOption>
                                    {(props.referenceValues.role ?? []).map(
                                        (role) => (
                                            <NativeSelectOption
                                                key={role.code}
                                                value={role.code}
                                            >
                                                {role.name}
                                            </NativeSelectOption>
                                        ),
                                    )}
                                </NativeSelect>
                            </div>
                            <div className="w-full sm:w-44">
                                <NativeSelect
                                    label="Jenis pihak"
                                    value={filters.type ?? ''}
                                    onChange={(event) =>
                                        applyFilter('type', event.target.value)
                                    }
                                >
                                    <NativeSelectOption value="">
                                        Semua jenis
                                    </NativeSelectOption>
                                    {partyTypes.map((type) => (
                                        <NativeSelectOption
                                            key={type.code}
                                            value={type.code}
                                        >
                                            {partyTypeLabel(
                                                type.code,
                                                partyTypes,
                                            )}
                                        </NativeSelectOption>
                                    ))}
                                </NativeSelect>
                            </div>
                            <div className="w-full sm:w-40">
                                <NativeSelect
                                    label="Status"
                                    value={filters.status ?? ''}
                                    onChange={(event) =>
                                        applyFilter(
                                            'status',
                                            event.target.value,
                                        )
                                    }
                                >
                                    <NativeSelectOption value="">
                                        Semua status
                                    </NativeSelectOption>
                                    <NativeSelectOption value="active">
                                        Aktif
                                    </NativeSelectOption>
                                    <NativeSelectOption value="inactive">
                                        Nonaktif
                                    </NativeSelectOption>
                                </NativeSelect>
                            </div>
                            <div className="w-full sm:w-44">
                                <NativeSelect
                                    label="Buku alamat"
                                    value={filters.address_book ?? ''}
                                    onChange={(event) =>
                                        applyFilter(
                                            'address_book',
                                            event.target.value,
                                        )
                                    }
                                >
                                    <NativeSelectOption value="">
                                        Semua buku alamat
                                    </NativeSelectOption>
                                    {addressBooks.map((book) => (
                                        <NativeSelectOption
                                            key={book.id}
                                            value={book.id}
                                        >
                                            {book.name}
                                        </NativeSelectOption>
                                    ))}
                                </NativeSelect>
                            </div>
                            <div className="w-full sm:w-44">
                                <NativeSelect
                                    label="Tampilkan"
                                    value={filters.archived ?? ''}
                                    onChange={(event) =>
                                        applyFilter(
                                            'archived',
                                            event.target.value,
                                        )
                                    }
                                >
                                    <NativeSelectOption value="">
                                        Pihak belum terarsip
                                    </NativeSelectOption>
                                    <NativeSelectOption value="only">
                                        Pihak terarsip
                                    </NativeSelectOption>
                                </NativeSelect>
                            </div>
                        </form>
                        {error && (
                            <p
                                role="alert"
                                className="text-sm text-destructive"
                            >
                                {error}
                            </p>
                        )}
                    </div>
                }
                footer={
                    <p className="p-3 text-sm text-muted-foreground">
                        {parties.data.length} data dimuat
                    </p>
                }
            >
                <RecordListScroll
                    listRef={listRef}
                    data="parties"
                    className="flex h-full min-h-0 flex-1 flex-col"
                >
                    <DataTable
                        data={parties.data}
                        getRowKey={(party) => party.id}
                        getRowLabel={(party) => party.name}
                        showRowNumbers
                        actionsPlacement="inline"
                        actions={
                            filters.archived === 'only'
                                ? canManage
                                    ? [
                                          {
                                              id: 'restore',
                                              label: 'Pulihkan pihak',
                                          },
                                      ]
                                    : []
                                : [{ id: 'open', label: 'Lihat pihak' }]
                        }
                        onRowAction={(action, party) =>
                            action === 'restore'
                                ? void restoreParty(party)
                                : openParty(party)
                        }
                        onRowClick={openParty}
                        emptyMessage="Belum ada pihak yang sesuai. Tambahkan pihak atau ubah saringan."
                        columns={[
                            {
                                id: 'number',
                                header: 'Nomor pihak',
                                width: 180,
                                cell: (party) => (
                                    <DataTableText>
                                        {party.number ?? 'Belum bernomor'}
                                    </DataTableText>
                                ),
                            },
                            {
                                id: 'name',
                                header: 'Nama',
                                width: 280,
                                cell: (party) => (
                                    <DataTableText>{party.name}</DataTableText>
                                ),
                            },
                            {
                                id: 'type',
                                header: 'Jenis',
                                width: 150,
                                cell: (party) => (
                                    <DataTableText>
                                        {partyTypeLabel(party.type, partyTypes)}
                                    </DataTableText>
                                ),
                            },
                            {
                                id: 'city',
                                header: 'Kota',
                                width: 160,
                                cell: (party) => (
                                    <DataTableText>
                                        {party.city ?? '—'}
                                    </DataTableText>
                                ),
                            },
                            {
                                id: 'roles',
                                header: 'Peran',
                                width: 220,
                                cell: (party) => (
                                    <DataTableText>
                                        {[
                                            ...new Set(
                                                party.roles.map(
                                                    (role) =>
                                                        props.referenceValues.role?.find(
                                                            (value) =>
                                                                value.code ===
                                                                role.code,
                                                        )?.name ?? role.code,
                                                ),
                                            ),
                                        ].join(', ') || '—'}
                                    </DataTableText>
                                ),
                            },
                            {
                                id: 'status',
                                header: 'Status',
                                width: 120,
                                cell: (party) => (
                                    <Badge
                                        variant={
                                            !party.deleted_at &&
                                            party.status === 'active'
                                                ? 'secondary'
                                                : 'outline'
                                        }
                                    >
                                        {party.deleted_at
                                            ? 'Terarsip'
                                            : party.status === 'active'
                                              ? 'Aktif'
                                              : 'Nonaktif'}
                                    </Badge>
                                ),
                            },
                        ]}
                    />
                </RecordListScroll>
            </ListPageLayout>
            {creating && (
                <PageDialog
                    open
                    onOpenChange={(open) => {
                        if (!open && !partySaving) {
                            transition.request(() => setCreating(false));
                        }
                    }}
                >
                    <PageDialogContent
                        aria-describedby={undefined}
                        onEscapeKeyDown={(event) => {
                            if (partySaving) {
                                event.preventDefault();
                            }
                        }}
                        title="Buku alamat global"
                        busy={partySaving}
                        onNew={canManage ? startNew : undefined}
                    >
                        <PartyForm
                            key={createKey}
                            onDirtyChange={setPartyDirty}
                            metadata={props}
                            party={null}
                            onBusyChange={setPartySaving}
                            onClose={() =>
                                transition.request(() => setCreating(false))
                            }
                            onSaved={(party) => {
                                setCreating(false);
                                openParty(party);
                            }}
                        />
                    </PageDialogContent>
                </PageDialog>
            )}
            {filters.party && (
                <PageDialog
                    open
                    onOpenChange={(open) => {
                        if (!open && !partySaving) {
                            transition.request(closeParty);
                        }
                    }}
                >
                    <PageDialogContent
                        aria-describedby={undefined}
                        onEscapeKeyDown={(event) => {
                            if (partySaving) {
                                event.preventDefault();
                            }
                        }}
                        title="Buku alamat global"
                        busy={partySaving}
                        onNew={canManage ? startNew : undefined}
                        navigation={navigation}
                    >
                        <PartyCard
                            key={filters.party}
                            partyId={filters.party}
                            metadata={props}
                            onBack={() => transition.request(closeParty)}

                            onDirtyChange={setPartyDirty}
                            requestTransition={transition.request}
                            onBusyChange={setPartySaving}
                        />
                    </PageDialogContent>
                </PageDialog>
            )}
            {transition.confirmation}
            {booksOpen && (
                <AddressBookManager
                    metadata={props}
                    onClose={() => setBooksOpen(false)}
                />
            )}
        </>
    );
}
GlobalAddressBook.layout = {
    breadcrumbs: [
        { title: 'Buku alamat global', href: '/settings/global-address-book' },
    ] satisfies BreadcrumbItem[],
};
