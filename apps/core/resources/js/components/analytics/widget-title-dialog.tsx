import { ActionButton } from '@apperp/ui/action-button';
import { DialogClose } from '@apperp/ui/dialog';
import {
    Dialog,
    DialogBody,
    DialogContent,
    DialogDescription,
    DialogToolbar,
    DialogHeader,
    DialogTitle,
} from '@apperp/ui/dialog';
import { Field, FieldError, FieldGroup } from '@apperp/ui/field';
import { Input } from '@apperp/ui/input';
import { Textarea } from '@apperp/ui/textarea';
import { useState } from 'react';
import { useSaveFeedback } from '@/hooks/use-save-feedback';
import { updateWidget } from '@/lib/analytics/api';
import type { DashboardWidget, TextVisual } from '@/lib/analytics/types';
import { CoreApiError } from '@/lib/core-api';

/**
 * Mengganti judul satu widget, dan isi teksnya untuk widget teks. Isi query dan tampilan diubah lewat
 * pembangun widget (area 8); di sini hanya yang dapat disimpan tanpa memeriksa ulang query, sehingga
 * widget yang kolomnya sudah hilang pun tetap dapat diberi nama baru.
 */
export function WidgetTitleDialog({
    widget,
    onClose,
    onSaved,
}: {
    widget: DashboardWidget;
    onClose: () => void;
    onSaved: (widget: DashboardWidget) => void;
}) {
    const isText = widget.type === 'text';
    const {
        report: saveReport,
        clear: saveClear,
        summary: saveSummary,
        scopeRef: saveScopeRef,
    } = useSaveFeedback(widget.id);
    const [title, setTitle] = useState(widget.title);
    const [text, setText] = useState(
        isText ? (widget.visual as TextVisual).text : '',
    );
    const [errors, setErrors] = useState<Record<string, string[]>>({});
    const [fieldError, setFieldError] = useState<string | null>(null);
    const [saving, setSaving] = useState(false);

    const save = async () => {
        saveClear();
        setSaving(true);
        setErrors({});
        setFieldError(null);

        try {
            onSaved(
                await updateWidget(widget, {
                    title: title.trim(),
                    ...(isText ? { visual: { text } } : {}),
                }),
            );
        } catch (caught) {
            if (caught instanceof CoreApiError) {
                setErrors(caught.errors);

                if (caught.code === 'analytics.invalid_visual') {
                    setFieldError(caught.message);
                }
            }

            saveReport(caught, 'Perubahan belum disimpan.');
        } finally {
            setSaving(false);
        }
    };

    return (
        <Dialog open onOpenChange={(open) => !open && onClose()}>
            <DialogContent size="compact">
                <DialogHeader>
                    <DialogTitle>
                        {isText ? 'Ubah teks' : 'Ganti judul'}
                    </DialogTitle>
                    <DialogDescription>
                        {isText
                            ? 'Teks tampil apa adanya, termasuk baris barunya.'
                            : 'Judul tampil di atas bagian ini untuk semua yang membuka dasbor.'}
                    </DialogDescription>
                </DialogHeader>
                <DialogToolbar>
                    <ActionButton
                        action="save"
                        type="submit"
                        form="widget-title-form"
                        disabled={
                            saving ||
                            title.trim() === '' ||
                            (isText && text.trim() === '')
                        }
                    />
                    <DialogClose asChild>
                        <ActionButton action="cancel" type="button" />
                    </DialogClose>
                </DialogToolbar>
                {saveSummary}
                <DialogBody>
                    <form
                        noValidate
                        ref={saveScopeRef}
                        id="widget-title-form"
                        onSubmit={(event) => {
                            event.preventDefault();
                            void save();
                        }}
                    >
                        <FieldGroup>
                            <Field
                                data-error-field="title"
                                data-invalid={Boolean(errors.title)}
                            >
                                <Input
                                    label="Judul"
                                    required
                                    maxLength={120}
                                    value={title}
                                    onChange={(event) =>
                                        setTitle(event.target.value)
                                    }
                                    aria-invalid={Boolean(errors.title)}
                                />
                                <FieldError>{errors.title?.[0]}</FieldError>
                            </Field>
                            {isText && (
                                <Field data-invalid={fieldError !== null}>
                                    <Textarea
                                        label="Teks"
                                        required
                                        maxLength={2000}
                                        rows={6}
                                        value={text}
                                        onChange={(event) =>
                                            setText(event.target.value)
                                        }
                                    />
                                    <FieldError>{fieldError}</FieldError>
                                </Field>
                            )}
                        </FieldGroup>
                    </form>
                </DialogBody>
            </DialogContent>
        </Dialog>
    );
}
