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
import { createDashboard, updateDashboard } from '@/lib/analytics/api';
import type {
    DashboardAbilities,
    DashboardDetail,
    DashboardSummary,
} from '@/lib/analytics/types';
import { CoreApiError } from '@/lib/core-api';

/**
 * Form membuat atau mengubah nama, keterangan, dan pembagian satu dasbor, di kartu `PageDialog` supaya
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
    const {
        report: saveReport,
        clear: saveClear,
        summary: saveSummary,
        scopeRef: saveScopeRef,
    } = useSaveFeedback(dashboard?.id ?? 'new');
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
        saveClear();
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

            saveReport(caught, 'Dasbor belum disimpan.');
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
                title="Dasbor"
                recordTitle={name.trim() || dashboard?.name || 'Dasbor baru'}
                editing
                recordUrl={
                    dashboard
                        ? `/analytics/dashboards/${dashboard.id}`
                        : undefined
                }
                description={
                    dashboard === null
                        ? 'Kumpulkan angka, grafik, dan tabel yang sering Anda pantau di satu halaman.'
                        : 'Nama dan keterangan tampil di daftar dasbor.'
                }
                busy={saving}
            >
                <PageDialogToolbar>
                    <ActionButton
                        action="save"
                        pending={saving}
                        type="submit"
                        form="dashboard-form"
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
                        id="dashboard-form"

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
                                    maxLength={80}
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
                            {canShare && (
                                <Field
                                    data-error-field="shared"
                                    data-invalid={Boolean(errors.shared)}
                                >
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
                                            : dashboard !== null &&
                                                !dashboard.mine
                                              ? `Bila tidak dibagikan lagi, dasbor ini hanya terlihat oleh ${dashboard.owner_name ?? 'pemiliknya'}.`
                                              : 'Hanya Anda yang melihat dasbor ini.'}
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
