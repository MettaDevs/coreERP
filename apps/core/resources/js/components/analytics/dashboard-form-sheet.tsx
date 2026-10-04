import { Button } from '@apperp/ui/button';
import {
    Field,
    FieldDescription,
    FieldError,
    FieldGroup,
    FieldLabel,
} from '@apperp/ui/field';
import { Input } from '@apperp/ui/input';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetFooter,
    SheetHeader,
    SheetTitle,
} from '@apperp/ui/sheet';
import { Switch } from '@apperp/ui/switch';
import { Textarea } from '@apperp/ui/textarea';
import { useState } from 'react';
import { createDashboard, updateDashboard } from '@/lib/analytics/api';
import type {
    DashboardAbilities,
    DashboardDetail,
    DashboardSummary,
} from '@/lib/analytics/types';
import { CoreApiError, toastSaveError } from '@/lib/core-api';

/**
 * Form membuat atau mengubah nama, keterangan, dan pembagian satu dasbor, di `Sheet` sisi kanan supaya
 * daftar atau dasbornya tetap terlihat. Pilihan membagikan hanya tampil bagi yang boleh: dasbor baru dan
 * dasbor miliknya sendiri bagi pemegang hak dasbor bersama, dan dasbor bersama bagi pemegang hak itu untuk
 * berhenti membagikannya — aturan yang sama dengan `DashboardAccess` di server.
 */
export function DashboardFormSheet({
    dashboard,
    abilities,
    onClose,
    onSaved,
}: {
    /** Kosong untuk dasbor baru. */
    dashboard: DashboardSummary | null;
    abilities: DashboardAbilities;
    onClose: () => void;
    onSaved: (dashboard: DashboardDetail) => void;
}) {
    const [name, setName] = useState(dashboard?.name ?? '');
    const [description, setDescription] = useState(
        dashboard?.description ?? '',
    );
    const [shared, setShared] = useState(dashboard?.shared ?? false);
    const [errors, setErrors] = useState<Record<string, string[]>>({});
    const [saving, setSaving] = useState(false);
    const canShare =
        abilities.share &&
        (dashboard === null || dashboard.mine || dashboard.shared);

    const save = async () => {
        setSaving(true);
        setErrors({});

        const input = {
            name: name.trim(),
            description: description.trim() === '' ? null : description.trim(),
            shared,
        };

        try {
            onSaved(
                dashboard === null
                    ? await createDashboard(input)
                    : await updateDashboard(dashboard, {
                          name: input.name,
                          description: input.description,
                          ...(shared !== dashboard.shared ? { shared } : {}),
                      }),
            );
        } catch (caught) {
            if (caught instanceof CoreApiError) {
                setErrors(caught.errors);
            }

            toastSaveError(caught, 'Dasbor belum disimpan.');
        } finally {
            setSaving(false);
        }
    };

    return (
        <Sheet open onOpenChange={(open) => !open && onClose()}>
            <SheetContent side="right" className="w-full gap-0 p-0 sm:max-w-lg">
                <SheetHeader className="border-b px-6 py-5 pr-12">
                    <SheetTitle>
                        {dashboard === null ? 'Dasbor baru' : 'Ubah dasbor'}
                    </SheetTitle>
                    <SheetDescription>
                        {dashboard === null
                            ? 'Kumpulkan angka, grafik, dan tabel yang sering Anda pantau di satu halaman.'
                            : 'Nama dan keterangan tampil di daftar dasbor.'}
                    </SheetDescription>
                </SheetHeader>
                <form
                    id="dashboard-form"
                    className="min-h-0 flex-1 overflow-y-auto px-6 py-5"
                    onSubmit={(event) => {
                        event.preventDefault();
                        void save();
                    }}
                >
                    <FieldGroup>
                        <Field data-invalid={Boolean(errors.name)}>
                            <Input
                                label="Nama"
                                required
                                maxLength={80}
                                value={name}
                                onChange={(event) =>
                                    setName(event.target.value)
                                }
                                aria-invalid={Boolean(errors.name)}
                            />
                            <FieldError>{errors.name?.[0]}</FieldError>
                        </Field>
                        <Field data-invalid={Boolean(errors.description)}>
                            <Textarea
                                label="Keterangan"
                                maxLength={1000}
                                rows={3}
                                value={description}
                                onChange={(event) =>
                                    setDescription(event.target.value)
                                }
                            />
                            <FieldError>{errors.description?.[0]}</FieldError>
                        </Field>
                        {canShare && (
                            <Field data-invalid={Boolean(errors.shared)}>
                                <div className="flex items-center gap-3">
                                    <Switch
                                        id="dashboard-shared"
                                        checked={shared}
                                        onCheckedChange={setShared}
                                    />
                                    <FieldLabel htmlFor="dashboard-shared">
                                        Bagikan ke anggota lain
                                    </FieldLabel>
                                </div>
                                <FieldDescription>
                                    {shared
                                        ? 'Anggota yang boleh melihat dasbor dapat membukanya. Setiap orang melihat angka sesuai aksesnya sendiri, bukan akses Anda.'
                                        : dashboard !== null && !dashboard.mine
                                          ? `Bila tidak dibagikan lagi, dasbor ini hanya terlihat oleh ${dashboard.owner_name ?? 'pemiliknya'}.`
                                          : 'Hanya Anda yang melihat dasbor ini.'}
                                </FieldDescription>
                                <FieldError>{errors.shared?.[0]}</FieldError>
                            </Field>
                        )}
                    </FieldGroup>
                </form>
                <SheetFooter className="border-t px-6 py-4 sm:flex-row sm:justify-end">
                    <Button variant="outline" type="button" onClick={onClose}>
                        Batal
                    </Button>
                    <Button
                        type="submit"
                        form="dashboard-form"
                        disabled={saving || name.trim() === ''}
                    >
                        {dashboard === null ? 'Buat dasbor' : 'Simpan'}
                    </Button>
                </SheetFooter>
            </SheetContent>
        </Sheet>
    );
}
