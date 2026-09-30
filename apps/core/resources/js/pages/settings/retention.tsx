import { Badge } from '@apperp/ui/badge';
import { Button } from '@apperp/ui/button';
import {
    Empty,
    EmptyDescription,
    EmptyHeader,
    EmptyTitle,
} from '@apperp/ui/empty';
import {
    Field,
    FieldDescription,
    FieldError,
    FieldLabel,
} from '@apperp/ui/field';
import { Input } from '@apperp/ui/input';
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
import Heading from '@/components/heading';
import { useDateTimeFormat } from '@/hooks/use-date-time';
import type { BreadcrumbItem } from '@/types/navigation';

type Policy = {
    code: string;
    caption: string;
    minimum_days: number;
    default_days: number | null;
    enabled: boolean;
    days: number;
    customized: boolean;
    optional: boolean;
    /** Versi baris setelan tenant; 0 selama masih memakai bawaan. */
    version: number;
};

type Entry = {
    id: string;
    policy: string;
    deleted_count: number;
    cutoff_at: string;
    status: 'success' | 'failed';
    message: string | null;
    created_at: string;
};

type Props = {
    canManage: boolean;
    policies: Policy[];
    entries: Entry[];
};

function PolicyRow({
    policy,
    canManage,
}: {
    policy: Policy;
    canManage: boolean;
}) {
    const form = useForm({
        enabled: policy.enabled,
        retention_days: String(policy.days),
    });
    const inputId = `retention-${policy.code}`;
    const editable = canManage && (!policy.optional || form.data.enabled);

    return (
        <form
            className="flex flex-col gap-4 rounded-lg border p-4 sm:flex-row sm:items-start sm:justify-between"
            onSubmit={(event) => {
                event.preventDefault();
                // Versi dari props saat dikirim: isian useForm tidak ikut diperbarui sesudah simpan.
                form.transform((data) => ({
                    ...data,
                    version: policy.version,
                }));
                form.put(`/settings/retention/${policy.code}`, {
                    preserveScroll: true,
                });
            }}
        >
            <div className="flex min-w-0 flex-col gap-1">
                <div className="flex flex-wrap items-center gap-2">
                    <span className="font-medium">{policy.caption}</span>
                    {policy.customized && (
                        <Badge variant="outline">Diubah</Badge>
                    )}
                </div>
                {policy.optional && (
                    <Field orientation="horizontal">
                        <Switch
                            id={`${inputId}-enabled`}
                            checked={form.data.enabled}
                            disabled={!canManage}
                            onCheckedChange={(on) =>
                                form.setData('enabled', on)
                            }
                        />
                        <FieldLabel htmlFor={`${inputId}-enabled`}>
                            Hapus otomatis data yang sudah lama
                        </FieldLabel>
                    </Field>
                )}
            </div>

            <div className="flex items-start gap-3">
                <Field data-invalid={Boolean(form.errors.retention_days)}>
                    <FieldLabel htmlFor={inputId}>
                        Disimpan selama (hari)
                    </FieldLabel>
                    <Input
                        id={inputId}
                        type="number"
                        inputMode="numeric"
                        min={policy.minimum_days}
                        className="w-32"
                        value={form.data.retention_days}
                        disabled={!editable}
                        onChange={(event) =>
                            form.setData('retention_days', event.target.value)
                        }
                    />
                    <FieldDescription>
                        Paling sedikit {policy.minimum_days} hari.
                    </FieldDescription>
                    <FieldError>{form.errors.retention_days}</FieldError>
                </Field>
                {canManage && (
                    <Button
                        type="submit"
                        className="mt-6"
                        disabled={form.processing}
                    >
                        Simpan
                    </Button>
                )}
            </div>
        </form>
    );
}

export default function RetentionSettings({
    canManage,
    policies,
    entries,
}: Props) {
    const formatTime = useDateTimeFormat();

    return (
        <>
            <Head title="Retensi data" />
            <main className="mx-auto flex w-full max-w-5xl min-w-0 flex-col gap-6 p-6">
                <Heading title="Retensi data" />
                <p className="text-sm text-muted-foreground">
                    Atur berapa lama catatan dan hasil ekspor disimpan sebelum
                    dihapus otomatis setiap hari. Data transaksi dan data master
                    tidak pernah dihapus dari sini.
                </p>

                <div className="flex flex-col gap-3">
                    {policies.map((policy) => (
                        <PolicyRow
                            key={policy.code}
                            policy={policy}
                            canManage={canManage}
                        />
                    ))}
                </div>

                <section className="flex flex-col gap-3">
                    <h2 className="text-base font-medium">
                        Penghapusan terakhir
                    </h2>
                    {entries.length === 0 ? (
                        <Empty className="border">
                            <EmptyHeader>
                                <EmptyTitle>
                                    Belum ada data yang dihapus
                                </EmptyTitle>
                                <EmptyDescription>
                                    Hasil penghapusan otomatis akan tampil di
                                    sini setelah ada data yang melewati masa
                                    simpannya.
                                </EmptyDescription>
                            </EmptyHeader>
                        </Empty>
                    ) : (
                        <div className="overflow-x-auto rounded-lg border">
                            <Table>
                                <TableHeader>
                                    <TableRow>
                                        <TableHead>Waktu</TableHead>
                                        <TableHead>Data</TableHead>
                                        <TableHead className="text-right">
                                            Baris dihapus
                                        </TableHead>
                                        <TableHead>Data sebelum</TableHead>
                                        <TableHead>Hasil</TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {entries.map((entry) => (
                                        <TableRow key={entry.id}>
                                            <TableCell>
                                                {formatTime(entry.created_at)}
                                            </TableCell>
                                            <TableCell>
                                                {entry.policy}
                                            </TableCell>
                                            <TableCell className="text-right">
                                                {entry.deleted_count.toLocaleString(
                                                    'id-ID',
                                                )}
                                            </TableCell>
                                            <TableCell>
                                                {formatTime(entry.cutoff_at)}
                                            </TableCell>
                                            <TableCell>
                                                {entry.status === 'success' ? (
                                                    <Badge variant="outline">
                                                        Berhasil
                                                    </Badge>
                                                ) : (
                                                    <span className="text-destructive">
                                                        {entry.message ??
                                                            'Gagal'}
                                                    </span>
                                                )}
                                            </TableCell>
                                        </TableRow>
                                    ))}
                                </TableBody>
                            </Table>
                        </div>
                    )}
                </section>
            </main>
        </>
    );
}

RetentionSettings.layout = {
    breadcrumbs: [
        { title: 'Retensi data', href: '/settings/retention' },
    ] satisfies BreadcrumbItem[],
};
