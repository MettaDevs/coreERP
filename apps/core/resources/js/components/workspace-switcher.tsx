import { Button } from '@apperp/ui/button';
import {
    Field,
    FieldDescription,
    FieldError,
    FieldGroup,
    FieldLabel,
} from '@apperp/ui/field';
import { Select } from '@apperp/ui/select';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetFooter,
    SheetHeader,
    SheetTitle,
    SheetTrigger,
} from '@apperp/ui/sheet';
import { usePage } from '@inertiajs/react';
import { Building2 } from 'lucide-react';
import { useRef, useState } from 'react';
import { apiRequest, errorText } from '@/lib/core-api';
import type { Workspace } from '@/types';

type PageProps = {
    auth: { membership: { id: string; tenant_name: string } | null };
    workspace: Workspace;
};

export function WorkspaceSwitcher() {
    const { auth, workspace } = usePage<PageProps>().props;
    const membership = auth.membership;
    const contentRef = useRef<HTMLDivElement>(null);
    const [open, setOpen] = useState(false);
    const [membershipId, setMembershipId] = useState(membership?.id ?? null);
    const [legalEntityId, setLegalEntityId] = useState(
        workspace.active_legal_entity?.id ?? null,
    );
    const [orgUnitId, setOrgUnitId] = useState(
        workspace.active_org_unit?.id ?? null,
    );
    const [saving, setSaving] = useState(false);
    const [error, setError] = useState<string | null>(null);

    if (!membership) {
        return null;
    }

    const changingWorkspace = membershipId !== membership.id;
    const changed =
        changingWorkspace ||
        legalEntityId !== (workspace.active_legal_entity?.id ?? null) ||
        orgUnitId !== (workspace.active_org_unit?.id ?? null);
    const contextLabel = [
        membership.tenant_name,
        workspace.active_legal_entity?.name,
        workspace.active_org_unit?.name,
    ]
        .filter(Boolean)
        .join(' · ');

    const changeOpen = (nextOpen: boolean) => {
        if (saving) {
            return;
        }

        if (nextOpen) {
            setMembershipId(membership.id);
            setLegalEntityId(workspace.active_legal_entity?.id ?? null);
            setOrgUnitId(workspace.active_org_unit?.id ?? null);
            setError(null);
        }

        setOpen(nextOpen);
    };

    const apply = async () => {
        setSaving(true);
        setError(null);

        try {
            await apiRequest('/api/v1/workspace-context', {
                method: 'PUT',
                body: JSON.stringify({
                    membership_id: membershipId,
                    legal_entity_id: changingWorkspace ? null : legalEntityId,
                    org_unit_id: changingWorkspace ? null : orgUnitId,
                }),
            });
            // Muat ulang penuh agar daftar dan isian tidak menyimpan konteks sebelumnya.
            window.location.reload();
        } catch (caught) {
            setError(
                errorText(caught, 'Tempat kerja belum berubah. Coba lagi.'),
            );
            setSaving(false);
        }
    };

    return (
        <Sheet open={open} onOpenChange={changeOpen}>
            <SheetTrigger asChild>
                <Button
                    variant="ghost"
                    size="icon"
                    aria-label={`Pilih tempat kerja: ${contextLabel}`}
                    title={contextLabel}
                >
                    <Building2 aria-hidden="true" />
                </Button>
            </SheetTrigger>
            <SheetContent
                ref={contentRef}
                side="right"
                className="w-full gap-0 p-0 sm:max-w-lg"
                showCloseButton={!saving}
            >
                <SheetHeader className="border-b px-6 py-5 pr-12">
                    <SheetTitle>Tempat kerja</SheetTitle>
                    <SheetDescription>
                        Daftar transaksi mengikuti perusahaan aktif. Unit kerja
                        menjadi pilihan awal transaksi baru. Hak akses Anda
                        tetap berlaku.
                    </SheetDescription>
                </SheetHeader>
                <form
                    className="flex min-h-0 flex-1 flex-col"
                    onSubmit={(event) => {
                        event.preventDefault();
                        void apply();
                    }}
                >
                    <div className="flex-1 overflow-y-auto px-6 py-6">
                        <fieldset disabled={saving}>
                            <FieldGroup>
                                <Field>
                                    {workspace.memberships.length > 1 ? (
                                        <Select
                                            label="Ruang kerja"
                                            value={membershipId}
                                            onValueChange={(value) =>
                                                value && setMembershipId(value)
                                            }
                                            items={workspace.memberships.map(
                                                (item) => ({
                                                    value: item.id,
                                                    label: item.tenant_name,
                                                }),
                                            )}
                                            searchPlaceholder="Cari ruang kerja…"
                                            emptyMessage="Ruang kerja tidak ditemukan."
                                            portalContainer={contentRef}
                                        />
                                    ) : (
                                        <>
                                            <FieldLabel>Ruang kerja</FieldLabel>
                                            <p className="text-sm">
                                                {membership.tenant_name}
                                            </p>
                                        </>
                                    )}
                                </Field>
                                {changingWorkspace ? (
                                    <FieldDescription>
                                        Terapkan untuk membuka ruang kerja ini.
                                        Setelah itu, pilih perusahaan dan unit
                                        yang tersedia di dalamnya.
                                    </FieldDescription>
                                ) : (
                                    <>
                                        <Field>
                                            {workspace.legal_entities.length >
                                            1 ? (
                                                <Select
                                                    label="Perusahaan"
                                                    value={legalEntityId}
                                                    onValueChange={(value) =>
                                                        value &&
                                                        setLegalEntityId(value)
                                                    }
                                                    items={workspace.legal_entities.map(
                                                        (item) => ({
                                                            value: item.id,
                                                            label: item.name,
                                                        }),
                                                    )}
                                                    searchPlaceholder="Cari perusahaan…"
                                                    emptyMessage="Perusahaan tidak ditemukan."
                                                    portalContainer={contentRef}
                                                />
                                            ) : (
                                                <>
                                                    <FieldLabel>
                                                        Perusahaan
                                                    </FieldLabel>
                                                    <p className="text-sm">
                                                        {workspace
                                                            .active_legal_entity
                                                            ?.name ??
                                                            'Belum ada perusahaan yang tersedia.'}
                                                    </p>
                                                </>
                                            )}
                                        </Field>
                                        <Field>
                                            {workspace.org_units.length > 1 ? (
                                                <Select
                                                    label="Unit kerja"
                                                    value={orgUnitId}
                                                    onValueChange={(value) =>
                                                        value &&
                                                        setOrgUnitId(value)
                                                    }
                                                    items={workspace.org_units.map(
                                                        (item) => ({
                                                            value: item.id,
                                                            label: item.name,
                                                        }),
                                                    )}
                                                    searchPlaceholder="Cari unit kerja…"
                                                    emptyMessage="Unit kerja tidak ditemukan."
                                                    portalContainer={contentRef}
                                                />
                                            ) : (
                                                <>
                                                    <FieldLabel>
                                                        Unit kerja
                                                    </FieldLabel>
                                                    <p className="text-sm">
                                                        {workspace
                                                            .active_org_unit
                                                            ?.name ??
                                                            'Belum ada unit kerja yang tersedia.'}
                                                    </p>
                                                </>
                                            )}
                                        </Field>
                                    </>
                                )}
                                <FieldDescription>
                                    Halaman akan dimuat ulang saat diterapkan.
                                    Selesaikan isian yang belum disimpan sebelum
                                    berpindah. Mengganti ruang kerja atau
                                    perusahaan juga mengembalikan tanggal kerja
                                    ke hari ini.
                                </FieldDescription>
                                {error && <FieldError>{error}</FieldError>}
                            </FieldGroup>
                        </fieldset>
                    </div>
                    <SheetFooter className="border-t sm:flex-row sm:justify-end">
                        <Button
                            type="button"
                            variant="outline"
                            disabled={saving}
                            onClick={() => changeOpen(false)}
                        >
                            Batal
                        </Button>
                        <Button
                            type="submit"
                            disabled={saving || !changed || !membershipId}
                        >
                            {saving ? 'Menerapkan…' : 'Terapkan'}
                        </Button>
                    </SheetFooter>
                </form>
            </SheetContent>
        </Sheet>
    );
}
