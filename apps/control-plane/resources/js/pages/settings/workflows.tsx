import { Head, Link, useForm } from '@inertiajs/react';
import { Button } from '@apperp/ui/button';
import {
    Card,
    CardAction,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@apperp/ui/card';
import { Field, FieldDescription, FieldError } from '@apperp/ui/field';
import { Input } from '@apperp/ui/input';
import { NativeSelect } from '@apperp/ui/native-select';
import { Badge } from '@apperp/ui/badge';
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
    const form = useForm({
        workflow_type_id: workflowTypes[0]?.id ?? '',
        name: '',
        legal_entity_id: '',
    });
    const selectedType = workflowTypes.find(
        (type) => type.id === form.data.workflow_type_id,
    );
    const needsLegalEntity = selectedType?.scope !== 'tenant';

    return (
        <>
            <Head title="Workflow" />
            <main className="mx-auto flex w-full max-w-7xl min-w-0 flex-col gap-6 p-6">
                <Heading
                    title="Workflow"
                    description="Susun langkah kerja aplikasi, mulai dari tugas, persetujuan, keputusan kondisi, sampai selesai."
                />
                <Card>
                    <CardHeader>
                        <CardTitle>Buat workflow</CardTitle>
                        <CardDescription>
                            Workflow baru dimulai sebagai draf. Setelah disimpan,
                            susun alurnya di canvas.
                        </CardDescription>
                    </CardHeader>
                    <CardContent>
                        {workflowTypes.length === 0 ? (
                            <p className="text-sm text-muted-foreground">
                                Belum ada proses workflow dari aplikasi yang
                                terdaftar.
                            </p>
                        ) : (
                            <form
                                className="grid gap-4 md:grid-cols-2"
                                onSubmit={(event) => {
                                    event.preventDefault();
                                    form.post('/settings/workflows');
                                }}
                            >
                                <Field>
                                    <NativeSelect
                                        label="Proses"
                                        value={form.data.workflow_type_id}
                                        disabled={!canManage}
                                        onChange={(event) =>
                                            form.setData(
                                                'workflow_type_id',
                                                event.target.value,
                                            )
                                        }
                                    >
                                        {workflowTypes.map((type) => (
                                            <option key={type.id} value={type.id}>
                                                {type.app_name} — {type.name}
                                            </option>
                                        ))}
                                    </NativeSelect>
                                    <FieldError>
                                        {form.errors.workflow_type_id}
                                    </FieldError>
                                </Field>
                                <Field>
                                    <Input
                                        label="Nama workflow"
                                        value={form.data.name}
                                        disabled={!canManage}
                                        onChange={(event) =>
                                            form.setData(
                                                'name',
                                                event.target.value,
                                            )
                                        }
                                        placeholder="Alur pemeriksaan usulan aset"
                                    />
                                    <FieldError>
                                        {form.errors.name}
                                    </FieldError>
                                </Field>
                                {needsLegalEntity && (
                                    <Field>
                                        <NativeSelect
                                            label="Entitas legal"
                                            value={form.data.legal_entity_id}
                                            disabled={!canManage}
                                            onChange={(event) =>
                                                form.setData(
                                                    'legal_entity_id',
                                                    event.target.value,
                                                )
                                            }
                                        >
                                            <option value="">
                                                Pilih entitas legal
                                            </option>
                                            {legalEntities.map((entity) => (
                                                <option
                                                    key={entity.id}
                                                    value={entity.id}
                                                >
                                                    {entity.name}
                                                </option>
                                            ))}
                                        </NativeSelect>
                                        <FieldError>
                                            {form.errors.legal_entity_id}
                                        </FieldError>
                                        <FieldDescription>
                                            Semua dokumen proses ini akan
                                            mengikuti entitas legal yang sama.
                                        </FieldDescription>
                                    </Field>
                                )}
                                <div className="flex items-end">
                                    <Button
                                        type="submit"
                                        disabled={
                                            !canManage ||
                                            form.processing ||
                                            form.data.name.trim() === '' ||
                                            (needsLegalEntity &&
                                                form.data.legal_entity_id === '')
                                        }
                                    >
                                        Buat workflow
                                    </Button>
                                </div>
                            </form>
                        )}
                    </CardContent>
                </Card>
                <Card>
                    <CardHeader>
                        <CardTitle>Workflow yang sudah dibuat</CardTitle>
                        <CardDescription>
                            Buka alur untuk menambah langkah atau mengaktifkan
                            versi yang sudah siap.
                        </CardDescription>
                        <CardAction>
                            <Badge variant="secondary">
                                {workflows.length} workflow
                            </Badge>
                        </CardAction>
                    </CardHeader>
                    <CardContent className="space-y-2">
                        {workflows.length === 0 ? (
                            <p className="text-sm text-muted-foreground">
                                Belum ada workflow yang dibuat.
                            </p>
                        ) : (
                            workflows.map((workflow) => (
                                <div
                                    key={workflow.id}
                                    className="flex flex-wrap items-center justify-between gap-3 rounded-md border p-3"
                                >
                                    <div>
                                        <p className="font-medium">
                                            {workflow.name}
                                        </p>
                                        <p className="text-sm text-muted-foreground">
                                            {workflow.app_name} —{' '}
                                            {workflow.type_name}
                                        </p>
                                    </div>
                                    <div className="flex items-center gap-2">
                                        <Badge variant={workflow.status === 'active' ? 'default' : 'secondary'}>
                                            {workflow.status === 'active' ? 'Aktif' : workflow.status === 'inactive' ? 'Nonaktif' : 'Draf'}
                                        </Badge>
                                        <Button asChild size="sm" variant="outline">
                                            <Link
                                                href={`/settings/workflows/${workflow.id}/edit`}
                                            >
                                                Buka alur
                                            </Link>
                                        </Button>
                                        {workflow.status === 'active' && <Button size="sm" variant="outline" disabled={!canManage} onClick={() => form.post(`/settings/workflows/${workflow.id}/deactivate`, { preserveScroll: true })}>Nonaktifkan</Button>}
                                        {workflow.status === 'inactive' && <Button size="sm" disabled={!canManage} onClick={() => form.post(`/settings/workflows/${workflow.id}/activate`, { preserveScroll: true })}>Aktifkan</Button>}
                                    </div>
                                </div>
                            ))
                        )}
                    </CardContent>
                </Card>
            </main>
        </>
    );
}
