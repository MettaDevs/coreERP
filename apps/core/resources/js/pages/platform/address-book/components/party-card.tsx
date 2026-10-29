import { ActionButton } from '@apperp/ui/action-button';
import {
    AlertDialog,
    AlertDialogAction,
    AlertDialogCancel,
    AlertDialogContent,
    AlertDialogDescription,
    AlertDialogFooter,
    AlertDialogHeader,
    AlertDialogTitle,
} from '@apperp/ui/alert-dialog';
import { Badge } from '@apperp/ui/badge';
import { Button } from '@apperp/ui/button';
import {
    CollapsibleSection,
    CollapsibleSectionGroup,
} from '@apperp/ui/collapsible-section';
import { DataTable, DataTableText } from '@apperp/ui/data-table';
import {
    Empty,
    EmptyDescription,
    EmptyHeader,
    EmptyTitle,
} from '@apperp/ui/empty';
import { usePageDialogRecord } from '@apperp/ui/page-dialog';
import { RecordActionBar } from '@apperp/ui/record-action-bar';
import { Link, router, usePage } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import type { RefObject } from 'react';
import { FormErrorHeader } from '@/components/form-error-header';
import { apiJson, apiRequest, errorText } from '@/lib/core-api';
import {
    OrganizationAddressesSection,
    OrganizationContactsSection,
} from '../../organization/components/address-book-section';
import type { DirectoryMetadata, PartyDetail } from '../types';
import { partyTypeLabel } from '../types';
import { AddressBookHint } from './address-book-hint';
import {
    commonProfileFields,
    organizationProfileFields,
    PartyForm,
    personProfileFields,
} from './party-form';
import { PartyRelationships } from './party-relationships';

