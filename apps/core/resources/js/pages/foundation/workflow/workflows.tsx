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
import { DataTable } from '@apperp/ui/data-table';
import type {
    DataTableColumn,
    DataTableRowAction,
} from '@apperp/ui/data-table';
import { Field, FieldLabel } from '@apperp/ui/field';
import { FormControl, FormGrid } from '@apperp/ui/form-control';
import { InlineLookup } from '@apperp/ui/inline-lookup';
import { Input } from '@apperp/ui/input';
import { ListPageLayout } from '@apperp/ui/list-page-layout';
import {
    PageDialog,
    PageDialogBody,
    PageDialogClose,
    PageDialogContent,
    PageDialogToolbar,
} from '@apperp/ui/page-dialog';
import { RecordActionBar } from '@apperp/ui/record-action-bar';
import { Select } from '@apperp/ui/select';
import { Switch } from '@apperp/ui/switch';
import { useRecordTransition } from '@apperp/ui/use-record-transition';
import { Head, router, useForm, usePage } from '@inertiajs/react';
import { Check, Pencil, Power, Search, Settings2 } from 'lucide-react';
import { useRef, useState } from 'react';
import { FormErrorHeader } from '@/components/form-error-header';
import {
    RecordListScroll,
    useRecordListSort,
} from '@/components/record-list-scroll';
import { useInertiaFeedback } from '@/hooks/use-inertia-feedback';

type WorkflowType = {
    id: string;
    code: string;
    name: string;
    app_name: string;
    scope: 'tenant' | 'legal_entity';
};
type LegalEntity = { id: string; name: string };
type Workflow = {
    id: string;
    name: string;
    enabled: boolean;
    version: number;
    status: 'draft' | 'active' | 'inactive';
    type_name: string;
    app_name: string;
    scope: 'tenant' | 'legal_entity';
    legal_entity_id: string | null;
};
type WorkflowParameter = {
    code: string;
    tipe: 'boolean';
    label: string;
    penjelasan: string;
    value: boolean;
};
type Filters = {
    q: string;
    status: Workflow['status'] | null;
    app: string | null;
    sort: string;
    direction: 'asc' | 'desc';
};
type Props = {
    canManage: boolean;
    parameters: WorkflowParameter[];
    workflowTypes: WorkflowType[];
    legalEntities: LegalEntity[];
    workflows: { data: Workflow[]; next_page_url: string | null };
    filters: Filters;
    workflowApps: string[];
};
const statusLabel = { draft: 'Draf', active: 'Aktif', inactive: 'Nonaktif' };

function WorkflowFilters({
    filters,
    apps,
}: {
    filters: Filters;
    apps: string[];
}) {
    const [query, setQuery] = useState(filters.q);
    const { url } = usePage();
    const queryParams = new URL(url, 'http://localhost').searchParams;
    const go = (next: Partial<Filters>) =>
        router.get(
            '/settings/workflows',
            {
                ...filters,
                sort: queryParams.get('sort') ?? filters.sort,
                direction: queryParams.get('direction') ?? filters.direction,
                q: query,
                ...next,
            },
            {
                preserveState: true,
                preserveScroll: true,
                replace: true,
                reset: ['workflows'],
            },
        );
    const appOptions = [
        { id: '', name: 'Semua aplikasi' },
        ...apps.map((name) => ({ id: name, name })),
    ];

    return (
        <form
            noValidate
            className="flex flex-wrap items-end gap-3 px-4 pb-3"
            onSubmit={(event) => {
                event.preventDefault();
                go({ q: query });
            }}
        >
            <FormControl className="min-w-56 flex-1">
                <Input
                    label="Cari workflow atau proses"
                    type="search"
                    value={query}
                    onChange={(event) => setQuery(event.target.value)}
                />
            </FormControl>
            <FormControl className="w-full sm:w-56">
                <InlineLookup
                    label="Aplikasi"
                    items={appOptions}
                    value={
                        appOptions.find(
                            (item) => item.id === (filters.app ?? ''),
                        ) ?? null
                    }
                    getItemValue={(item) => item.id}
                    getItemLabel={(item) => item.name}
                    columns={[
                        {
                            id: 'name',
                            header: 'Aplikasi',
                            cell: (item) => item.name,
                        },
                    ]}
                    onValueChange={(item) => go({ app: item.id || null })}
                    showDetails={false}
                    showFullList={false}
                />
            </FormControl>
            <FormControl className="w-full sm:w-44">
                <Select
                    label="Status"
                    items={[
                        { value: '', label: 'Semua status' },
                        ...Object.entries(statusLabel).map(
                            ([value, label]) => ({ value, label }),
                        ),
                    ]}
                    value={filters.status ?? ''}
                    onValueChange={(value) =>
                        go({
                            status:
                                value === 'draft' ||
                                value === 'active' ||
                                value === 'inactive'
                                    ? value
                                    : null,
                        })
                    }
                />
            </FormControl>
            <Button type="submit" variant="ghost">
                <Search aria-hidden="true" />
                Cari
            </Button>
        </form>
    );
}

