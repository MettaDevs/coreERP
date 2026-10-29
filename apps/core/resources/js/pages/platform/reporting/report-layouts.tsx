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
    CollapsibleSection,
    CollapsibleSectionGroup,
} from '@apperp/ui/collapsible-section';
import { DataTable, DataTableText } from '@apperp/ui/data-table';
import type {
    DataTableColumn,
    DataTableRowAction,
} from '@apperp/ui/data-table';
import {
    Empty,
    EmptyDescription,
    EmptyHeader,
    EmptyTitle,
} from '@apperp/ui/empty';
import { Field, FieldDescription, FieldLabel } from '@apperp/ui/field';
import { Input } from '@apperp/ui/input';
import { ListPageLayout } from '@apperp/ui/list-page-layout';
import { MasterDetailLayout } from '@apperp/ui/master-detail-layout';
import {
    PageDialog,
    PageDialogContent,
    PageDialogToolbar,
    PageDialogBody,
} from '@apperp/ui/page-dialog';
import { RadioGroup, RadioGroupItem } from '@apperp/ui/radio-group';
import { RecordActionBar } from '@apperp/ui/record-action-bar';
import { matchesRecordSearch } from '@apperp/ui/record-search';

import { Textarea } from '@apperp/ui/textarea';
import { Head } from '@inertiajs/react';
import { Trash2 } from 'lucide-react';
import { useCallback, useEffect, useRef, useState } from 'react';
import { toast } from 'sonner';
import { useDateTimeFormat } from '@/hooks/use-date-time';
import { useSaveFeedback } from '@/hooks/use-save-feedback';
import type {
    DefaultVersions,
    Layout,
    Report,
    ReportField,
} from '@/lib/reports';
import {
    deleteLayout,
    downloadLayout,
    listFields,
    listLayouts,
    replaceLayoutFile,
    setDefaultLayout,
    uploadLayout,
    formatBytes,
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
            `Variabel tidak dikenal dan akan menghasilkan nilai kosong saat laporan dibuat: ${list.map((p) => `\${${p}}`).join(', ')}`,
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
    const formatTime = useDateTimeFormat();
    const [code, setCode] = useState(reports[0]?.code ?? '');
    const {
        report: saveReport,
        clear: saveClear,
        summary: saveSummary,
        scopeRef: saveScopeRef,
    } = useSaveFeedback<HTMLDivElement>(code);

    const [showDetail, setShowDetail] = useState(false);
    const [reportSearch, setReportSearch] = useState('');
    const [layouts, setLayouts] = useState<Layout[]>([]);
    const [defaultRef, setDefaultRef] = useState('');
    const [defaultVersions, setDefaultVersions] = useState<DefaultVersions>({
        tenant: 0,
        legal_entity: null,
    });
    const [fields, setFields] = useState<ReportField[]>([]);
    const [error, setError] = useState('');
    const [loading, setLoading] = useState(Boolean(reports[0]));
    const [uploading, setUploading] = useState(false);
    const [removing, setRemoving] = useState<Layout | null>(null);
    const replaceInput = useRef<HTMLInputElement>(null);
    const [replacing, setReplacing] = useState<Layout | null>(null);
    const loadSequence = useRef(0);

    const load = useCallback(async () => {
        const sequence = ++loadSequence.current;

        if (!code) {
            setLayouts([]);
            setFields([]);
            setDefaultRef('');
            setLoading(false);

            return;
        }

        setLoading(true);

        try {
            const [result, definition] = await Promise.all([
                listLayouts(code),
                listFields(code),
            ]);

            if (sequence !== loadSequence.current) {
                return;
            }

            setLayouts(result.data);
            setDefaultRef(result.meta.default_ref);
            setDefaultVersions(result.meta.default_versions);
            setFields(definition.data);
            setError('');
        } catch (caught) {
            if (sequence !== loadSequence.current) {
                return;
            }

            setError(
                caught instanceof Error && caught.message
                    ? caught.message
                    : 'Layout belum dapat dimuat.',
            );
        } finally {
            if (sequence === loadSequence.current) {
                setLoading(false);
            }
        }
    }, [code]);

    useEffect(() => {
        // Dimuat di microtask supaya effect tidak memanggil setState secara sinkron.
        void Promise.resolve().then(load);

        return () => {
            loadSequence.current += 1;
        };
    }, [load]);

    const selectReport = (selected: Report) => {
        setShowDetail(true);

        if (selected.code === code) {
            return;
        }

        loadSequence.current += 1;
        saveClear();
        setCode(selected.code);
        setLoading(true);
        setLayouts([]);
        setDefaultRef('');
        setDefaultVersions({ tenant: 0, legal_entity: null });
        setFields([]);
        setError('');
    };

    const makeDefault = async (layout: Layout | null, scope: Scope) => {
        saveClear();

        try {
            const result = await setDefaultLayout(
                code,
                layout?.ref ?? null,
                scope,
                defaultVersions[scope],
            );
            setLayouts(result.data);
            setDefaultRef(result.meta.default_ref);
            setDefaultVersions(result.meta.default_versions);
            toast.success(
                layout
                    ? `${layout.name} dijadikan default ${scope === 'tenant' ? 'seluruh perusahaan' : (legalEntity?.name ?? 'perusahaan aktif')}.`
                    : 'Pilihan default dihapus; laporan kembali memakai layout bawaan.',
            );
        } catch (caught) {
            saveReport(caught, 'Default belum dapat diubah.');
        }
    };

    const confirmRemove = async () => {
        if (!removing) {
            return;
        }

        saveClear();

        try {
            await deleteLayout(code, removing.ref, removing.version ?? 0);
            toast.success(`${removing.name} dihapus.`);
            setRemoving(null);
            await load();
        } catch (caught) {
            saveReport(caught, 'Layout belum dapat dihapus.');
        }
    };

    const replaceFile = async (file: File | undefined) => {
        if (!file || !replacing) {
            return;
        }

        saveClear();

        try {
            const result = await replaceLayoutFile(
                code,
                replacing.ref,
                file,
                replacing.version ?? 0,
            );
            warnUnknown(result.meta.unknown_placeholders);
            toast.success(`Berkas ${replacing.name} diganti.`);
            await load();
        } catch (caught) {
            saveReport(caught, 'Berkas belum dapat diganti.');
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
                    label: 'Delete',
                    icon: <Trash2 aria-hidden="true" />,
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
                    : (legalEntity?.name ?? 'Perusahaan aktif'),
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

    const reportColumns: DataTableColumn<Report>[] = [
        {
            id: 'report',
            header: 'Laporan',
            cell: (item) => (
                <div className="min-w-0 space-y-0.5">
                    <DataTableText>{item.name}</DataTableText>
                    <DataTableText>{item.app_name}</DataTableText>
                    {item.description && (
                        <DataTableText>{item.description}</DataTableText>
                    )}
                    <p className="text-xs text-muted-foreground">
                        {item.builtin_layouts.length} layout bawaan
                    </p>
                </div>
            ),
            sortValue: (item) => item.name,
        },
    ];

    const report = reports.find((item) => item.code === code);
    const visibleReports = reports.filter((item) =>
        matchesRecordSearch(reportSearch, [
            item.code,
            item.name,
            item.app_name,
            item.description ?? '',
        ]),
    );
    const rowActions = layouts.length
        ? actionsFor(
              layouts.find((layout) => layout.source === 'uploaded') ??
                  layouts[0],
          )
        : [];

    return (
        <>
            <Head title="Layout laporan" />
            <ListPageLayout
                ref={saveScopeRef}
                header={
                    <RecordActionBar
                        title="Layout laporan"
                        feedback={saveSummary}
                    >
                        {canManage && code && (
                            <ActionButton
                                action="create"
                                type="button"
                                onClick={() => setUploading(true)}
                            />
                        )}
                    </RecordActionBar>
                }
            >
                <MasterDetailLayout
                    detailOpen={showDetail}
                    onDetailOpenChange={setShowDetail}
                    list={
                        <aside className="flex h-full min-h-0 min-w-0 flex-col">
                            <div className="shrink-0 border-b p-3">
                                <Input
                                    type="search"
                                    aria-label="Cari laporan"
                                    placeholder="Cari nama laporan atau aplikasi..."
                                    value={reportSearch}
                                    onChange={(event) =>
                                        setReportSearch(event.target.value)
                                    }
                                />
                            </div>
                            <DataTable
                                columns={reportColumns}
                                data={visibleReports}
                                getRowKey={(item) => item.code}
                                getRowLabel={(item) => item.name}
                                showRowNumbers={false}
                                activeRowKey={code || undefined}
                                rowActivation="single-click"
                                onRowClick={selectReport}
                                emptyMessage={
                                    reports.length
                                        ? 'Tidak ada laporan yang cocok.'
                                        : 'Belum ada aplikasi yang menyediakan laporan.'
                                }
                            />
                        </aside>
                    }
                >
                    <div className="flex h-full min-h-0 min-w-0 flex-1 flex-col">
                        {report ? (
                            <>
                                <header className="shrink-0 border-b px-5 py-4">
                                    <p className="text-sm text-muted-foreground">
                                        {report.app_name}
                                    </p>
                                    <h2 className="text-lg font-semibold">
                                        {report.name}
                                    </h2>
                                    {report.description && (
                                        <p className="mt-1 text-sm text-muted-foreground">
                                            {report.description}
                                        </p>
                                    )}
                                </header>
                                <div className="flex min-w-0 shrink-0 flex-col p-4">
                                    <div className="mb-3 flex shrink-0 flex-wrap items-center justify-between gap-2">
                                        <div>
                                            <h3 className="text-sm font-semibold">
                                                Layout tersedia
                                            </h3>
                                            <p className="text-sm text-muted-foreground">
                                                Layout bawaan dan unggahan untuk
                                                laporan ini.
                                            </p>
                                        </div>
                                        {defaultRef &&
                                            canManage &&
                                            !loading && (
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
                                                    Kembalikan default ke layout
                                                    bawaan
                                                </Button>
                                            )}
                                    </div>
                                    {error && (
                                        <div
                                            role="alert"
                                            className="mb-3 flex shrink-0 flex-wrap items-center justify-between gap-2 rounded-md border border-destructive/30 px-3 py-2 text-sm"
                                        >
                                            <span>{error}</span>
                                            <Button
                                                type="button"
                                                variant="ghost"
                                                size="sm"
                                                onClick={() => void load()}
                                            >
                                                Coba lagi
                                            </Button>
                                        </div>
                                    )}
                                    <div className="max-h-56 shrink-0 overflow-y-auto">
                                        <DataTable
                                            scrollMode="inline"
                                            columns={columns}
                                            data={loading ? [] : layouts}
                                            getRowKey={(layout) => layout.ref}
                                            actions={rowActions}
                                            getRowActions={actionsFor}
                                            getRowLabel={(layout) =>
                                                layout.name
                                            }
                                            onRowAction={runAction}
                                            emptyMessage={
                                                loading
                                                    ? 'Memuat layout...'
                                                    : error
                                                      ? 'Layout belum dapat dimuat.'
                                                      : 'Belum ada layout untuk laporan ini.'
                                            }
                                        />
                                    </div>
                                </div>
                                <div className="min-h-0 flex-1 overflow-y-auto">
                                    <CollapsibleSectionGroup>
                                        <CollapsibleSection
                                            value="variables"
                                            title="Variabel laporan"
                                            summary={`${fields.length} variabel untuk ${report.name}`}
                                        >
                                            <p className="mb-4 text-sm text-muted-foreground">
                                                Tulis nama variabel persis
                                                seperti ini di layout. Baris
                                                yang memakai variabel dari tabel
                                                akan diulang per data; letakkan
                                                variabel itu dalam satu baris
                                                tabel Word atau lembar Excel.
                                            </p>
                                            <div className="grid gap-x-8 gap-y-3 text-sm sm:grid-cols-2 lg:grid-cols-3">
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
                                </div>
                            </>
                        ) : (
                            <Empty>
                                <EmptyHeader>
                                    <EmptyTitle>
                                        {reports.length
                                            ? 'Pilih laporan'
                                            : 'Belum ada laporan'}
                                    </EmptyTitle>
                                    <EmptyDescription>
                                        {reports.length
                                            ? 'Pilih laporan di daftar untuk melihat dan mengatur layout-nya.'
                                            : 'Belum ada aplikasi yang menyediakan laporan untuk dicetak atau diekspor.'}
                                    </EmptyDescription>
                                </EmptyHeader>
                            </Empty>
                        )}
                    </div>
                </MasterDetailLayout>

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
            </ListPageLayout>
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
    const {
        report: saveReport,
        clear: saveClear,
        summary: saveSummary,
        scopeRef: saveScopeRef,
    } = useSaveFeedback<HTMLDivElement>(code);
    const [file, setFile] = useState<File | null>(null);
    const [name, setName] = useState('');
    const [description, setDescription] = useState('');
    const [scope, setScope] = useState<Scope>(
        legalEntity ? 'legal_entity' : 'tenant',
    );
    const [saving, setSaving] = useState(false);

    const save = async () => {
        if (!file) {
            saveReport(
                new Error('Pilih berkas layout lebih dahulu.'),
                'Layout belum dapat diunggah.',
            );

            return;
        }

        setSaving(true);

        saveClear();

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
            saveReport(caught, 'Layout belum dapat diunggah.');
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
                title="Layout laporan"
                recordTitle={name.trim() || file?.name || 'Layout baru'}
                editing
                description={
                    'Berkas Word (.docx) atau Excel (.xlsx) tanpa makro,\n                        paling besar 5 MB. Placeholder yang tidak dikenal tidak\n                        ditolak, tetapi dicetak kosong.'
                }
                busy={saving}
            >
                <PageDialogToolbar>
                    <ActionButton
                        action="save"
                        pending={saving}
                        type="button"
                        disabled={saving || !name || !file}
                        onClick={() => void save()}
                    />
                    <ActionButton
                        action="cancel"
                        type="button"
                        disabled={saving}
                        onClick={onClose}
                    />
                </PageDialogToolbar>
                {saveSummary}
                <PageDialogBody ref={saveScopeRef} className="space-y-4">
                    <Field>
                        <FieldLabel htmlFor="layout-file">
                            Berkas layout
                        </FieldLabel>
                        <Input
                            id="layout-file"
                            data-error-field="file"
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
                            data-error-field="name"
                            required
                            value={name}
                            onChange={(event) => setName(event.target.value)}
                        />
                    </Field>
                    <Field>
                        <Textarea
                            label="Keterangan"
                            data-error-field="description"
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
                            Pilih perusahaan bila layout memuat kop atau tanda
                            tangan perusahaan tertentu.
                        </FieldDescription>
                    </Field>
                </PageDialogBody>
            </PageDialogContent>
        </PageDialog>
    );
}

ReportLayouts.layout = {
    breadcrumbs: [
        { title: 'Settings', href: '/settings/profile' },
        { title: 'Layout laporan', href: '/settings/report-layouts' },
    ] satisfies BreadcrumbItem[],
};
