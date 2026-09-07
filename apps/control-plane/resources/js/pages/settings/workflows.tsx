import { Badge } from '@apperp/ui/badge';
import { Button } from '@apperp/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@apperp/ui/card';
import { Field, FieldError } from '@apperp/ui/field';
import { Input } from '@apperp/ui/input';
import { NativeSelect } from '@apperp/ui/native-select';
import { Head, Link, useForm } from '@inertiajs/react';
import {
    Check,
    GitBranch,
    Plus,
    Power,
    Workflow as WorkflowIcon,
} from 'lucide-react';
import Heading from '@/components/heading';

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
    status: 'draft' | 'active' | 'inactive';
    type_name: string;
    app_name: string;
    scope: 'tenant' | 'legal_entity';
    legal_entity_id: string | null;
};
type Props = {
    canManage: boolean;
    workflowTypes: WorkflowType[];
    legalEntities: LegalEntity[];
    workflows: Workflow[];
};

export default function Workflows({
    canManage,
    workflowTypes,
    legalEntities,
    workflows,
}: Props) {
    const createForm = useForm({
        workflow_type_id: workflowTypes[0]?.id ?? '',
        name: '',
        legal_entity_id: '',
    });
    const toggleForm = useForm({});

    const selectedType = workflowTypes.find(
        (type) => type.id === createForm.data.workflow_type_id,
    );
    const needsLegalEntity = selectedType?.scope !== 'tenant';

    return (
        <>
            <Head title="Workflow" />
            <main className="mx-auto flex w-full max-w-7xl min-w-0 flex-col gap-6 p-6">
                <Heading
                    title="Workflow"
                    description="Susun langkah kerja aplikasi, mulai dari tugas, persetujuan, keputusan kondisi, sampai selesai."
                    icon={WorkflowIcon}
                />

                {/* Card Form Buat Workflow (Sesuai Tampilan Awal) */}
                <Card className="overflow-hidden shadow-xs">
                    <CardHeader className="border-b border-border bg-card/50 px-6 py-4">
                        <div className="flex items-center gap-3">
                            <div className="flex size-9 shrink-0 items-center justify-center rounded-xl bg-primary/10 text-primary">
                                <Plus className="size-15" />
                            </div>
                            <div className="flex flex-row items-center gap-3">
                                <CardTitle className="text-base font-bold text-foreground">
                                    Buat Workflow Baru
                                </CardTitle>
                                <CardDescription className="mt-0.5 text-xs text-muted-foreground">
                                    Workflow baru dimulai sebagai draf. Setelah
                                    disimpan, susun alurnya di canvas.
                                </CardDescription>
                            </div>
                        </div>
                    </CardHeader>
                    <CardContent className="p-6">
                        {workflowTypes.length === 0 ? (
                            <p className="text-xs text-muted-foreground italic">
                                Belum ada proses workflow dari aplikasi yang
                                terdaftar.
                            </p>
                        ) : (
                            <form
                                className="grid gap-4 md:grid-cols-2"
                                onSubmit={(event) => {
                                    event.preventDefault();
                                    createForm.post('/settings/workflows', {
                                        onSuccess: () =>
                                            createForm.reset('name'),
                                    });
                                }}
                            >
                                <Field>
                                    <NativeSelect
                                        label="Proses Aplikasi"
                                        value={createForm.data.workflow_type_id}
                                        disabled={!canManage}
                                        onChange={(event) =>
                                            createForm.setData(
                                                'workflow_type_id',
                                                event.target.value,
                                            )
                                        }
                                    >
                                        {workflowTypes.map((type) => (
                                            <option
                                                key={type.id}
                                                value={type.id}
                                            >
                                                {type.app_name} — {type.name}
                                            </option>
                                        ))}
                                    </NativeSelect>
                                    <FieldError>
                                        {createForm.errors.workflow_type_id}
                                    </FieldError>
                                </Field>

                                <Field>
                                    <Input
                                        label="Nama Workflow"
                                        value={createForm.data.name}
                                        disabled={!canManage}
                                        onChange={(event) =>
                                            createForm.setData(
                                                'name',
                                                event.target.value,
                                            )
                                        }
                                        placeholder="Alur pemeriksaan usulan aset"
                                    />
                                    <FieldError>
                                        {createForm.errors.name}
                                    </FieldError>
                                </Field>

                                {needsLegalEntity && (
                                    <Field className="md:col-span-2">
                                        <div className="grid gap-4 md:grid-cols-2">
                                            <div>
                                                <NativeSelect
                                                    label="Entitas Legal"
                                                    value={
                                                        createForm.data
                                                            .legal_entity_id
                                                    }
                                                    disabled={!canManage}
                                                    onChange={(event) =>
                                                        createForm.setData(
                                                            'legal_entity_id',
                                                            event.target.value,
                                                        )
                                                    }
                                                >
                                                    <option value="">
                                                        Pilih entitas legal
                                                    </option>
                                                    {legalEntities.map(
                                                        (entity) => (
                                                            <option
                                                                key={entity.id}
                                                                value={
                                                                    entity.id
                                                                }
                                                            >
                                                                {entity.name}
                                                            </option>
                                                        ),
                                                    )}
                                                </NativeSelect>
                                                <FieldError>
                                                    {
                                                        createForm.errors
                                                            .legal_entity_id
                                                    }
                                                </FieldError>
                                            </div>
                                        </div>
                                    </Field>
                                )}

                                <div className="flex flex-wrap items-center justify-between gap-4 border-t border-border/40 pt-2 md:col-span-2">
                                    <p className="text-xs text-muted-foreground">
                                        {needsLegalEntity
                                            ? 'Semua dokumen proses ini akan mengikuti entitas legal yang dipilih.'
                                            : 'Workflow ini berlaku untuk seluruh bisnis (scope tenant).'}
                                    </p>
                                    <Button
                                        type="submit"
                                        variant="default"
                                        size="sm"
                                        disabled={
                                            !canManage ||
                                            createForm.processing ||
                                            createForm.data.name.trim() ===
                                                '' ||
                                            (needsLegalEntity &&
                                                createForm.data
                                                    .legal_entity_id === '')
                                        }
                                        className="shrink-0 px-5 text-xs font-medium shadow-xs"
                                    >
                                        <Plus className="mr-1.5 size-3.5" />{' '}
                                        Buat Workflow
                                    </Button>
                                </div>
                            </form>
                        )}
                    </CardContent>
                </Card>

                {/* Card Daftar Workflow (Desain Modern yang Disukai) */}
                <Card className="overflow-hidden shadow-xs">
                    <CardHeader className="border-b border-border bg-card/50 px-6 py-4">
                        <div className="flex items-center justify-between gap-4">
                            <div className="flex flex-row items-center gap-3">
                                <CardTitle className="text-base font-bold whitespace-nowrap text-foreground">
                                    Daftar Workflow Terpasang
                                </CardTitle>
                                <CardDescription className="text-xs text-muted-foreground">
                                    Kelola alur persetujuan, buka canvas untuk
                                    mengedit alur, atau ubah status
                                    keaktifannya.
                                </CardDescription>
                            </div>
                        </div>
                    </CardHeader>
                    <CardContent className="p-0">
                        {workflows.length === 0 ? (
                            <div className="flex flex-col items-center justify-center p-12 text-center">
                                <div className="mb-3 flex size-12 items-center justify-center rounded-2xl bg-muted/60 text-muted-foreground">
                                    <WorkflowIcon className="size-6" />
                                </div>
                                <h3 className="text-sm font-semibold text-foreground">
                                    Belum ada workflow
                                </h3>
                                <p className="mt-1 max-w-sm text-xs text-muted-foreground">
                                    Gunakan form{' '}
                                    <span className="font-semibold text-foreground">
                                        Buat Workflow Baru
                                    </span>{' '}
                                    di atas untuk membuat alur persetujuan
                                    pertama Anda.
                                </p>
                            </div>
                        ) : (
                            <div className="divide-y divide-border/60">
                                {workflows.map((workflow) => {
                                    const isActive =
                                        workflow.status === 'active';
                                    const isDraft = workflow.status === 'draft';

                                    return (
                                        <div
                                            key={workflow.id}
                                            className="flex flex-wrap items-center justify-between gap-4 p-4 transition-colors hover:bg-accent/30"
                                        >
                                            <div className="flex min-w-0 flex-1 items-center gap-3.5">
                                                <div className="flex size-10 shrink-0 items-center justify-center rounded-xl border border-primary/20 bg-primary/10 text-primary">
                                                    <GitBranch className="size-5" />
                                                </div>
                                                <div className="min-w-0">
                                                    <div className="flex items-center gap-2">
                                                        <h4 className="truncate text-sm font-bold text-foreground">
                                                            {workflow.name}
                                                        </h4>
                                                        <Badge variant="outline">
                                                            {isActive
                                                                ? 'Aktif'
                                                                : isDraft
                                                                  ? 'Draf'
                                                                  : 'Nonaktif'}
                                                        </Badge>
                                                    </div>
                                                    <p className="mt-0.5 flex items-center gap-1.5 truncate text-xs text-muted-foreground">
                                                        <span className="font-medium text-foreground">
                                                            {workflow.app_name}
                                                        </span>
                                                        <span>•</span>
                                                        <span>
                                                            {workflow.type_name}
                                                        </span>
                                                    </p>
                                                </div>
                                            </div>

                                            <div className="flex shrink-0 items-center gap-2">
                                                <Button
                                                    asChild
                                                    size="sm"
                                                    variant="default"
                                                    className="text-xs font-medium shadow-2xs"
                                                >
                                                    <Link
                                                        href={`/settings/workflows/${workflow.id}/edit`}
                                                    >
                                                        <GitBranch className="mr-1.5 size-3.5" />
                                                        Buka Canvas Alur
                                                    </Link>
                                                </Button>

                                                {isActive && (
                                                    <Button
                                                        size="sm"
                                                        variant="outline"
                                                        disabled={
                                                            !canManage ||
                                                            toggleForm.processing
                                                        }
                                                        className="text-xs text-muted-foreground hover:text-foreground"
                                                        onClick={() =>
                                                            toggleForm.post(
                                                                `/settings/workflows/${workflow.id}/deactivate`,
                                                                {
                                                                    preserveScroll: true,
                                                                },
                                                            )
                                                        }
                                                    >
                                                        <Power className="mr-1.5 size-3.5" />
                                                        Nonaktifkan
                                                    </Button>
                                                )}

                                                {workflow.status ===
                                                    'inactive' && (
                                                    <Button
                                                        size="sm"
                                                        variant="outline"
                                                        disabled={
                                                            !canManage ||
                                                            toggleForm.processing
                                                        }
                                                        className="text-xs"
                                                        onClick={() =>
                                                            toggleForm.post(
                                                                `/settings/workflows/${workflow.id}/activate`,
                                                                {
                                                                    preserveScroll: true,
                                                                },
                                                            )
                                                        }
                                                    >
                                                        <Check className="mr-1.5 size-3.5" />
                                                        Aktifkan
                                                    </Button>
                                                )}
                                            </div>
                                        </div>
                                    );
                                })}
                            </div>
                        )}
                    </CardContent>
                </Card>
            </main>
        </>
    );
}
