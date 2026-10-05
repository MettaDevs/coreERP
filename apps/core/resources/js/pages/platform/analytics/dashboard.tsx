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
import { Card, CardContent } from '@apperp/ui/card';
import {
    Empty,
    EmptyContent,
    EmptyDescription,
    EmptyHeader,
    EmptyTitle,
} from '@apperp/ui/empty';
import { RecordActionBar } from '@apperp/ui/record-action-bar';
import { Head, router, usePage } from '@inertiajs/react';
import { LayoutGrid } from 'lucide-react';
import { useState } from 'react';
import { toast } from 'sonner';
import { DashboardFormSheet } from '@/components/analytics/dashboard-form-sheet';
import {
    DashboardGrid,
    sortLayout,
} from '@/components/analytics/dashboard-grid';
import { CrossFilterChips, SlicerBar } from '@/components/analytics/slicer-bar';
import { WidgetBuilder } from '@/components/analytics/widget-builder';
import { WidgetFrame } from '@/components/analytics/widget-frame';
import { WidgetTitleDialog } from '@/components/analytics/widget-title-dialog';
import {
    archiveDashboard,
    archiveWidget,
    updateDashboard,
} from '@/lib/analytics/api';
import {
    fieldsForWidget,
    readSlicerUrl,
    slicerUrl,
} from '@/lib/analytics/slicer';
import type {
    CrossFilter,
    DashboardAbilities,
    DashboardDetail,
    DashboardLayoutItem,
    DashboardWidget,
} from '@/lib/analytics/types';
import { toastSaveError } from '@/lib/core-api';
import type { BreadcrumbItem } from '@/types/navigation';

type Props = {
    dashboard: DashboardDetail;
    abilities: DashboardAbilities;
};

/**
 * Satu dasbor (`/analytics/dashboards/{id}`, area 7.2): grid widget yang masing-masing memuat datanya sendiri
 * saat terlihat, dihitung sebagai yang melihat. Pemilik (atau pengelola dasbor bersama) dapat mengubah nama,
 * mengatur letak dengan tombol geser dan pilihan lebar, mengganti judul widget, dan mengarsipkan; area 8 menambah
 * Tambah bagian dan Ubah lewat pembangun widget.
 *
 * Prop dari server adalah sumber kebenarannya: setiap perubahan disimpan dengan versi yang dibuka lalu
 * halamannya dimuat ulang, tidak disalin ke state. Yang dipegang layar hanya draf letak selama mode ubah.
 * Dasbor lain dibuka lewat komponen yang sama, jadi isinya diberi kunci id dasbor.
 */
export default function AnalyticsDashboard(props: Props) {
    return <DashboardScreen key={props.dashboard.id} {...props} />;
}

AnalyticsDashboard.layout = (props: Props) => ({
    breadcrumbs: [
        { title: 'Dasbor', href: '/analytics' },
        {
            title: props.dashboard.name,
            href: `/analytics/dashboards/${props.dashboard.id}`,
        },
    ] satisfies BreadcrumbItem[],
});

function reloadDashboard(onFinish?: () => void) {
    router.reload({ only: ['dashboard'], onFinish });
}

