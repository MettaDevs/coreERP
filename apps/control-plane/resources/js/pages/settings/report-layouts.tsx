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
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@apperp/ui/card';
import {
    CollapsibleSection,
    CollapsibleSectionGroup,
} from '@apperp/ui/collapsible-section';
import { DataTable } from '@apperp/ui/data-table';
import type {
    DataTableColumn,
    DataTableRowAction,
} from '@apperp/ui/data-table';
import { Field, FieldDescription, FieldLabel } from '@apperp/ui/field';
import { Input } from '@apperp/ui/input';
import { NativeSelect, NativeSelectOption } from '@apperp/ui/native-select';
import { RadioGroup, RadioGroupItem } from '@apperp/ui/radio-group';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetFooter,
    SheetHeader,
    SheetTitle,
} from '@apperp/ui/sheet';
import { Textarea } from '@apperp/ui/textarea';
import { Head } from '@inertiajs/react';
import { useCallback, useEffect, useRef, useState } from 'react';
import { toast } from 'sonner';
import Heading from '@/components/heading';
import type { Layout, Report, ReportField } from '@/lib/reports';
import {
    deleteLayout,
    downloadLayout,
    listFields,
    listLayouts,
    replaceLayoutFile,
    setDefaultLayout,
    uploadLayout,
    formatBytes,
    formatTime,
} from '@/lib/reports';
import type { BreadcrumbItem } from '@/types/navigation';

type Props = {
    canManage: boolean;
    reports: Report[];
    legalEntity: { id: string; name: string } | null;
};

type Scope = 'tenant' | 'legal_entity';

function warnUnknown(list: string[]) {
    if (list.length) {
        toast.warning(
            `Placeholder tidak dikenal dan akan dicetak kosong: ${list.map((p) => `\${${p}}`).join(', ')}`,
            { duration: 10_000 },
        );
    }
}

/**
 * Halaman Layout laporan untuk semua app, padanan Report Layouts dan Report Selections
 * Business Central dalam satu layar: pilih laporan, lihat layout bawaan dan unggahan,
 * unduh untuk disunting, unggah versi baru, dan tetapkan default per legal entity atau
 * seluruh perusahaan.
 *
 * Layout bawaan tidak pernah disunting di tempat — diunduh, diubah di Word/Excel, lalu
 * diunggah sebagai layout baru — supaya pembaruan app tetap memperbarui layout bawaan
 * tanpa menimpa milik tenant.
 */
