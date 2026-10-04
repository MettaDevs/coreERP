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
    EmptyDescription,
    EmptyHeader,
    EmptyTitle,
} from '@apperp/ui/empty';
import { RecordActionBar } from '@apperp/ui/record-action-bar';
import { Head, router } from '@inertiajs/react';
import { LayoutGrid } from 'lucide-react';
import { useState } from 'react';
import { toast } from 'sonner';
import { DashboardFormSheet } from '@/components/analytics/dashboard-form-sheet';
import {
    DashboardGrid,
    sortLayout,
} from '@/components/analytics/dashboard-grid';
import { WidgetFrame } from '@/components/analytics/widget-frame';
import { WidgetTitleDialog } from '@/components/analytics/widget-title-dialog';
import {
    archiveDashboard,
    archiveWidget,
    updateDashboard,
} from '@/lib/analytics/api';
import type {
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
 * mengatur letak dengan tombol geser dan pilihan lebar, mengganti judul widget, dan mengarsipkan.
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
    const [draft, setDraft] = useState<DashboardLayoutItem[] | null>(null);
    const [savingLayout, setSavingLayout] = useState(false);
    const [editingDashboard, setEditingDashboard] = useState(false);
    const [archivingDashboard, setArchivingDashboard] = useState(false);
    const [renaming, setRenaming] = useState<DashboardWidget | null>(null);
    const [archivingWidget, setArchivingWidget] =
        useState<DashboardWidget | null>(null);
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
                                height={place.h}
                                className={
                                    widget.type === 'kpi' ||
                                    widget.type === 'text'
                                        ? undefined
                                        : 'flex-1'
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