export function PartyCard({
    portalContainer,
    partyId,
    metadata,
    onBack,
    onBusyChange,
    onDirtyChange,
    requestTransition,
}: {
    portalContainer?: RefObject<HTMLElement | null>;
    partyId: string;
    metadata: DirectoryMetadata;
    onBack: () => void;
    onBusyChange?: (busy: boolean) => void;
    onDirtyChange?: (dirty: boolean) => void;
    requestTransition?: (action: () => void) => void;
}) {
    const { url } = usePage();
    const section =
        new URLSearchParams(url.split('?')[1] ?? '').get('section') ??
        'general';
    const [party, setParty] = useState<PartyDetail | null>(null);
    const [editing, setEditing] = useState(false);
    const [confirmArchive, setConfirmArchive] = useState(false);
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState('');
    useEffect(() => {
        onBusyChange?.(busy);

        return () => onBusyChange?.(false);
    }, [busy, onBusyChange]);
    const [reload, setReload] = useState(0);
    useEffect(() => {
        const controller = new AbortController();
        apiJson<{ data: PartyDetail }>(`/api/v1/parties/${partyId}`, {
            signal: controller.signal,
        })
            .then((result) => {
                if (!controller.signal.aborted) {
                    setParty(result.data);
                    setError('');
                }
            })
            .catch((caught) => {
                if (!controller.signal.aborted) {
                    setError(errorText(caught, 'Pihak belum dapat dimuat.'));
                }
            });

        return () => controller.abort();
    }, [partyId, reload]);
    const loadedPartyId = party?.id;
    useEffect(() => {
        if (!loadedPartyId) {
            return;
        }

        document.getElementById(section)?.scrollIntoView({ block: 'start' });
    }, [section, loadedPartyId]);
    async function archive() {
        if (!party) {
            return;
        }

        setBusy(true);
        setError('');

        try {
            await apiRequest(`/api/v1/parties/${party.id}`, {
                method: 'DELETE',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ version: party.version }),
            });
            onBack();
        } catch (caught) {
            setError(errorText(caught, 'Pihak belum dapat diarsipkan.'));
        } finally {
            setBusy(false);
        }
    }

    const embedded = usePageDialogRecord(
        !party || editing
            ? undefined
            : {
                  recordTitle: [party.number, party.name]
                      .filter(Boolean)
                      .join(' · '),
                  onEdit: metadata.canManage
                      ? () => setEditing(true)
                      : undefined,
                  onDelete: metadata.canManage
                      ? () => setConfirmArchive(true)
                      : undefined,
                  editing: false,
                  recordUrl:
                      '/settings/global-address-book?' +
                      new URLSearchParams({
                          party: party.id,
                          section,
                      }).toString(),
              },
    );

    if (!party) {
        return (
            <div className="space-y-3 p-4">
                {!embedded && (
                    <Button variant="outline" onClick={onBack}>
                        Kembali ke daftar
                    </Button>
                )}
                <p
                    role={error ? 'alert' : 'status'}
                    className={
                        error
                            ? 'text-sm text-destructive'
                            : 'text-sm text-muted-foreground'
                    }
                >
                    {error || 'Memuat pihak…'}
                </p>
                {error && (
                    <Button
                        variant="outline"
                        onClick={() => setReload((key) => key + 1)}
                    >
                        Coba lagi
                    </Button>
                )}
            </div>
        );
    }

    if (editing) {
        return (
            <PartyForm
                portalContainer={portalContainer}
                party={party}
                onBusyChange={onBusyChange}
                onDirtyChange={onDirtyChange}
                metadata={metadata}
                onClose={() =>
                    requestTransition
                        ? requestTransition(() => setEditing(false))
                        : setEditing(false)
                }
                onSaved={() => {
                    setEditing(false);
                    setReload((key) => key + 1);
                    router.reload({ only: ['parties'] });
                }}
            />
        );
    }

    const profileFields = [
        ...commonProfileFields,
        ...(party.type === 'person'
            ? personProfileFields
            : [
                  {
                      key: 'organization_number' as const,
                      label: 'NIB (Nomor Induk Berusaha)',
                  },
                  ...organizationProfileFields,
                  // Jumlah karyawan belum terhubung integrasi; jangan tampilkan nilai kosong/0.
                  ...(party.number_of_employees && party.number_of_employees > 0
                      ? [
                            {
                                key: 'number_of_employees' as const,
                                label: 'Jumlah karyawan',
                            },
                        ]
                      : []),
              ]),
    ];
    const roleLabel = (code: string) =>
        metadata.referenceValues.role?.find((value) => value.code === code)
            ?.name ?? code;

    return (
        <div className="flex h-full min-h-0 flex-col">
            {!embedded && (
                <RecordActionBar
                    feedback={
                        <FormErrorHeader
                            message={error}
                            operation="Pihak belum dapat diarsipkan"
                        />
                    }
                >
                    <Button variant="outline" onClick={onBack}>
                        Kembali ke daftar
                    </Button>
                    {metadata.canManage && (
                        <>
                            <ActionButton
                                action="edit"
                                onClick={() => setEditing(true)}
                            />
                            <ActionButton
                                action="archive"
                                disabled={busy}
                                onClick={() => setConfirmArchive(true)}
                            />
                        </>
                    )}
                </RecordActionBar>
            )}
            <main className="min-h-0 flex-1 overflow-y-auto p-4">
                {error && (
                    <p role="alert" className="text-sm text-destructive">
                        {error}
                    </p>
                )}
                <CollapsibleSectionGroup defaultValue={['general', section]}>
                    <CollapsibleSection
                        value="general"
                        title="General"
                        summary={`${party.number ?? 'Nomor belum diterbitkan'} · ${party.name}`}
                    >
                        <div id="general" className="scroll-mt-16 space-y-4">
                            <dl className="grid gap-4 sm:grid-cols-2">
                                <div>
                                    <dt className="text-sm text-muted-foreground">
                                        Jenis pihak
                                    </dt>
                                    <dd>
                                        {partyTypeLabel(
                                            party.type,
                                            metadata.partyTypes,
                                        )}
                                    </dd>
                                </div>
                                <div>
                                    <dt className="text-sm text-muted-foreground">
                                        Status
                                    </dt>
                                    <dd>
                                        <Badge
                                            variant={
                                                party.status === 'active'
                                                    ? 'secondary'
                                                    : 'outline'
                                            }
                                        >
                                            {party.status === 'active'
                                                ? 'Aktif'
                                                : 'Nonaktif'}
                                        </Badge>
                                    </dd>
                                </div>
                                <div>
                                    <dt className="text-sm text-muted-foreground">
                                        NPWP
                                    </dt>
                                    <dd>{party.tax_number ?? '—'}</dd>
                                </div>
                                <div>
                                    <dt className="text-sm text-muted-foreground">
                                        Nama pencarian
                                    </dt>
                                    <dd>{party.search_name}</dd>
                                </div>
                                <div>
                                    <dt className="text-sm text-muted-foreground">
                                        Buku alamat
                                    </dt>
                                    <dd>
                                        {party.address_books
                                            .map((book) => book.name)
                                            .join(', ') || '—'}
                                    </dd>
                                </div>
                                {profileFields.map((field) => (
                                    <div key={field.key}>
                                        <dt className="text-sm text-muted-foreground">
                                            {field.label}
                                        </dt>
                                        <dd>
                                            {metadata.referenceValues[
                                                field.key
                                            ]?.find(
                                                (value) =>
                                                    value.code ===
                                                    party[field.key],
                                            )?.name ??
                                                party[field.key] ??
                                                '—'}
                                        </dd>
                                    </div>
                                ))}
                            </dl>
                            {party.memo && (
                                <div>
                                    <p className="text-sm text-muted-foreground">
                                        Catatan
                                    </p>
                                    <p className="whitespace-pre-wrap">
                                        {party.memo}
                                    </p>
                                </div>
                            )}
                            {party.organization_id && (
                                <Link
                                    className="text-sm text-primary underline"
                                    href="/settings/organization"
                                >
                                    Ubah identitas organisasi dari halaman
                                    Organisasi
                                </Link>
                            )}
                        </div>
                    </CollapsibleSection>
                    <CollapsibleSection
                        value="addresses"
                        title="Alamat"
                        summary={`Alamat bersama Party`}
                    >
                        <div id="addresses" className="scroll-mt-16">
                            <OrganizationAddressesSection
                                organizationId={party.id}
                                resource="parties"
                                countryCode="ID"
                                canManage={metadata.canManage}
                            />
                        </div>
                    </CollapsibleSection>
                    <CollapsibleSection
                        value="contacts"
                        title="Informasi kontak"
                        summary={`Telepon, email, dan kontak lainnya`}
                    >
                        <div id="contacts" className="scroll-mt-16">
                            <OrganizationContactsSection
                                organizationId={party.id}
                                resource="parties"
                                canManage={metadata.canManage}
                            />
                        </div>
                    </CollapsibleSection>
                    <CollapsibleSection
                        value="relationships"
                        title="Hubungan antar pihak"
                        summary={`${party.relationships.length} hubungan`}
                    >
                        <div id="relationships" className="scroll-mt-16">
                            <PartyRelationships
                                party={party}
                                metadata={metadata}
                                onChanged={() => setReload((key) => key + 1)}
                            />
                        </div>
                    </CollapsibleSection>
                    <CollapsibleSection
                        value="roles"
                        title="Peran bisnis"
                        summary={`${party.roles.length} peran bisnis`}
                    >
                        <div id="roles" className="scroll-mt-16">
                            {party.roles.length === 0 ? (
                                <Empty>
                                    <EmptyHeader>
                                        <EmptyTitle>
                                            Belum ada peran bisnis
                                        </EmptyTitle>
                                        <EmptyDescription>
                                            Peran muncul setelah pihak ini
                                            dipakai sebagai akun bisnis,
                                            misalnya vendor.
                                        </EmptyDescription>
                                    </EmptyHeader>
                                </Empty>
                            ) : (
                                <DataTable
                                    scrollMode="inline"
                                    data={party.roles}
                                    getRowKey={(role) =>
                                        `${role.code}-${role.legal_entity_id}`
                                    }
                                    emptyMessage="Pihak ini belum dipakai sebagai akun bisnis."
                                    columns={[
                                        {
                                            id: 'role',
                                            header: 'Peran',
                                            cell: (role) => (
                                                <DataTableText>
                                                    {roleLabel(role.code)}
                                                </DataTableText>
                                            ),
                                        },
                                        {
                                            id: 'company',
                                            header: 'Perusahaan',
                                            cell: (role) => (
                                                <DataTableText>
                                                    {role.company ?? '—'}
                                                </DataTableText>
                                            ),
                                        },
                                    ]}
                                />
                            )}
                        </div>
                    </CollapsibleSection>
                    {metadata.canReadVendors && (
                        <CollapsibleSection
                            value="vendor"
                            title="Akun vendor"
                            summary={`${party.vendors.length} akun vendor`}
                        >
                            <div id="vendor" className="scroll-mt-16 space-y-3">
                                <div className="flex justify-end">
                                    <AddressBookHint label="akun vendor">
                                        Nomor dan status vendor berlaku per
                                        perusahaan. Nama, alamat, serta kontak
                                        memakai pihak yang sama.
                                    </AddressBookHint>
                                </div>
                                <DataTable
                                    scrollMode="inline"
                                    data={party.vendors}
                                    getRowKey={(vendor) => vendor.id}
                                    actions={[
                                        { id: 'open', label: 'Lihat vendor' },
                                    ]}
                                    actionsPlacement="inline"
                                    onRowAction={(_, vendor) =>
                                        router.get('/settings/vendors', {
                                            legal_entity:
                                                vendor.legal_entity_id,
                                            q: vendor.number,
                                        })
                                    }
                                    emptyMessage="Belum ada akun vendor untuk pihak ini."
                                    columns={[
                                        {
                                            id: 'number',
                                            header: 'Nomor vendor',
                                            cell: (vendor) => (
                                                <DataTableText>
                                                    {vendor.number}
                                                </DataTableText>
                                            ),
                                        },
                                        {
                                            id: 'company',
                                            header: 'Perusahaan',
                                            cell: (vendor) => (
                                                <DataTableText>
                                                    {vendor.company ?? '—'}
                                                </DataTableText>
                                            ),
                                        },
                                        {
                                            id: 'status',
                                            header: 'Status',
                                            cell: (vendor) => (
                                                <DataTableText>
                                                    {vendor.status === 'active'
                                                        ? 'Aktif'
                                                        : 'Nonaktif'}
                                                </DataTableText>
                                            ),
                                        },
                                    ]}
                                />
                                {metadata.canManageVendors && (
                                    <ActionButton
                                        action="create"
                                        onClick={() =>
                                            router.get('/settings/vendors', {
                                                party: party.id,
                                            })
                                        }
                                    />
                                )}
                            </div>
                        </CollapsibleSection>
                    )}
                </CollapsibleSectionGroup>
            </main>
            <AlertDialog open={confirmArchive} onOpenChange={setConfirmArchive}>
                <AlertDialogContent>
                    <AlertDialogHeader>
                        <AlertDialogTitle>
                            Delete {party.name}?
                        </AlertDialogTitle>
                        <AlertDialogDescription>
                            Pihak akan disembunyikan dari daftar aktif. Pihak
                            yang masih dipakai akun bisnis atau organisasi harus
                            dinonaktifkan melalui Ubah pihak.
                        </AlertDialogDescription>
                    </AlertDialogHeader>
                    <AlertDialogFooter>
                        <AlertDialogCancel disabled={busy}>
                            Batal
                        </AlertDialogCancel>
                        <AlertDialogAction
                            disabled={busy}
                            onClick={() => void archive()}
                        >
                            Delete
                        </AlertDialogAction>
                    </AlertDialogFooter>
                </AlertDialogContent>
            </AlertDialog>
        </div>
    );
}