export default function Workflows({
    canManage,
    parameters,
    workflowTypes,
    legalEntities,
    workflows,
    filters,
    workflowApps,
}: Props) {
    const { wrap: wrapCreateFormRequest, summary: createFormRequestSummary } =
        useInertiaFeedback();
    const { wrap: wrapToggleFormRequest, summary: toggleFormRequestSummary } =
        useInertiaFeedback();
    const {
        wrap: wrapParameterFormRequest,
        summary: parameterFormRequestSummary,
    } = useInertiaFeedback();

    const [createOpen, setCreateOpen] = useState(false);
    const [settingsOpen, setSettingsOpen] = useState(false);
    const [changing, setChanging] = useState<Workflow | null>(null);
    const [parameterStatus, setParameterStatus] = useState('');
    const [pendingParameter, setPendingParameter] = useState<{
        code: string;
        value: boolean;
    } | null>(null);
    const createPortal = useRef<HTMLDivElement>(null);
    const createForm = useForm({
        workflow_type_id: '',
        name: '',
        legal_entity_id: '',
    });
    const createTransition = useRecordTransition(
        createForm.isDirty,
        createForm.processing,
    );
    const toggleForm = useForm({ version: 0 });
    const parameterForm = useForm({ code: '', value: false });
    const sort = useRecordListSort('workflows');
    const selectedType = workflowTypes.find(
        (type) => type.id === createForm.data.workflow_type_id,
    );
    const needsLegalEntity = selectedType?.scope === 'legal_entity';
    const openWorkflow = (workflow: Workflow) =>
        router.visit(`/settings/workflows/${workflow.id}/edit`);
    const actions: DataTableRowAction[] = [
        { id: 'open', label: 'Buka alur', icon: <Pencil /> },
        { id: 'activate', label: 'Aktifkan', icon: <Check /> },
        { id: 'deactivate', label: 'Nonaktifkan', icon: <Power /> },
    ];
    const columns: DataTableColumn<Workflow>[] = [
        {
            id: 'name',
            header: 'Nama workflow',
            cell: (workflow) => workflow.name,
            sortValue: (workflow) => workflow.name,
            minWidth: 220,
        },
        {
            id: 'app_name',
            header: 'Aplikasi',
            cell: (workflow) => workflow.app_name,
            sortValue: (workflow) => workflow.app_name,
            width: 180,
        },
        {
            id: 'type_name',
            header: 'Proses',
            cell: (workflow) => workflow.type_name,
            sortValue: (workflow) => workflow.type_name,
            minWidth: 240,
        },
        {
            id: 'company',
            header: 'Perusahaan',
            cell: (workflow) =>
                workflow.scope === 'tenant'
                    ? 'Berlaku umum'
                    : (legalEntities.find(
                          (entity) => entity.id === workflow.legal_entity_id,
                      )?.name ?? 'Perusahaan tidak tersedia'),
            width: 220,
        },
        {
            id: 'status',
            header: 'Status',
            cell: (workflow) => (
                <Badge
                    variant={
                        workflow.status === 'active' ? 'secondary' : 'outline'
                    }
                >
                    {statusLabel[workflow.status]}
                </Badge>
            ),
            sortValue: (workflow) => workflow.status,
            width: 110,
        },
    ];

    return (
        <>
            <Head title="Workflow" />
            <ListPageLayout
                header={
                    <>
                        <RecordActionBar title="Workflow">
                            {canManage && (
                                <ActionButton
                                    action="create"
                                    onClick={() => {
                                        createForm.reset();
                                        createForm.clearErrors();
                                        setCreateOpen(true);
                                    }}
                                    disabled={workflowTypes.length === 0}
                                />
                            )}
                            {parameters.length > 0 && (
                                <Button
                                    type="button"
                                    variant="ghost"
                                    size="command"
                                    onClick={() => setSettingsOpen(true)}
                                >
                                    <Settings2 aria-hidden="true" />
                                    Pengaturan
                                </Button>
                            )}
                        </RecordActionBar>
                    </>
                }
                filters={
                    <WorkflowFilters
                        key={JSON.stringify([
                            filters.q,
                            filters.status,
                            filters.app,
                        ])}
                        filters={filters}
                        apps={workflowApps}
                    />
                }
                footer={
                    <p className="px-4 py-2 text-sm text-muted-foreground">
                        {workflows.data.length} workflow ditampilkan
                    </p>
                }
            >
                <RecordListScroll
                    data="workflows"
                    className="flex min-h-0 flex-1 flex-col"
                >
                    <DataTable
                        {...sort}
                        data={workflows.data}
                        columns={columns}
                        getRowKey={(workflow) => workflow.id}
                        getRowLabel={(workflow) => workflow.name}
                        actions={actions}
                        getRowActions={(workflow) =>
                            actions.filter(
                                (action) =>
                                    action.id === 'open' ||
                                    (canManage &&
                                        ((action.id === 'activate' &&
                                            workflow.status === 'inactive') ||
                                            (action.id === 'deactivate' &&
                                                workflow.status === 'active'))),
                            )
                        }
                        onRowAction={(action, workflow) => {
                            if (action === 'open') {
                                openWorkflow(workflow);
                            } else if (canManage && !toggleForm.processing) {
                                toggleForm.clearErrors();
                                setChanging(workflow);
                            }
                        }}
                        onRowClick={openWorkflow}
                        emptyMessage={
                            filters.q || filters.app || filters.status
                                ? 'Tidak ada workflow yang sesuai dengan filter.'
                                : canManage && workflowTypes.length > 0
                                  ? 'Belum ada workflow. Pilih Baru untuk mulai menyusun alur.'
                                  : 'Belum ada workflow yang dapat ditampilkan.'
                        }
                    />
                </RecordListScroll>
            </ListPageLayout>

            <PageDialog
                open={createOpen}
                onOpenChange={(open) => {
                    if (!open) {
                        createTransition.request(() => setCreateOpen(false));
                    }
                }}
            >
                <PageDialogContent
                    ref={createPortal}
                    title="Workflow"
                    recordTitle={createForm.data.name.trim() || 'Baru'}
                    editing
                    busy={createForm.processing}
                >
                    <form
                        noValidate
                        className="contents"
                        onSubmit={(event) => {
                            event.preventDefault();

                            if (!canManage) {
                                return;
                            }

                            createForm.post(
                                '/settings/workflows',
                                wrapCreateFormRequest({
                                    onSuccess: () => {
                                        setCreateOpen(false);
                                        createForm.reset();
                                    },
                                }),
                            );
                        }}
                    >
                        <PageDialogToolbar>
                            <ActionButton
                                action="save"
                                type="submit"
                                pending={createForm.processing}
                                disabled={
                                    !canManage ||
                                    !selectedType ||
                                    !createForm.data.name.trim() ||
                                    (needsLegalEntity &&
                                        !createForm.data.legal_entity_id)
                                }
                            />
                            <ActionButton
                                action="cancel"
                                onClick={() =>
                                    createTransition.request(() =>
                                        setCreateOpen(false),
                                    )
                                }
                                disabled={createForm.processing}
                            />
                        </PageDialogToolbar>
                        <>
                            {createFormRequestSummary}
                            <FormErrorHeader errors={createForm.errors} />
                        </>

                        <PageDialogBody>
                            <p className="mb-4 text-sm text-muted-foreground">
                                Pilih proses yang membutuhkan persetujuan.
                                Setelah disimpan sebagai draf, lanjutkan
                                menyusun langkah dan penerima tugas.
                            </p>
                            <FormGrid>
                                <FormControl error={createForm.errors.name}>
                                    <Input
                                        label="Nama workflow"
                                        required
                                        maxLength={160}
                                        value={createForm.data.name}
                                        onChange={(event) =>
                                            createForm.setData(
                                                'name',
                                                event.target.value,
                                            )
                                        }
                                    />
                                </FormControl>
                                <FormControl
                                    error={createForm.errors.workflow_type_id}
                                >
                                    <InlineLookup
                                        label="Proses"
                                        required
                                        items={workflowTypes}
                                        value={selectedType ?? null}
                                        columns={[
                                            {
                                                id: 'app',
                                                header: 'Aplikasi',
                                                cell: (type) => type.app_name,
                                                width: 180,
                                            },
                                            {
                                                id: 'process',
                                                header: 'Proses',
                                                cell: (type) => type.name,
                                                width: 280,
                                            },
                                        ]}
                                        getItemValue={(type) => type.id}
                                        getItemLabel={(type) =>
                                            `${type.app_name} — ${type.name}`
                                        }
                                        onValueChange={(type) =>
                                            createForm.setData((current) => ({
                                                ...current,
                                                workflow_type_id: type.id,
                                                legal_entity_id:
                                                    type.scope === 'tenant'
                                                        ? ''
                                                        : current.legal_entity_id,
                                            }))
                                        }
                                        portalContainer={createPortal}
                                        showDetails={false}
                                    />
                                </FormControl>
                                {needsLegalEntity && (
                                    <FormControl
                                        error={
                                            createForm.errors.legal_entity_id
                                        }
                                        hint="Alur ini hanya berlaku untuk dokumen dari perusahaan yang dipilih."
                                    >
                                        <InlineLookup
                                            label="Perusahaan"
                                            required
                                            items={legalEntities}
                                            value={
                                                legalEntities.find(
                                                    (entity) =>
                                                        entity.id ===
                                                        createForm.data
                                                            .legal_entity_id,
                                                ) ?? null
                                            }
                                            columns={[
                                                {
                                                    id: 'name',
                                                    header: 'Perusahaan',
                                                    cell: (entity) =>
                                                        entity.name,
                                                },
                                            ]}
                                            getItemValue={(entity) => entity.id}
                                            getItemLabel={(entity) =>
                                                entity.name
                                            }
                                            onValueChange={(entity) =>
                                                createForm.setData(
                                                    'legal_entity_id',
                                                    entity.id,
                                                )
                                            }
                                            portalContainer={createPortal}
                                            showDetails={false}
                                        />
                                    </FormControl>
                                )}
                            </FormGrid>
                            {selectedType?.scope === 'tenant' && (
                                <p className="mt-4 text-sm text-muted-foreground">
                                    Proses ini dipakai bersama dan tidak
                                    dibatasi ke satu perusahaan.
                                </p>
                            )}
                            {!workflowTypes.length && (
                                <p
                                    role="status"
                                    className="mt-4 text-sm text-muted-foreground"
                                >
                                    Belum ada proses aplikasi yang dapat memakai
                                    workflow.
                                </p>
                            )}
                        </PageDialogBody>
                    </form>
                </PageDialogContent>
                {createTransition.confirmation}
            </PageDialog>

            <PageDialog
                open={settingsOpen}
                onOpenChange={(open) => {
                    if (!parameterForm.processing) {
                        setSettingsOpen(open);
                    }
                }}
            >
                <PageDialogContent
                    title="Pengaturan workflow"
                    busy={parameterForm.processing}
                >
                    <PageDialogToolbar>
                        <PageDialogClose asChild>
                            <Button
                                type="button"
                                variant="ghost"
                                size="command"
                                disabled={parameterForm.processing}
                            >
                                Selesai
                            </Button>
                        </PageDialogClose>
                    </PageDialogToolbar>
                    <PageDialogBody>
                        <p className="mb-4 text-sm text-muted-foreground">
                            Pengaturan berlaku untuk seluruh workflow. Perubahan
                            langsung disimpan.
                        </p>
                        <>
                            {parameterFormRequestSummary}
                            <FormErrorHeader
                                errors={parameterForm.errors}
                                operation="Pengaturan workflow belum disimpan"
                            />
                        </>
                        <FormGrid columns={1}>
                            {parameters.map((parameter) => (
                                <FormControl
                                    key={parameter.code}
                                    hint={parameter.penjelasan}
                                >
                                    <Field orientation="horizontal">
                                        <FieldLabel
                                            htmlFor={`workflow-${parameter.code}`}
                                        >
                                            {parameter.label}
                                        </FieldLabel>
                                        <Switch
                                            id={`workflow-${parameter.code}`}
                                            checked={
                                                pendingParameter?.code ===
                                                parameter.code
                                                    ? pendingParameter.value
                                                    : parameter.value
                                            }
                                            disabled={
                                                !canManage ||
                                                parameterForm.processing
                                            }
                                            onCheckedChange={(value) => {
                                                if (!canManage) {
                                                    return;
                                                }

                                                setParameterStatus('');
                                                setPendingParameter({
                                                    code: parameter.code,
                                                    value,
                                                });
                                                parameterForm.transform(() => ({
                                                    code: parameter.code,
                                                    value,
                                                }));
                                                parameterForm.post(
                                                    '/settings/workflows/parameters',
                                                    wrapParameterFormRequest({
                                                        preserveScroll: true,
                                                        onSuccess: () =>
                                                            setParameterStatus(
                                                                'Pengaturan disimpan.',
                                                            ),
                                                        onFinish: () =>
                                                            setPendingParameter(
                                                                null,
                                                            ),
                                                    }),
                                                );
                                            }}
                                        />
                                    </Field>
                                </FormControl>
                            ))}
                        </FormGrid>
                        <p
                            role="status"
                            className="mt-3 text-sm text-muted-foreground"
                        >
                            {parameterForm.processing
                                ? 'Menyimpan pengaturan…'
                                : parameterStatus}
                        </p>
                    </PageDialogBody>
                </PageDialogContent>
            </PageDialog>

            <AlertDialog
                open={Boolean(changing)}
                onOpenChange={(open) => {
                    if (!open && !toggleForm.processing) {
                        setChanging(null);
                    }
                }}
            >
                <AlertDialogContent>
                    <AlertDialogHeader>
                        <AlertDialogTitle>
                            {changing?.enabled
                                ? 'Nonaktifkan workflow?'
                                : 'Aktifkan workflow?'}
                        </AlertDialogTitle>
                        <AlertDialogDescription>
                            {changing?.enabled
                                ? `Permintaan baru tidak lagi memakai alur “${changing.name}”. Proses yang sudah berjalan tetap memakai versinya.`
                                : `Permintaan baru yang sesuai akan memakai alur “${changing?.name ?? ''}”.`}
                        </AlertDialogDescription>
                    </AlertDialogHeader>
                    <>
                        {toggleFormRequestSummary}
                        <FormErrorHeader
                            errors={toggleForm.errors}
                            operation="Status workflow belum diubah"
                        />
                    </>
                    <AlertDialogFooter>
                        <AlertDialogCancel disabled={toggleForm.processing}>
                            Batal
                        </AlertDialogCancel>
                        <AlertDialogAction
                            disabled={toggleForm.processing}
                            onClick={(event) => {
                                event.preventDefault();

                                if (!changing || !canManage) {
                                    return;
                                }

                                toggleForm.transform(() => ({
                                    version: changing.version,
                                }));
                                toggleForm.post(
                                    `/settings/workflows/${changing.id}/${changing.enabled ? 'deactivate' : 'activate'}`,
                                    wrapToggleFormRequest({
                                        preserveScroll: true,
                                        onSuccess: () => setChanging(null),
                                    }),
                                );
                            }}
                        >
                            {toggleForm.processing
                                ? 'Memproses…'
                                : changing?.enabled
                                  ? 'Nonaktifkan'
                                  : 'Aktifkan'}
                        </AlertDialogAction>
                    </AlertDialogFooter>
                </AlertDialogContent>
            </AlertDialog>
        </>
    );
}
Workflows.layout = {
    breadcrumbs: [{ title: 'Workflow', href: '/settings/workflows' }],
};
