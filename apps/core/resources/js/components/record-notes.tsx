import { ActionButton } from '@apperp/ui/action-button';
import { Button } from '@apperp/ui/button';
import {
    Dialog,
    DialogBody,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@apperp/ui/dialog';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@apperp/ui/dropdown-menu';
import { Textarea } from '@apperp/ui/textarea';
import { Trash2 } from 'lucide-react';
import { Ellipsis, Plus } from 'lucide-react';
import { useEffect, useId, useState } from 'react';
import { FormErrorHeader } from '@/components/form-error-header';
import { useDateTimeFormat } from '@/hooks/use-date-time';
import { apiJson, apiRequest, CoreApiError } from '@/lib/core-api';
import { attachmentError } from '@/lib/record-files';

type Note = {
    id: string;
    content: string;
    version: number;
    created_by_name: string | null;
    created_at: string | null;
};
type NotePage = { data: Note[]; meta: { can_change: boolean } };

export function RecordNotes({
    recordType,
    recordId,
    onCountChange,
}: {
    recordType: string;
    recordId: string;
    onCountChange?: (count: number) => void;
}) {
    const path = `/api/v1/records/${encodeURIComponent(recordType)}/${encodeURIComponent(recordId)}/notes`;
    const [page, setPage] = useState<NotePage | null>(null);
    const [error, setError] = useState<string | null>(null);
    const [saveError, setSaveError] = useState<string | null>(null);
    const [fieldErrors, setFieldErrors] = useState<Record<string, string[]>>(
        {},
    );
    const contentErrorId = `${useId()}-content-error`;
    const [busy, setBusy] = useState(false);
    const [open, setOpen] = useState(false);
    const [editing, setEditing] = useState<Note | null>(null);
    const [content, setContent] = useState('');
    const [reload, setReload] = useState(0);
    const formatDateTime = useDateTimeFormat();

    useEffect(() => {
        const controller = new AbortController();
        apiJson<NotePage>(path, { signal: controller.signal })
            .then((result) => {
                if (!controller.signal.aborted) {
                    setPage(result);
                }
            })
            .catch((caught: unknown) => {
                if (!controller.signal.aborted) {
                    setError(
                        attachmentError(caught, 'Catatan belum dapat dimuat.'),
                    );
                }
            });

        return () => controller.abort();
    }, [path, reload]);

    useEffect(() => {
        if (page) {
            onCountChange?.(page.data.length);
        }
    }, [page, onCountChange]);

    function show(note: Note | null) {
        setEditing(note);
        setFieldErrors({});
        setContent(note?.content ?? '');
        setSaveError(null);
        setOpen(true);
    }

    async function save() {
        setFieldErrors({});

        if (!content.trim()) {
            setFieldErrors({ content: ['Isi catatan sebelum menyimpan.'] });
            setSaveError(
                'Catatan belum disimpan. Isi catatan terlebih dahulu.',
            );

            return;
        }

        setBusy(true);
        setSaveError(null);

        try {
            await apiRequest(editing ? `${path}/${editing.id}` : path, {
                method: editing ? 'PATCH' : 'POST',
                body: JSON.stringify({
                    content: content.trim(),
                    ...(editing ? { version: editing.version } : {}),
                }),
            });
            setOpen(false);
            setReload((value) => value + 1);
        } catch (caught) {
            if (caught instanceof CoreApiError && caught.status < 500) {
                setFieldErrors(caught.errors);
            }

            setSaveError(
                attachmentError(caught, 'Catatan belum dapat disimpan.'),
            );
        } finally {
            setBusy(false);
        }
    }

    async function archive(note: Note) {
        setBusy(true);
        setError(null);

        try {
            await apiRequest(`${path}/${note.id}`, {
                method: 'DELETE',
                body: JSON.stringify({ version: note.version }),
            });
            setPage((current) =>
                current
                    ? {
                          ...current,
                          data: current.data.filter(
                              (item) => item.id !== note.id,
                          ),
                      }
                    : current,
            );
        } catch (caught) {
            setError(
                attachmentError(caught, 'Catatan belum dapat diarsipkan.'),
            );
        } finally {
            setBusy(false);
        }
    }

    return (
        <section className="space-y-3" aria-label="Catatan">
            <div className="flex items-center justify-between gap-2">
                <h3 className="font-semibold">
                    Catatan{page ? ` (${page.data.length})` : ''}
                </h3>
                {page?.meta.can_change && (
                    <Button
                        type="button"
                        variant="ghost"
                        size="icon"
                        aria-label="Tambah catatan"
                        disabled={busy}
                        onClick={() => show(null)}
                    >
                        <Plus />
                    </Button>
                )}
            </div>
            {!open && (
                <FormErrorHeader
                    message={error}
                    operation="Catatan belum dapat diproses"
                />
            )}
            {!page && !error && (
                <p className="text-sm text-muted-foreground">Memuat catatan…</p>
            )}
            {page && !page.data.length && (
                <p className="text-sm text-muted-foreground">
                    Belum ada catatan.
                </p>
            )}
            <div className="max-h-80 space-y-4 overflow-y-auto">
                {page?.data.map((note) => (
                    <div key={note.id} className="flex items-start gap-2">
                        <div className="min-w-0 flex-1">
                            <button
                                type="button"
                                className="block w-full truncate text-left text-sm text-primary hover:underline"
                                onClick={() => show(note)}
                            >
                                {note.content}
                            </button>
                            <p className="text-xs text-muted-foreground">
                                {formatDateTime(note.created_at)} ·{' '}
                                {note.created_by_name ?? 'Pengguna'}
                            </p>
                        </div>
                        {page.meta.can_change && (
                            <DropdownMenu>
                                <DropdownMenuTrigger asChild>
                                    <Button
                                        type="button"
                                        variant="ghost"
                                        size="icon"
                                        aria-label="Tindakan catatan"
                                        disabled={busy}
                                    >
                                        <Ellipsis />
                                    </Button>
                                </DropdownMenuTrigger>
                                <DropdownMenuContent align="end">
                                    <DropdownMenuItem
                                        onSelect={() => show(note)}
                                    >
                                        Ubah
                                    </DropdownMenuItem>
                                    <DropdownMenuItem
                                        onSelect={() => void archive(note)}
                                    >
                                        <Trash2 />
                                        Delete
                                    </DropdownMenuItem>
                                </DropdownMenuContent>
                            </DropdownMenu>
                        )}
                    </div>
                ))}
            </div>
            <Dialog
                open={open}
                onOpenChange={(value) => {
                    if (!busy) {
                        setOpen(value);
                    }
                }}
            >
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>
                            {editing ? 'Catatan' : 'Tambah catatan'}
                        </DialogTitle>
                        <DialogDescription>
                            Catatan untuk data yang sedang dipilih.
                        </DialogDescription>
                    </DialogHeader>
                    <FormErrorHeader
                        message={saveError}
                        errors={fieldErrors}
                        operation="Catatan belum dapat disimpan"
                    />

                    <DialogBody>
                        <Textarea
                            data-error-field="content"
                            aria-invalid={!!fieldErrors.content}
                            aria-describedby={
                                fieldErrors.content ? contentErrorId : undefined
                            }
                            label="Isi catatan"
                            required
                            maxLength={10000}
                            rows={7}
                            value={content}
                            readOnly={busy || !page?.meta.can_change}
                            onChange={(event) => setContent(event.target.value)}
                        />
                        {fieldErrors.content && (
                            <p
                                id={contentErrorId}
                                role="alert"
                                className="text-sm text-destructive"
                            >
                                {fieldErrors.content.join(' ')}
                            </p>
                        )}
                    </DialogBody>
                    <DialogFooter>
                        <DialogClose asChild>
                            <Button
                                type="button"
                                variant="outline"
                                disabled={busy}
                            >
                                Tutup
                            </Button>
                        </DialogClose>
                        {page?.meta.can_change && (
                            <ActionButton
                                action="save"
                                type="button"
                                disabled={busy}
                                onClick={() => void save()}
                                pending={busy}
                            />
                        )}
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </section>
    );
}