function DashboardScreen({ dashboard, abilities }: Props) {
    const { url } = usePage();
    const [draft, setDraft] = useState<DashboardLayoutItem[] | null>(null);
    const [savingLayout, setSavingLayout] = useState(false);
    const [editingDashboard, setEditingDashboard] = useState(false);
    const [archivingDashboard, setArchivingDashboard] = useState(false);
    const [renaming, setRenaming] = useState<DashboardWidget | null>(null);
    const [archivingWidget, setArchivingWidget] =
        useState<DashboardWidget | null>(null);
    // Pembangun bagian (area 8): `widget` kosong untuk bagian baru.
    const [building, setBuilding] = useState<{
        widget: DashboardWidget | null;
    } | null>(null);
    const [crossFilterState, setCrossFilterState] = useState({
        dashboardId: dashboard.id,
        values: [] as CrossFilter[],
    });
    const crossFilters =
        crossFilterState.dashboardId === dashboard.id
            ? crossFilterState.values
            : [];
    const setCrossFilters = (
        update: CrossFilter[] | ((current: CrossFilter[]) => CrossFilter[]),
    ) => {
        setCrossFilterState((current) => {
            const values =
                current.dashboardId === dashboard.id ? current.values : [];

            return {
                dashboardId: dashboard.id,
                values: typeof update === 'function' ? update(values) : update,
            };
        });
    };
    const slicerValues = readSlicerUrl(url, dashboard.slicers);
    const editing = draft !== null;
    const layout = draft ?? dashboard.layout;
    const canEdit = dashboard.can_edit;

    const saveLayout = async () => {
        if (draft === null) {
            return;
        }

        setSavingLayout(true);

        try {
            await updateDashboard(dashboard, { layout: draft });
            toast.success('Tata letak disimpan.');
            reloadDashboard(() => setDraft(null));
        } catch (caught) {
            toastSaveError(caught, 'Tata letak belum disimpan.');
        } finally {
            setSavingLayout(false);
        }
    };

    const confirmArchiveDashboard = async () => {
        try {
            await archiveDashboard(dashboard);
            toast.success(`Dasbor "${dashboard.name}" diarsipkan.`);
            router.visit('/analytics');
        } catch (caught) {
            toastSaveError(caught, 'Dasbor belum diarsipkan.');
        }
    };

    const confirmArchiveWidget = async (widget: DashboardWidget) => {
        try {
            await archiveWidget(widget);
            toast.success(`"${widget.title}" diarsipkan dari dasbor ini.`);
            reloadDashboard();
        } catch (caught) {
            toastSaveError(caught, 'Bagian ini belum diarsipkan.');
        } finally {
            setArchivingWidget(null);
        }
    };

    const saveSlicers = async (slicers: DashboardDetail['slicers']) => {
        await updateDashboard(dashboard, { slicers });
        const kept = Object.fromEntries(
            Object.entries(slicerValues).filter(([key]) =>
                slicers.some((slicer) => slicer.key === key),
            ),
        );
        router.replace({
            url: slicerUrl(url, slicers, kept),
            preserveState: true,
            preserveScroll: true,
        });
        setCrossFilters([]);
        toast.success('Saringan dasbor disimpan.');
        reloadDashboard();
    };

    const toggleCrossFilter = (filter: CrossFilter) => {
        setCrossFilters((current) =>
            current.some((item) => item.id === filter.id)
                ? current.filter((item) => item.id !== filter.id)
                : [...current, filter],
        );
    };

    return (
        <>
            <Head title={dashboard.name} />
            <RecordActionBar
                title="Dasbor"
                trailing={
                    dashboard.shared ? (
                        <Badge variant="secondary">Bersama</Badge>
                    ) : (
                        <Badge variant="outline">Pribadi</Badge>
                    )
                }
            >
                {canEdit &&
                    (editing ? (
                        <>
                            <Button
                                type="button"
                                size="sm"
                                disabled={savingLayout}
                                onClick={() => void saveLayout()}
                            >
                                Simpan tata letak
                            </Button>
                            <Button
                                type="button"
                                size="sm"
                                variant="outline"
                                disabled={savingLayout}
                                onClick={() => setDraft(null)}
                            >
                                Batal
                            </Button>
                        </>
                    ) : (
                        <>
                            <ActionButton
                                action="create"
                                size="sm"
                                onClick={() => setBuilding({ widget: null })}
                            >
                                Tambah bagian
                            </ActionButton>
                            <ActionButton
                                action="edit"
                                size="sm"
                                onClick={() => setEditingDashboard(true)}
                            >
                                Ubah dasbor
                            </ActionButton>
                            {dashboard.widgets.length > 0 && (
                                <Button
                                    type="button"
                                    size="sm"
                                    variant="outline"
                                    onClick={() =>
                                        setDraft(sortLayout(dashboard.layout))
                                    }
                                >
                                    <LayoutGrid />
                                    Atur tata letak
                                </Button>
                            )}
                            <ActionButton
                                action="archive"
                                size="sm"
                                onClick={() => setArchivingDashboard(true)}
                            >
                                Arsipkan
                            </ActionButton>
                        </>
                    ))}
            </RecordActionBar>
            <main className="mx-auto flex w-full max-w-7xl min-w-0 flex-col gap-4 p-6">
                <div className="space-y-1">
                    <h1 className="text-xl font-semibold tracking-tight break-words">
                        {dashboard.name}
                    </h1>
                    {dashboard.description && (
                        <p className="text-sm break-words whitespace-pre-line">
                            {dashboard.description}
                        </p>
                    )}
                    <p className="text-sm text-muted-foreground">
                        {dashboard.shared
                            ? `Dibagikan oleh ${dashboard.mine ? 'Anda' : (dashboard.owner_name ?? 'pemiliknya')}. Setiap orang melihat angka sesuai aksesnya sendiri.`
                            : 'Hanya Anda yang melihat dasbor ini.'}
                    </p>
                    {editing && (
                        <p className="text-sm" role="status">
                            Geser bagian ke kiri atau kanan dan pilih lebarnya,
                            lalu simpan tata letaknya. Di layar sempit setiap
                            bagian tetap selebar layar.
                        </p>
                    )}
                </div>
                <SlicerBar
                    dashboard={dashboard}
                    values={slicerValues}
                    canEdit={canEdit}
                    onValuesChange={(values) => {
                        router.replace({
                            url: slicerUrl(url, dashboard.slicers, values),
                            preserveState: true,
                            preserveScroll: true,
                        });
                        setCrossFilters([]);
                    }}
                    onSave={saveSlicers}
                />
                <CrossFilterChips
                    filters={crossFilters}
                    onRemove={(id) =>
                        setCrossFilters((current) =>
                            current.filter((item) => item.id !== id),
                        )
                    }
                    onClear={() => setCrossFilters([])}
                />
                {dashboard.widgets.length === 0 ? (
                    <Card>
                        <CardContent>
                            <Empty className="py-12">
                                <EmptyHeader>
                                    <EmptyTitle>
                                        Dasbor ini belum berisi apa pun
                                    </EmptyTitle>
                                    <EmptyDescription>
                                        {canEdit
                                            ? 'Angka, grafik, dan tabel yang ditambahkan ke dasbor ini akan tampil di sini.'
                                            : 'Pemilik dasbor belum menambahkan isi.'}
                                    </EmptyDescription>
                                </EmptyHeader>
                                {canEdit && (
                                    <EmptyContent>
                                        <ActionButton
                                            action="create"
                                            size="sm"
                                            onClick={() =>
                                                setBuilding({ widget: null })
                                            }
                                        >
                                            Tambah bagian
                                        </ActionButton>
                                    </EmptyContent>
                                )}
                            </Empty>
                        </CardContent>
                    </Card>
                ) : (
                    <DashboardGrid
                        widgets={dashboard.widgets}
                        layout={layout}
                        editing={editing}
                        onLayoutChange={setDraft}
                        renderWidget={(widget, place) => (
                            <WidgetFrame
                                widget={widget}
                                fields={fieldsForWidget(
                                    widget,
                                    dashboard.dataset_fields,
                                )}
                                hierarchies={
                                    widget.dataset_code === null
                                        ? {}
                                        : (dashboard.dataset_fields[
                                              widget.dataset_code
                                          ]?.hierarchies ?? {})
                                }
                                slicers={dashboard.slicers}
                                slicerValues={slicerValues}
                                crossFilters={crossFilters}
                                onCrossFilter={toggleCrossFilter}
                                height={place.h}
                                className={
                                    widget.type === 'kpi' ||
                                    widget.type === 'text'
                                        ? undefined
                                        : 'flex-1'
                                }
                                onEdit={
                                    canEdit && !editing
                                        ? (item) =>
                                              setBuilding({ widget: item })
                                        : undefined
                                }
                                onRename={
                                    canEdit && !editing
                                        ? setRenaming
                                        : undefined
                                }
                                onArchive={
                                    canEdit && !editing
                                        ? setArchivingWidget
                                        : undefined
                                }
                            />
                        )}
                    />
                )}
            </main>
            {editingDashboard && (
                <DashboardFormSheet
                    dashboard={dashboard}
                    abilities={abilities}
                    onClose={() => setEditingDashboard(false)}
                    onSaved={() => {
                        toast.success('Dasbor disimpan.');
                        setEditingDashboard(false);
                        reloadDashboard();
                    }}
                />
            )}
            {building && (
                <WidgetBuilder
                    dashboard={dashboard}
                    widget={building.widget}
                    onClose={() => setBuilding(null)}
                    onSaved={(widget) => {
                        toast.success(
                            building.widget === null
                                ? `"${widget.title}" ditambahkan ke dasbor ini.`
                                : 'Perubahan disimpan.',
                        );
                        setBuilding(null);
                        reloadDashboard();
                    }}
                />
            )}
            {renaming && (
                <WidgetTitleDialog
                    widget={renaming}
                    onClose={() => setRenaming(null)}
                    onSaved={() => {
                        toast.success('Perubahan disimpan.');
                        setRenaming(null);
                        reloadDashboard();
                    }}
                />
            )}
            <AlertDialog
                open={archivingDashboard}
                onOpenChange={setArchivingDashboard}
            >
                <AlertDialogContent>
                    <AlertDialogHeader>
                        <AlertDialogTitle>
                            Arsipkan dasbor "{dashboard.name}"?
                        </AlertDialogTitle>
                        <AlertDialogDescription>
                            {dashboard.shared
                                ? 'Dasbor ini hilang dari daftar Anda dan semua anggota yang menerimanya. Isinya ikut diarsipkan; data asalnya tidak berubah.'
                                : 'Dasbor ini hilang dari daftar Anda. Isinya ikut diarsipkan; data asalnya tidak berubah.'}
                        </AlertDialogDescription>
                    </AlertDialogHeader>
                    <AlertDialogFooter>
                        <AlertDialogCancel>Batal</AlertDialogCancel>
                        <AlertDialogAction
                            onClick={() => void confirmArchiveDashboard()}
                        >
                            Arsipkan
                        </AlertDialogAction>
                    </AlertDialogFooter>
                </AlertDialogContent>
            </AlertDialog>
            <AlertDialog
                open={archivingWidget !== null}
                onOpenChange={(open) => !open && setArchivingWidget(null)}
            >
                <AlertDialogContent>
                    <AlertDialogHeader>
                        <AlertDialogTitle>
                            Arsipkan "{archivingWidget?.title}"?
                        </AlertDialogTitle>
                        <AlertDialogDescription>
                            Bagian ini hilang dari dasbor untuk semua yang
                            membukanya. Data asalnya tidak berubah.
                        </AlertDialogDescription>
                    </AlertDialogHeader>
                    <AlertDialogFooter>
                        <AlertDialogCancel>Batal</AlertDialogCancel>
                        <AlertDialogAction
                            onClick={() =>
                                archivingWidget &&
                                void confirmArchiveWidget(archivingWidget)
                            }
                        >
                            Arsipkan
                        </AlertDialogAction>
                    </AlertDialogFooter>
                </AlertDialogContent>
            </AlertDialog>
        </>
    );
}
