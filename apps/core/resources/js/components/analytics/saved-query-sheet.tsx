import { ActionButton } from '@apperp/ui/action-button';

import {
    Field,
    FieldDescription,
    FieldError,
    FieldGroup,
    FieldLabel,
} from '@apperp/ui/field';
import { Input } from '@apperp/ui/input';
import {
    PageDialog,
    PageDialogContent,
    PageDialogToolbar,
    PageDialogBody,
} from '@apperp/ui/page-dialog';

import { Switch } from '@apperp/ui/switch';
import { Textarea } from '@apperp/ui/textarea';
import { useState } from 'react';
import { useSaveFeedback } from '@/hooks/use-save-feedback';
import { createSavedQuery } from '@/lib/analytics/api';
import type {
    AnalyticsQuery,
    DashboardAbilities,
    SavedQuery,
} from '@/lib/analytics/types';
import { CoreApiError } from '@/lib/core-api';

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
    const {
        report: saveReport,
        clear: saveClear,
        summary: saveSummary,
        scopeRef: saveScopeRef,
    } = useSaveFeedback();
    const [name, setName] = useState(suggestedName);
    const [description, setDescription] = useState('');
    const [shared, setShared] = useState(false);
    const [errors, setErrors] = useState<Record<string, string[]>>({});
    const [saving, setSaving] = useState(false);

    const save = async () => {
        saveClear();
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

            saveReport(caught, 'Analisis belum disimpan.');
        } finally {
            setSaving(false);
        }
    };

    return (
        <PageDialog
            open
            onOpenChange={(open) => {
                if (!open && !saving) {
                    onClose();
                }
            }}
        >
            <PageDialogContent
                onEscapeKeyDown={(event) => {
                    if (saving) {
                        event.preventDefault();
                    }
                }}
                title="Analisis tersimpan"
                recordTitle={name.trim() || 'Analisis baru'}
                editing
                description={
                    'Pilihan data, nilai, pengelompokan, saringan, dan\n                        periode disimpan. Angkanya dihitung ulang setiap kali\n                        dibuka.'
                }
                busy={saving}
            >
                <PageDialogToolbar>
                    <ActionButton
                        action="save"
                        pending={saving}
                        type="submit"
                        form="saved-query-form"
                        disabled={saving || name.trim() === ''}
                    />
                    <ActionButton
                        action="cancel"
                        type="button"
                        disabled={saving}
                        onClick={onClose}
                    />
                </PageDialogToolbar>
                {saveSummary}
                <PageDialogBody>
                    <form
                        noValidate
                        ref={saveScopeRef}
                        id="saved-query-form"

                        onSubmit={(event) => {
                            event.preventDefault();
                            void save();
                        }}
                    >
                        <FieldGroup>
                            <Field
                                data-error-field="name"
                                data-invalid={Boolean(errors.name)}
                            >
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
                            <Field
                                data-error-field="description"
                                data-invalid={Boolean(errors.description)}
                            >
                                <Textarea
                                    label="Keterangan"
                                    maxLength={1000}
                                    rows={3}
                                    value={description}
                                    onChange={(event) =>
                                        setDescription(event.target.value)
                                    }
                                />
                                <FieldError>
                                    {errors.description?.[0]}
                                </FieldError>
                            </Field>
                            {abilities.share && (
                                <Field
                                    data-error-field="shared"
                                    data-invalid={Boolean(errors.shared)}
                                >
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
                                    <FieldError>
                                        {errors.shared?.[0]}
                                    </FieldError>
                                </Field>
                            )}
                        </FieldGroup>
                    </form>
                </PageDialogBody>
            </PageDialogContent>
        </PageDialog>
    );
}
