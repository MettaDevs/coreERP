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
import { createSavedQuery } from '@/lib/analytics/api';
import type {
    AnalyticsQuery,
    DashboardAbilities,
    SavedQuery,
} from '@/lib/analytics/types';
import { CoreApiError, toastSaveError } from '@/lib/core-api';

/**
 * Menyimpan analisis penjelajah sebagai analisis tersimpan (`POST api/v1/analytics/saved-queries`, area 8.5):
 * nama, keterangan, dan pilihan membagikan bagi pemegang hak dasbor bersama. Yang disimpan adalah query-nya,
 * bukan angkanya: setiap orang yang membukanya menghitung ulang dengan aksesnya sendiri.
 */
export function SavedQuerySheet({
    query,
    suggestedName,
    abilities,
    onClose,
    onSaved,
}: {
    query: AnalyticsQuery;
    suggestedName: string;
    abilities: DashboardAbilities;
    onClose: () => void;
    onSaved: (saved: SavedQuery) => void;
}) {
    const [name, setName] = useState(suggestedName);
    const [description, setDescription] = useState('');
    const [shared, setShared] = useState(false);
    const [errors, setErrors] = useState<Record<string, string[]>>({});
    const [saving, setSaving] = useState(false);

    const save = async () => {
        setSaving(true);
        setErrors({});

        try {
            onSaved(
                await createSavedQuery({
                    name: name.trim(),
                    description:
                        description.trim() === '' ? null : description.trim(),
                    shared,
                    query,
                }),
            );
        } catch (caught) {
            if (caught instanceof CoreApiError) {
                setErrors(caught.errors);
            }

            toastSaveError(caught, 'Analisis belum disimpan.');
        } finally {
            setSaving(false);
        }
    };

    return (
        <Sheet open onOpenChange={(open) => !open && onClose()}>
            <SheetContent side="right" className="w-full gap-0 p-0 sm:max-w-lg">
                <SheetHeader className="border-b px-6 py-5 pr-12">
                    <SheetTitle>Simpan analisis</SheetTitle>
                    <SheetDescription>
                        Pilihan data, nilai, pengelompokan, saringan, dan
                        periode disimpan. Angkanya dihitung ulang setiap kali
                        dibuka.
                    </SheetDescription>
                </SheetHeader>
                <form
                    id="saved-query-form"
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
                                maxLength={120}
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
                        {abilities.share && (
                            <Field data-invalid={Boolean(errors.shared)}>
                                <div className="flex items-center gap-3">
                                    <Switch
                                        id="saved-query-shared"
                                        checked={shared}
                                        onCheckedChange={setShared}
                                    />
                                    <FieldLabel htmlFor="saved-query-shared">
                                        Bagikan ke anggota lain
                                    </FieldLabel>
                                </div>
                                <FieldDescription>
                                    {shared
                                        ? 'Anggota yang boleh melihat dasbor dapat membukanya. Setiap orang melihat angka sesuai aksesnya sendiri.'
                                        : 'Hanya Anda yang melihat analisis ini.'}
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
                        form="saved-query-form"
                        disabled={saving || name.trim() === ''}
                    >
                        Simpan
                    </Button>
                </SheetFooter>
            </SheetContent>
        </Sheet>
    );
}
