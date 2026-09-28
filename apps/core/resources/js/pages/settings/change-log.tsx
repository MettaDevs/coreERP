import { Badge } from '@apperp/ui/badge';
import { Button } from '@apperp/ui/button';
import { Checkbox } from '@apperp/ui/checkbox';
import {
    CollapsibleSection,
    CollapsibleSectionGroup,
} from '@apperp/ui/collapsible-section';
import {
    Empty,
    EmptyDescription,
    EmptyHeader,
    EmptyTitle,
} from '@apperp/ui/empty';
import { Field, FieldHint, FieldLabel } from '@apperp/ui/field';
import { Switch } from '@apperp/ui/switch';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@apperp/ui/table';
import { Head, useForm } from '@inertiajs/react';
import { CircleHelp } from 'lucide-react';
import Heading from '@/components/heading';
import type { BreadcrumbItem } from '@/types/navigation';

type Operation = 'log_insertion' | 'log_modification' | 'log_deletion';

type Flags = Record<Operation, boolean>;

type LoggedField = Flags & {
    field_name: string;
    field_caption: string;
};

type LoggedTable = Flags & {
    table_name: string;
    table_caption: string;
    customized: boolean;
    fields: LoggedField[];
};

type Props = {
    canManage: boolean;
    tables: LoggedTable[];
};

const OPERATIONS: { key: Operation; label: string; column: string }[] = [
    { key: 'log_insertion', label: 'Catat saat dibuat', column: 'Dibuat' },
    { key: 'log_modification', label: 'Catat saat diubah', column: 'Diubah' },
    { key: 'log_deletion', label: 'Catat saat dihapus', column: 'Dihapus' },
];

function summary(table: LoggedTable): string {
    const active = OPERATIONS.filter((operation) => table[operation.key]).map(
        (operation) => operation.column.toLowerCase(),
    );
    const fields = table.fields.filter((field) =>
        OPERATIONS.some(
            (operation) => table[operation.key] && field[operation.key],
        ),
    ).length;

    return active.length === 0
        ? 'Tidak dicatat'
        : `Dicatat saat ${active.join(', ')} · ${fields} field`;
}

function TableSetup({
    table,
    canManage,
}: {
    table: LoggedTable;
    canManage: boolean;
}) {
    const form = useForm({
        log_insertion: table.log_insertion,
        log_modification: table.log_modification,
        log_deletion: table.log_deletion,
        fields: Object.fromEntries(
            table.fields.map((field) => [
                field.field_name,
                {
                    log_insertion: field.log_insertion,
                    log_modification: field.log_modification,
                    log_deletion: field.log_deletion,
                },
            ]),
        ) as Record<string, Flags>,
    });

    function toggleField(field: string, operation: Operation, on: boolean) {
        form.setData('fields', {
            ...form.data.fields,
            [field]: { ...form.data.fields[field], [operation]: on },
        });
    }

    return (
        <form
            className="space-y-4"
            onSubmit={(event) => {
                event.preventDefault();
                form.put(`/settings/change-log/${table.table_name}`, {
                    preserveScroll: true,
                });
            }}
        >
            <div className="grid gap-3 sm:grid-cols-3">
                {OPERATIONS.map((operation) => (
                    <Field key={operation.key} orientation="horizontal">
                        <Switch
                            id={`${table.table_name}-${operation.key}`}
                            checked={form.data[operation.key]}
                            disabled={!canManage}
                            onCheckedChange={(on) =>
                                form.setData(operation.key, on)
                            }
                        />
                        <FieldLabel
                            htmlFor={`${table.table_name}-${operation.key}`}
                        >
                            {operation.label}
                        </FieldLabel>
                        {operation.key === 'log_deletion' && (
                            <FieldHint hint="Data di aplikasi ini biasanya diarsipkan, bukan dihapus. Pengarsipan tercatat sebagai perubahan field Diarsipkan.">
                                <button
                                    type="button"
                                    aria-label="Bantuan pencatatan penghapusan"
                                    className="text-muted-foreground"
                                >
                                    <CircleHelp className="size-4" />
                                </button>
                            </FieldHint>
                        )}
                    </Field>
                ))}
            </div>

            <div className="overflow-x-auto rounded-lg border">
                <Table>
                    <TableHeader>
                        <TableRow>
                            <TableHead>Field</TableHead>
                            {OPERATIONS.map((operation) => (
                                <TableHead
                                    className="w-24 text-center"
                                    key={operation.key}
                                >
                                    {operation.column}
                                </TableHead>
                            ))}
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        {table.fields.map((field) => (
                            <TableRow key={field.field_name}>
                                <TableCell>{field.field_caption}</TableCell>
                                {OPERATIONS.map((operation) => (
                                    <TableCell
                                        className="text-center"
                                        key={operation.key}
                                    >
                                        <Checkbox
                                            aria-label={`${field.field_caption}, ${operation.column.toLowerCase()}`}
                                            checked={
                                                form.data.fields[
                                                    field.field_name
                                                ]?.[operation.key] ?? false
                                            }
                                            disabled={
                                                !canManage ||
                                                !form.data[operation.key]
                                            }
                                            onCheckedChange={(on) =>
                                                toggleField(
                                                    field.field_name,
                                                    operation.key,
                                                    on === true,
                                                )
                                            }
                                        />
                                    </TableCell>
                                ))}
                            </TableRow>
                        ))}
                    </TableBody>
                </Table>
            </div>

            {canManage && (
                <div className="flex justify-end">
                    <Button type="submit" disabled={form.processing}>
                        Simpan
                    </Button>
                </div>
            )}
        </form>
    );
}

export default function ChangeLogSettings({ canManage, tables }: Props) {
    return (
        <>
            <Head title="Riwayat perubahan" />
            <main className="mx-auto flex w-full max-w-5xl min-w-0 flex-col gap-6 p-6">
                <Heading
                    title="Riwayat perubahan"
                    description="Pilih data dan field yang perubahannya dicatat. Riwayatnya tampil di halaman data itu: siapa mengubah, dari apa menjadi apa, dan kapan. Perubahan peran, hak akses, dan setelan di halaman ini selalu dicatat."
                />

                {tables.length === 0 ? (
                    <Empty className="border">
                        <EmptyHeader>
                            <EmptyTitle>
                                Belum ada data yang dapat dicatat
                            </EmptyTitle>
                            <EmptyDescription>
                                Aplikasi yang terpasang belum menyediakan data
                                untuk riwayat perubahan.
                            </EmptyDescription>
                        </EmptyHeader>
                    </Empty>
                ) : (
                    <CollapsibleSectionGroup>
                        {tables.map((table) => (
                            <CollapsibleSection
                                key={table.table_name}
                                value={table.table_name}
                                title={table.table_caption}
                                summary={
                                    <>
                                        {summary(table)}
                                        {table.customized && (
                                            <Badge
                                                variant="outline"
                                                className="ml-2"
                                            >
                                                Diubah
                                            </Badge>
                                        )}
                                    </>
                                }
                            >
                                <TableSetup
                                    table={table}
                                    canManage={canManage}
                                />
                            </CollapsibleSection>
                        ))}
                    </CollapsibleSectionGroup>
                )}
            </main>
        </>
    );
}

ChangeLogSettings.layout = {
    breadcrumbs: [
        { title: 'Riwayat perubahan', href: '/settings/change-log' },
    ] satisfies BreadcrumbItem[],
};