export default function ReportLayouts({
    canManage,
    reports,
    legalEntity,
}: Props) {
    const [code, setCode] = useState(reports[0]?.code ?? '');
    const [layouts, setLayouts] = useState<Layout[]>([]);
    const [defaultRef, setDefaultRef] = useState('');
    const [fields, setFields] = useState<ReportField[]>([]);
    const [error, setError] = useState('');
    const [uploading, setUploading] = useState(false);
    const [removing, setRemoving] = useState<Layout | null>(null);
    const replaceInput = useRef<HTMLInputElement>(null);
    const [replacing, setReplacing] = useState<Layout | null>(null);

    const load = useCallback(async () => {
        if (!code) {
            return;
        }

        try {
            const [result, definition] = await Promise.all([
                listLayouts(code),
                listFields(code),
            ]);
            setLayouts(result.data);
            setDefaultRef(result.meta.default_ref);
            setFields(definition.data);
            setError('');
        } catch (caught) {
            setError(
                caught instanceof Error && caught.message
                    ? caught.message
                    : 'Layout belum dapat dimuat.',
            );
        }
    }, [code]);

    useEffect(() => {
        // Dimuat di microtask supaya effect tidak memanggil setState secara sinkron.
        void Promise.resolve().then(load);
    }, [load]);

    const makeDefault = async (layout: Layout | null, scope: Scope) => {
        try {
            const result = await setDefaultLayout(
                code,
                layout?.ref ?? null,
                scope,
            );
            setLayouts(result.data);
            setDefaultRef(result.meta.default_ref);
            toast.success(
                layout
                    ? `${layout.name} dijadikan default ${scope === 'tenant' ? 'seluruh perusahaan' : (legalEntity?.name ?? 'entitas legal aktif')}.`
                    : 'Pilihan default dihapus; laporan kembali memakai layout bawaan.',
            );
        } catch (caught) {
            toast.error(
                caught instanceof Error && caught.message
                    ? caught.message
                    : 'Default belum dapat diubah.',
            );
        }
    };

    const confirmRemove = async () => {
        if (!removing) {
            return;
        }

        try {
            await deleteLayout(code, removing.ref);
            toast.success(`${removing.name} dihapus.`);
            setRemoving(null);
            await load();
        } catch (caught) {
            toast.error(
                caught instanceof Error && caught.message
                    ? caught.message
                    : 'Layout belum dapat dihapus.',
            );
        }
    };

    const replaceFile = async (file: File | undefined) => {
        if (!file || !replacing) {
            return;
        }

        try {
            const result = await replaceLayoutFile(code, replacing.ref, file);
            warnUnknown(result.meta.unknown_placeholders);
            toast.success(`Berkas ${replacing.name} diganti.`);
            await load();
        } catch (caught) {
            toast.error(
                caught instanceof Error && caught.message
                    ? caught.message
                    : 'Berkas belum dapat diganti.',
            );
        } finally {
            setReplacing(null);

            if (replaceInput.current) {
                replaceInput.current.value = '';
            }
        }
    };

    const actionsFor = (layout: Layout): DataTableRowAction[] => {
        const list: DataTableRowAction[] = [];

        if (canManage) {
            list.push({ id: 'download', label: 'Unduh berkas' });

            if (legalEntity) {
                list.push({
                    id: 'default-le',
                    label: `Jadikan default ${legalEntity.name}`,
                });
            }

            list.push({
                id: 'default-tenant',
                label: 'Jadikan default seluruh perusahaan',
            });

            if (layout.source === 'uploaded') {
                list.push({ id: 'replace', label: 'Ganti berkas' });
                list.push({
                    id: 'delete',
                    label: 'Hapus',
                    destructive: true,
                    separatorBefore: true,
                });
            }
        }

        return list;
    };

    const runAction = (id: string, layout: Layout) => {
        if (!actionsFor(layout).some((action) => action.id === id)) {
            toast.error('Tindakan ini tidak berlaku untuk layout bawaan.');

            return;
        }

        if (id === 'download') {
            void downloadLayout(code, layout).catch((caught: Error) =>
                toast.error(caught.message || 'Berkas belum dapat diunduh.'),
            );
        } else if (id === 'default-le') {
            void makeDefault(layout, 'legal_entity');
        } else if (id === 'default-tenant') {
            void makeDefault(layout, 'tenant');
        } else if (id === 'replace') {
            setReplacing(layout);
            replaceInput.current?.click();
        } else if (id === 'delete') {
            setRemoving(layout);
        }
    };

    const columns: DataTableColumn<Layout>[] = [
        {
            id: 'name',
            header: 'Layout',
            cell: (layout) => (
                <div className="min-w-0">
                    <p className="truncate font-medium">
                        {layout.name}
                        {layout.is_default && (
                            <Badge className="ml-2" variant="secondary">
                                Default
                            </Badge>
                        )}
                    </p>
                    {layout.description && (
                        <p className="truncate text-xs text-muted-foreground">
                            {layout.description}
                        </p>
                    )}
                </div>
            ),
            sortValue: (layout) => layout.name,
            width: 320,
        },
        {
            id: 'format',
            header: 'Format',
            cell: (layout) => (layout.format === 'docx' ? 'Word' : 'Excel'),
            width: 90,
        },
        {
            id: 'source',
            header: 'Sumber',
            cell: (layout) =>
                layout.source === 'builtin' ? 'Bawaan aplikasi' : 'Unggahan',
            width: 140,
        },
        {
            id: 'scope',
            header: 'Berlaku untuk',
            cell: (layout) =>
                layout.source === 'builtin' || !layout.legal_entity_id
                    ? 'Seluruh perusahaan'
                    : (legalEntity?.name ?? 'Entitas legal aktif'),
            width: 180,
        },
        {
            id: 'size',
            header: 'Ukuran',
            cell: (layout) => formatBytes(layout.file_size),
            align: 'right',
            width: 90,
        },
        {
            id: 'created',
            header: 'Diunggah',
            cell: (layout) => formatTime(layout.created_at),
            width: 170,
        },
    ];

    const report = reports.find((item) => item.code === code);
    const rowActions = layouts.length
        ? actionsFor(
              layouts.find((layout) => layout.source === 'uploaded') ??
                  layouts[0],
          )
        : [];

    return (
        <>
            <Head title="Layout laporan" />
            <main className="mx-auto flex w-full max-w-7xl min-w-0 flex-col gap-6 p-6">
                <div className="flex flex-wrap items-start justify-between gap-4">
                    <Heading
                        title="Layout laporan"
                        description="Tampilan dokumen cetak dan ekspor dari semua aplikasi, per laporan dan per entitas legal."
                    />
                    {canManage && code && (
                        <ActionButton
                            action="create"
                            type="button"
                            onClick={() => setUploading(true)}
                        >
                            Unggah layout
                        </ActionButton>
                    )}
                </div>
                {error && <p className="text-sm text-destructive">{error}</p>}
                {reports.length === 0 ? (
                    <Card>
                        <CardContent className="py-8 text-center text-sm text-muted-foreground">
                            Belum ada aplikasi terpasang yang menyediakan
                            laporan.
                        </CardContent>
                    </Card>
                ) : (
                    <Card>
                        <CardHeader>
                            <CardTitle>Layout per laporan</CardTitle>
                            <CardDescription>
                                Unduh layout bawaan, ubah tampilannya di Word
                                atau Excel tanpa mengganti placeholder, lalu
                                unggah sebagai layout baru dan jadikan default.
                                Layout bawaan ikut diperbarui saat aplikasi
                                diperbarui; layout unggahan tetap milik Anda.
                            </CardDescription>
                        </CardHeader>
                        <CardContent className="space-y-4 px-0">
                            <div className="flex flex-col gap-3 px-6 sm:flex-row">
                                <div className="w-full sm:w-80">
                                    <NativeSelect
                                        label="Laporan"
                                        value={code}
                                        onChange={(event) =>
                                            setCode(event.target.value)
                                        }
                                    >
                                        {reports.map((item) => (
                                            <NativeSelectOption
                                                key={item.code}
                                                value={item.code}
                                            >
                                                {item.app_name} · {item.name}
                                            </NativeSelectOption>
                                        ))}
                                    </NativeSelect>
                                </div>
                            </div>
                            <DataTable
                                columns={columns}
                                data={layouts}
                                getRowKey={(layout) => layout.ref}
                                actions={rowActions}
                                getRowLabel={(layout) => layout.name}
                                onRowAction={runAction}
                                emptyMessage="Belum ada layout untuk laporan ini."
                            />
                            {defaultRef && canManage && (
                                <div className="px-6">
                                    <Button
                                        type="button"
                                        variant="ghost"
                                        size="sm"
                                        onClick={() =>
                                            void makeDefault(
                                                null,
                                                legalEntity
                                                    ? 'legal_entity'
                                                    : 'tenant',
                                            )
                                        }
                                    >
                                        Kembalikan default ke layout bawaan
                                    </Button>
                                </div>
                            )}
                        </CardContent>
                    </Card>
                )}

                {report && (
                    <CollapsibleSectionGroup>
                        <CollapsibleSection
                            value="placeholders"
                            title="Placeholder yang tersedia"
                            summary={`${fields.length} placeholder untuk ${report.name}`}
                        >
                            <p className="mb-3 text-sm text-muted-foreground">
                                Tulis placeholder persis seperti ini di dalam
                                layout. Kolom yang punya nama tabel diulang per
                                baris: letakkan di satu baris tabel Word, atau
                                satu baris lembar Excel, dan baris itu akan
                                digandakan.
                            </p>
                            <div className="grid gap-x-6 gap-y-1 text-sm sm:grid-cols-2 lg:grid-cols-3">
                                {fields.map((field) => (
                                    <div
                                        key={field.key}
                                        className="flex items-baseline gap-2"
                                    >
                                        <code className="rounded bg-muted px-1 text-xs">{`\${${field.key}}`}</code>
                                        <span className="truncate text-muted-foreground">
                                            {field.label}
                                        </span>
                                    </div>
                                ))}
                            </div>
                        </CollapsibleSection>
                    </CollapsibleSectionGroup>
                )}

                <input
                    ref={replaceInput}
                    type="file"
                    accept=".docx,.xlsx"
                    className="hidden"
                    aria-hidden
                    onChange={(event) =>
                        void replaceFile(event.target.files?.[0])
                    }
                />

                {uploading && code && (
                    <UploadSheet
                        code={code}
                        legalEntity={legalEntity}
                        onClose={() => setUploading(false)}
                        onSaved={() => {
                            setUploading(false);
                            void load();
                        }}
                    />
                )}

                <AlertDialog
                    open={removing !== null}
                    onOpenChange={(open) => !open && setRemoving(null)}
                >
                    <AlertDialogContent>
                        <AlertDialogHeader>
                            <AlertDialogTitle>
                                Hapus layout {removing?.name}?
                            </AlertDialogTitle>
                            <AlertDialogDescription>
                                Berkasnya dihapus permanen. Laporan yang
                                memakainya sebagai default kembali ke layout
                                bawaan.
                            </AlertDialogDescription>
                        </AlertDialogHeader>
                        <AlertDialogFooter>
                            <AlertDialogCancel>Batal</AlertDialogCancel>
                            <AlertDialogAction
                                onClick={() => void confirmRemove()}
                            >
                                Hapus
                            </AlertDialogAction>
                        </AlertDialogFooter>
                    </AlertDialogContent>
                </AlertDialog>
            </main>
        </>
    );
}

