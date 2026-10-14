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
import { Card, CardContent, CardHeader, CardTitle } from '@apperp/ui/card';
import { DataTable, DataTableText } from '@apperp/ui/data-table';
import { RecordActionBar } from '@apperp/ui/record-action-bar';
import { Link, router, usePage } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import { apiJson, apiRequest, errorText } from '@/lib/core-api';
import {
    OrganizationAddressesSection,
    OrganizationContactsSection,
} from '../../organization/components/address-book-section';
import type { DirectoryMetadata, PartyDetail } from '../types';
import {
    commonProfileFields,
    organizationProfileFields,
    PartyForm,
    personProfileFields,
} from './party-form';
import { PartyRelationships } from './party-relationships';

export function PartyCard({
    partyId,
    metadata,
    onBack,
}: {
    partyId: string;
    metadata: DirectoryMetadata;
    onBack: () => void;
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
    const [reload, setReload] = useState(0);
    useEffect(() => {
        const controller = new AbortController();
        apiJson<{ data: PartyDetail }>(`/api/v1/parties/${partyId}`, {
            signal: controller.signal,
        })
            .then((result) => {
                setParty(result.data);
                setError('');
            })
            .catch((caught) => {
                if (!controller.signal.aborted)
                    setError(errorText(caught, 'Pihak belum dapat dimuat.'));
            });
        return () => controller.abort();
    }, [partyId, reload]);
    useEffect(() => {
        if (!party) return;
        document.getElementById(section)?.scrollIntoView({ block: 'start' });
    }, [section, party?.id]);
    async function archive() {
        if (!party) return;
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
            setConfirmArchive(false);
        }
    }
    if (!party)
        return (
            <div className="space-y-3 p-4">
                <Button variant="outline" onClick={onBack}>
                    Kembali ke daftar
                </Button>
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
    const profileFields = [
        ...commonProfileFields,
        ...(party.type === 'person'
            ? personProfileFields
            : organizationProfileFields),
    ];
    const roleLabel = (code: string) =>
        metadata.referenceValues.role?.find((value) => value.code === code)
            ?.name ?? code;
    return (
        <>
            <RecordActionBar
                title="Buku alamat global"
                trailing={
                    <>
                        <Button variant="outline" onClick={onBack}>
                            Kembali ke daftar
                        </Button>
                        {metadata.canManage && (
                            <>
                                <ActionButton
                                    action="edit"
                                    onClick={() => setEditing(true)}
                                >
                                    Ubah pihak
                                </ActionButton>
                                <ActionButton
                                    action="archive"
                                    disabled={busy}
                                    onClick={() => setConfirmArchive(true)}
                                >
                                    Arsipkan
                                </ActionButton>
                            </>
                        )}
                    </>
                }
            />
            <main className="mx-auto w-full max-w-7xl space-y-4 p-4">
                {error && (
                    <p role="alert" className="text-sm text-destructive">
                        {error}
                    </p>
                )}
                <section id="general" className="scroll-mt-16">
                    <Card>
                        <CardHeader>
                            <CardTitle>
                                {party.number ?? 'Nomor belum diterbitkan'} ·{' '}
                                {party.name}
                            </CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-4">
                            <dl className="grid gap-4 sm:grid-cols-2">
                                <div>
                                    <dt className="text-sm text-muted-foreground">
                                        Jenis pihak
                                    </dt>
                                    <dd>
                                        {metadata.partyTypes.find(
                                            (type) => type.code === party.type,
                                        )?.name ?? party.type}
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
                                        Nama pencarian
                                    </dt>
                                    <dd>{party.search_name}</dd>
                                </div>
                                <div>
                                    <dt className="text-sm text-muted-foreground">
                                        Buku alamat
                                    </dt>
                                    <dd>
                                        {metadata.addressBooks
                                            .filter((book) =>
                                                party.address_book_ids.includes(
                                                    book.id,
                                                ),
                                            )
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
                        </CardContent>
                    </Card>
                </section>
                <section id="addresses" className="scroll-mt-16">
                    <Card>
                        <CardHeader>
                            <CardTitle>Alamat</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <OrganizationAddressesSection
                                organizationId={party.id}
                                resource="parties"
                                countryCode="ID"
                                canManage={metadata.canManage}
                            />
                        </CardContent>
                    </Card>
                </section>
                <section id="contacts" className="scroll-mt-16">
                    <Card>
                        <CardHeader>
                            <CardTitle>Informasi kontak</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <OrganizationContactsSection
                                organizationId={party.id}
                                resource="parties"
                                canManage={metadata.canManage}
                            />
                        </CardContent>
                    </Card>
                </section>
                <section id="relationships" className="scroll-mt-16">
                    <PartyRelationships
                        party={party}
                        metadata={metadata}
                        onChanged={() => setReload((key) => key + 1)}
                    />
                </section>
                <section id="roles" className="scroll-mt-16">
                    <Card>
                        <CardHeader>
                            <CardTitle>Peran bisnis</CardTitle>
                        </CardHeader>
                        <CardContent>
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
                        </CardContent>
                    </Card>
                </section>
                {metadata.canReadVendors && (
                    <section id="vendor" className="scroll-mt-16">
                        <Card>
                            <CardHeader>
                                <CardTitle>Akun vendor</CardTitle>
                            </CardHeader>
                            <CardContent className="space-y-3">
                                <p className="text-sm text-muted-foreground">
                                    Nomor dan status vendor berlaku per
                                    perusahaan. Nama, alamat, serta kontak
                                    memakai pihak yang sama.
                                </p>
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
                                    <Button
                                        variant="outline"
                                        onClick={() =>
                                            router.get('/settings/vendors', {
                                                party: party.id,
                                            })
                                        }
                                    >
                                        Buat akun vendor dari pihak ini
                                    </Button>
                                )}
                            </CardContent>
                        </Card>
                    </section>
                )}
            </main>
            {editing && (
                <PartyForm
                    party={party}
                    metadata={metadata}
                    onClose={() => setEditing(false)}
                    onSaved={() => {
                        setEditing(false);
                        setReload((key) => key + 1);
                        router.reload({ only: ['parties'] });
                    }}
                />
            )}
            <AlertDialog open={confirmArchive} onOpenChange={setConfirmArchive}>
                <AlertDialogContent>
                    <AlertDialogHeader>
                        <AlertDialogTitle>
                            Arsipkan {party.name}?
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
                            Arsipkan
                        </AlertDialogAction>
                    </AlertDialogFooter>
                </AlertDialogContent>
            </AlertDialog>
        </>
    );
}
