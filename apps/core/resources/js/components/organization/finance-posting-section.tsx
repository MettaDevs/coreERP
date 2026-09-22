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
    AlertDialogTrigger,
} from '@apperp/ui/alert-dialog';
import { Badge } from '@apperp/ui/badge';
import { Button } from '@apperp/ui/button';
import {
    Field,
    FieldDescription,
    FieldError,
    FieldLabel,
} from '@apperp/ui/field';
import { Input } from '@apperp/ui/input';
import { NativeSelect, NativeSelectOption } from '@apperp/ui/native-select';
import { Switch } from '@apperp/ui/switch';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@apperp/ui/table';
import { Plus } from 'lucide-react';
import { useEffect, useState } from 'react';
import { toast } from 'sonner';
import { apiJson, apiRequest, CoreApiError, errorText } from '@/lib/core-api';

/**
 * Setelan feed posting finance satu entitas legal: aktif atau tidak, tanggal cutover, dan riwayat
 * kebijakan jurnal perolehan dengan tanggal berlakunya (K-10, K-16).
 */

type Mode = 'direct_payable' | 'clearing';
type ModeRow = {
    id: string;
    mode: Mode;
    effective_from: string;
    removable: boolean;
};
type Setting = {
    enabled: boolean;
    cutover_date: string | null;
    current_mode: Mode;
    default_mode: Mode;
    modes: ModeRow[];
};

const MODE: Record<Mode, { label: string; description: string }> = {
    direct_payable: {
        label: 'Langsung ke hutang',
        description:
            'Penerimaan aset langsung menjadi hutang ke vendor. Faktur di aplikasi finance dibuat dari posting ini, tanpa jurnal kedua.',
    },
    clearing: {
        label: 'Lewat akun perantara',
        description:
            'Penerimaan dicatat ke akun perantara. Faktur di aplikasi finance yang memindahkannya ke hutang.',
    },
};

function tanggal(value: string | null): string {
    if (!value) {
        return '—';
    }

    return new Intl.DateTimeFormat('id-ID', { dateStyle: 'medium' }).format(
        new Date(`${value}T00:00:00`),
    );
}