function UploadSheet({
    code,
    legalEntity,
    onClose,
    onSaved,
}: {
    code: string;
    legalEntity: { id: string; name: string } | null;
    onClose: () => void;
    onSaved: () => void;
}) {
    const [file, setFile] = useState<File | null>(null);
    const [name, setName] = useState('');
    const [description, setDescription] = useState('');
    const [scope, setScope] = useState<Scope>(
        legalEntity ? 'legal_entity' : 'tenant',
    );
    const [saving, setSaving] = useState(false);

    const save = async () => {
        if (!file) {
            toast.error('Pilih berkas layout lebih dahulu.');

            return;
        }

        setSaving(true);

        try {
            const result = await uploadLayout(code, {
                file,
                name,
                description,
                scope,
            });
            warnUnknown(result.meta.unknown_placeholders);
            toast.success(`Layout ${result.data.name} tersimpan.`);
            onSaved();
        } catch (caught) {
            toast.error(
                caught instanceof Error && caught.message
                    ? caught.message
                    : 'Layout belum dapat diunggah.',
            );
        } finally {
            setSaving(false);
        }
    };

    return (
        <Sheet open onOpenChange={(open) => !open && onClose()}>
            <SheetContent side="right" className="flex flex-col">
                <SheetHeader>
                    <SheetTitle>Unggah layout</SheetTitle>
                    <SheetDescription>
                        Berkas Word (.docx) atau Excel (.xlsx) tanpa makro,
                        paling besar 5 MB. Placeholder yang tidak dikenal tidak
                        ditolak, tetapi dicetak kosong.
                    </SheetDescription>
                </SheetHeader>
                <div className="flex-1 space-y-4 overflow-y-auto px-4">
                    <Field>
                        <FieldLabel htmlFor="layout-file">
                            Berkas layout
                        </FieldLabel>
                        <Input
                            id="layout-file"
                            type="file"
                            accept=".docx,.xlsx"
                            required
                            onChange={(event) => {
                                const chosen = event.target.files?.[0] ?? null;
                                setFile(chosen);

                                if (chosen && !name) {
                                    setName(
                                        chosen.name.replace(
                                            /\.(docx|xlsx)$/i,
                                            '',
                                        ),
                                    );
                                }
                            }}
                        />
                    </Field>
                    <Field>
                        <Input
                            label="Nama layout"
                            required
                            value={name}
                            onChange={(event) => setName(event.target.value)}
                        />
                    </Field>
                    <Field>
                        <Textarea
                            label="Keterangan"
                            value={description}
                            onChange={(event) =>
                                setDescription(event.target.value)
                            }
                        />
                    </Field>
                    <Field>
                        <FieldLabel>Berlaku untuk</FieldLabel>
                        <RadioGroup
                            value={scope}
                            onValueChange={(value) => setScope(value as Scope)}
                            className="space-y-1"
                        >
                            {legalEntity && (
                                <label className="flex items-center gap-2 text-sm">
                                    <RadioGroupItem value="legal_entity" />
                                    {legalEntity.name} saja
                                </label>
                            )}
                            <label className="flex items-center gap-2 text-sm">
                                <RadioGroupItem value="tenant" />
                                Seluruh perusahaan
                            </label>
                        </RadioGroup>
                        <FieldDescription>
                            Pilih entitas legal bila layout memuat kop atau
                            tanda tangan perusahaan tertentu.
                        </FieldDescription>
                    </Field>
                </div>
                <SheetFooter>
                    <Button
                        type="button"
                        disabled={saving || !name || !file}
                        onClick={() => void save()}
                    >
                        {saving ? 'Mengunggah…' : 'Simpan'}
                    </Button>
                    <Button type="button" variant="outline" onClick={onClose}>
                        Batal
                    </Button>
                </SheetFooter>
            </SheetContent>
        </Sheet>
    );
}

ReportLayouts.layout = {
    breadcrumbs: [
        { title: 'Settings', href: '/settings/profile' },
        { title: 'Layout laporan', href: '/settings/report-layouts' },
    ] satisfies BreadcrumbItem[],
};