export function FinancePostingSection({
    organizationId,
    canManage,
}: {
    organizationId: string;
    canManage: boolean;
}) {
    const base = `/api/v1/organizations/${organizationId}/finance-posting`;
    const [setting, setSetting] = useState<Setting | null>(null);
    const [loadError, setLoadError] = useState('');
    const [enabled, setEnabled] = useState(false);
    const [cutover, setCutover] = useState('');
    const [saveErrors, setSaveErrors] = useState<Record<string, string[]>>({});
    const [saving, setSaving] = useState(false);
    const [newMode, setNewMode] = useState<Mode>('clearing');
    const [newFrom, setNewFrom] = useState('');
    const [modeErrors, setModeErrors] = useState<Record<string, string[]>>({});
    const [addingMode, setAddingMode] = useState(false);

    const apply = (next: Setting) => {
        setSetting(next);
        setEnabled(next.enabled);
        setCutover(next.cutover_date ?? '');
    };

    useEffect(() => {
        let cancelled = false;
        apiJson<{ data: Setting }>(base)
            .then((result) => {
                if (!cancelled) {
                    apply(result.data);
                }
            })
            .catch((caught: unknown) => {
                if (!cancelled) {
                    setLoadError(
                        errorText(
                            caught,
                            'Setelan posting belum dapat dimuat.',
                        ),
                    );
                }
            });

        return () => {
            cancelled = true;
        };
    }, [base]);

    const save = async () => {
        setSaving(true);
        setSaveErrors({});

        try {
            const result = await apiJson<{ data: Setting }>(base, {
                method: 'PUT',
                body: JSON.stringify({
                    enabled,
                    cutover_date: cutover || null,
                }),
            });
            apply(result.data);
            toast.success('Setelan posting disimpan.');
        } catch (caught) {
            if (caught instanceof CoreApiError) {
                setSaveErrors(caught.errors);
            }

            toast.error(errorText(caught, 'Setelan posting belum disimpan.'));
        } finally {
            setSaving(false);
        }
    };

    const addMode = async () => {
        setAddingMode(true);
        setModeErrors({});

        try {
            const result = await apiJson<{ data: Setting }>(
                `${base}/settlement-modes`,
                {
                    method: 'POST',
                    body: JSON.stringify({
                        mode: newMode,
                        effective_from: newFrom,
                    }),
                },
            );
            apply(result.data);
            setNewFrom('');
            toast.success('Mode penyelesaian ditambahkan.');
        } catch (caught) {
            if (caught instanceof CoreApiError) {
                setModeErrors(caught.errors);
            }

            toast.error(errorText(caught, 'Mode belum dapat ditambahkan.'));
        } finally {
            setAddingMode(false);
        }
    };

    const removeMode = async (row: ModeRow) => {
        try {
            await apiRequest(`${base}/settlement-modes/${row.id}`, {
                method: 'DELETE',
            });
            const result = await apiJson<{ data: Setting }>(base);
            apply(result.data);
            toast.success('Mode penyelesaian dihapus.');
        } catch (caught) {
            toast.error(errorText(caught, 'Mode belum dapat dihapus.'));
        }
    };

    if (loadError) {
        return <p className="text-sm text-destructive">{loadError}</p>;
    }

    if (!setting) {
        return (
            <p className="text-sm text-muted-foreground">
                Memuat setelan posting…
            </p>
        );
    }

    return (
        <div className="space-y-6">
            <div className="space-y-4">
                <div className="flex flex-wrap items-center gap-2 text-sm">
                    <Badge variant={setting.enabled ? 'default' : 'outline'}>
                        {setting.enabled ? 'Feed aktif' : 'Feed tidak aktif'}
                    </Badge>
                    <span className="text-muted-foreground">
                        Cutover {tanggal(setting.cutover_date)} · Mode hari ini:{' '}
                        {MODE[setting.current_mode].label}
                    </span>
                </div>
                <p className="text-sm text-muted-foreground">
                    Selama feed tidak aktif, posting dari entitas ini tidak
                    dikirim ke aplikasi finance. Posting bertanggal sebelum
                    cutover juga tidak dikirim, karena riwayat itu sudah
                    dijurnal manual.
                </p>

                {canManage && (
                    <div className="grid gap-4 rounded-lg border p-4 md:grid-cols-2">
                        <Field orientation="horizontal">
                            <Switch
                                id={`feed-${organizationId}`}
                                checked={enabled}
                                onCheckedChange={setEnabled}
                            />
                            <FieldLabel htmlFor={`feed-${organizationId}`}>
                                Kirim posting ke aplikasi finance
                            </FieldLabel>
                        </Field>
                        <Field data-invalid={Boolean(saveErrors.cutover_date)}>
                            <Input
                                type="date"
                                label="Tanggal cutover"
                                value={cutover}
                                required={enabled}
                                onChange={(event) =>
                                    setCutover(event.target.value)
                                }
                                aria-invalid={Boolean(saveErrors.cutover_date)}
                            />
                            <FieldDescription>
                                Posting mulai dikirim untuk transaksi pada atau
                                sesudah tanggal ini.
                            </FieldDescription>
                            <FieldError>
                                {saveErrors.cutover_date?.[0]}
                            </FieldError>
                        </Field>
                        <div className="md:col-span-2">
                            <Button
                                type="button"
                                size="sm"
                                onClick={save}
                                disabled={saving}
                            >
                                Simpan setelan posting
                            </Button>
                        </div>
                    </div>
                )}
            </div>

            <div className="space-y-3">
                <div>
                    <h4 className="text-sm font-semibold">
                        Kebijakan jurnal perolehan
                    </h4>
                    <p className="text-sm text-muted-foreground">
                        Tanpa riwayat, entitas ini memakai{' '}
                        {MODE[setting.default_mode].label.toLowerCase()}.
                        Koreksi atas penerimaan lama selalu mengikuti kebijakan
                        yang berlaku saat penerimaan itu, bukan kebijakan hari
                        ini.
                    </p>
                </div>

                {setting.modes.length === 0 ? (
                    <p className="rounded-lg border border-dashed p-4 text-sm text-muted-foreground">
                        Belum ada riwayat kebijakan.
                    </p>
                ) : (
                    <div className="overflow-x-auto rounded-lg border">
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>Berlaku mulai</TableHead>
                                    <TableHead>Kebijakan</TableHead>
                                    {canManage && (
                                        <TableHead className="w-28 text-right">
                                            Aksi
                                        </TableHead>
                                    )}
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {setting.modes.map((row) => (
                                    <TableRow key={row.id}>
                                        <TableCell>
                                            {tanggal(row.effective_from)}
                                        </TableCell>
                                        <TableCell>
                                            <span className="font-medium">
                                                {MODE[row.mode].label}
                                            </span>
                                            <span className="block text-xs text-muted-foreground">
                                                {MODE[row.mode].description}
                                            </span>
                                        </TableCell>
                                        {canManage && (
                                            <TableCell className="text-right">
                                                {row.removable ? (
                                                    <AlertDialog>
                                                        <AlertDialogTrigger
                                                            asChild
                                                        >
                                                            <ActionButton
                                                                action="delete"
                                                                type="button"
                                                                size="sm"
                                                            >
                                                                Hapus
                                                            </ActionButton>
                                                        </AlertDialogTrigger>
                                                        <AlertDialogContent>
                                                            <AlertDialogHeader>
                                                                <AlertDialogTitle>
                                                                    Hapus
                                                                    kebijakan
                                                                    yang belum
                                                                    berlaku?
                                                                </AlertDialogTitle>
                                                                <AlertDialogDescription>
                                                                    Kebijakan{' '}
                                                                    {
                                                                        MODE[
                                                                            row
                                                                                .mode
                                                                        ].label
                                                                    }{' '}
                                                                    mulai{' '}
                                                                    {tanggal(
                                                                        row.effective_from,
                                                                    )}{' '}
                                                                    dihapus.
                                                                    Kebijakan
                                                                    sebelumnya
                                                                    tetap
                                                                    berlaku.
                                                                </AlertDialogDescription>
                                                            </AlertDialogHeader>
                                                            <AlertDialogFooter>
                                                                <AlertDialogCancel>
                                                                    Batal
                                                                </AlertDialogCancel>
                                                                <AlertDialogAction
                                                                    onClick={() =>
                                                                        removeMode(
                                                                            row,
                                                                        )
                                                                    }
                                                                >
                                                                    Hapus
                                                                </AlertDialogAction>
                                                            </AlertDialogFooter>
                                                        </AlertDialogContent>
                                                    </AlertDialog>
                                                ) : (
                                                    <span className="text-xs text-muted-foreground">
                                                        Sudah berlaku
                                                    </span>
                                                )}
                                            </TableCell>
                                        )}
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    </div>
                )}

                {canManage && (
                    <div className="grid gap-4 rounded-lg border p-4 md:grid-cols-[1fr_1fr_auto] md:items-start">
                        <Field data-invalid={Boolean(modeErrors.mode)}>
                            <NativeSelect
                                label="Kebijakan baru"
                                value={newMode}
                                onChange={(event) =>
                                    setNewMode(event.target.value as Mode)
                                }
                            >
                                {(Object.keys(MODE) as Mode[]).map((mode) => (
                                    <NativeSelectOption key={mode} value={mode}>
                                        {MODE[mode].label}
                                    </NativeSelectOption>
                                ))}
                            </NativeSelect>
                            <FieldError>{modeErrors.mode?.[0]}</FieldError>
                        </Field>
                        <Field
                            data-invalid={Boolean(modeErrors.effective_from)}
                        >
                            <Input
                                type="date"
                                label="Berlaku mulai"
                                value={newFrom}
                                required
                                onChange={(event) =>
                                    setNewFrom(event.target.value)
                                }
                                aria-invalid={Boolean(
                                    modeErrors.effective_from,
                                )}
                            />
                            <FieldError>
                                {modeErrors.effective_from?.[0]}
                            </FieldError>
                        </Field>
                        <Button
                            type="button"
                            size="sm"
                            className="md:mt-1"
                            onClick={addMode}
                            disabled={addingMode || newFrom === ''}
                        >
                            <Plus className="mr-1.5 size-3.5" />
                            Tambah kebijakan
                        </Button>
                    </div>
                )}
            </div>
        </div>
    );
}
