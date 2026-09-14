import { Button } from '@apperp/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@apperp/ui/dialog';
import { Input } from '@apperp/ui/input';
import { NativeSelect } from '@apperp/ui/native-select';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@apperp/ui/table';
import { Head, Link, useForm } from '@inertiajs/react';
import { useState } from 'react';
import type { FormEvent } from 'react';
import { SiteStateBadge } from '@/components/badges';
import Shell from '@/components/shell';
import { connectivityLabels, labelFor } from '@/lib/display';

type SiteRow = {
    id: string;
    name: string;
    tenant: string;
    edition: string;
    connectivity: string;
    state: string;
    reportedRelease: string | null;
    lastSeenAt: string | null;
    lastSeenVia: string | null;
};

function FieldError({ message }: { message?: string }) {
    return message ? (
        <p className="text-sm text-destructive">{message}</p>
    ) : null;
}

function CreateDialog({
    tenants,
}: {
    tenants: { id: string; name: string }[];
}) {
    const [open, setOpen] = useState(false);
    const { data, setData, post, processing, errors, reset } = useForm({
        tenant_id: tenants[0]?.id ?? '',
        name: '',
        edition: '',
        address: '',
        connectivity: 'online',
        update_window_start: '',
        update_window_end: '',
    });

    function submit(e: FormEvent) {
        e.preventDefault();
        post('/situs', {
            onSuccess: () => {
                reset();
                setOpen(false);
            },
        });
    }

    return (
        <Dialog open={open} onOpenChange={setOpen}>
            <DialogTrigger asChild>
                <Button disabled={tenants.length === 0}>Situs baru</Button>
            </DialogTrigger>
            <DialogContent>
                <form onSubmit={submit}>
                    <DialogHeader>
                        <DialogTitle>Situs baru</DialogTitle>
                        <DialogDescription>
                            Server milik klien yang dikelola dari sini lewat
                            agen. Mencatatnya belum memasang apa pun.
                        </DialogDescription>
                    </DialogHeader>

                    <div className="space-y-4 py-4">
                        <div className="space-y-2">
                            <NativeSelect
                                id="tenant_id"
                                label="Tenant"
                                value={data.tenant_id}
                                onChange={(e) =>
                                    setData('tenant_id', e.target.value)
                                }
                            >
                                {tenants.map((t) => (
                                    <option key={t.id} value={t.id}>
                                        {t.name}
                                    </option>
                                ))}
                            </NativeSelect>
                            <FieldError message={errors.tenant_id} />
                        </div>

                        <div className="space-y-2">
                            <Input
                                id="name"
                                label="Nama situs"
                                required
                                value={data.name}
                                onChange={(e) =>
                                    setData('name', e.target.value)
                                }
                            />
                            <FieldError message={errors.name} />
                        </div>

                        <div className="space-y-2">
                            <Input
                                id="edition"
                                label="Edisi"
                                required
                                value={data.edition}
                                onChange={(e) =>
                                    setData('edition', e.target.value)
                                }
                            />
                            <p className="text-xs text-muted-foreground">
                                Sama dengan nama berkas di folder editions,
                                misalnya apotek-sejahtera. Situs hanya dapat
                                memasang rilis dari edisi ini.
                            </p>
                            <FieldError message={errors.edition} />
                        </div>

                        <div className="space-y-2">
                            <NativeSelect
                                id="connectivity"
                                label="Internet keluar"
                                value={data.connectivity}
                                onChange={(e) =>
                                    setData('connectivity', e.target.value)
                                }
                            >
                                <option value="online">
                                    Online — agen menarik perintah sendiri
                                </option>
                                <option value="offline">
                                    Offline — paket dibawa dengan flashdisk
                                </option>
                            </NativeSelect>
                            <FieldError message={errors.connectivity} />
                        </div>

                        <div className="space-y-2">
                            <Input
                                id="address"
                                label="Alamat aplikasi (boleh kosong)"
                                type="url"
                                value={data.address}
                                onChange={(e) =>
                                    setData('address', e.target.value)
                                }
                            />
                            <FieldError message={errors.address} />
                        </div>

                        <div className="grid grid-cols-2 gap-3">
                            <div className="space-y-2">
                                <Input
                                    id="update_window_start"
                                    label="Jendela mulai"
                                    type="time"
                                    value={data.update_window_start}
                                    onChange={(e) =>
                                        setData(
                                            'update_window_start',
                                            e.target.value,
                                        )
                                    }
                                />
                                <FieldError
                                    message={errors.update_window_start}
                                />
                            </div>
                            <div className="space-y-2">
                                <Input
                                    id="update_window_end"
                                    label="Jendela selesai"
                                    type="time"
                                    value={data.update_window_end}
                                    onChange={(e) =>
                                        setData(
                                            'update_window_end',
                                            e.target.value,
                                        )
                                    }
                                />
                                <FieldError
                                    message={errors.update_window_end}
                                />
                            </div>
                        </div>
                        <p className="text-xs text-muted-foreground">
                            Jam pembaruan yang disepakati dengan klien, waktu
                            Jakarta. Kosongkan keduanya bila pembaruan boleh
                            kapan saja.
                        </p>
                    </div>

                    <DialogFooter>
                        <Button type="submit" disabled={processing}>
                            Catat situs
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

/**
 * Daftar server milik klien on-prem yang dikelola dari konsol ini.
 *
 * Kolom "Terakhir terlihat" menyebut asalnya. Situs offline hanya dikenal lewat file laporan yang
 * dibawa pulang, dan tanggal itu bisa berhari-hari lalu tanpa ada yang salah — layar yang
 * menyamakannya dengan heartbeat akan membuat setiap situs offline tampak rusak.
 */
export default function Index({
    sites,
    tenants,
}: {
    sites: SiteRow[];
    tenants: { id: string; name: string }[];
}) {
    return (
        <Shell
            title="Situs"
            description="Server milik klien yang dikelola lewat agen: keadaannya, rilis yang terpasang, dan kapan terakhir melapor."
            actions={<CreateDialog tenants={tenants} />}
        >
            <Head title="Situs" />

            <div className="overflow-x-auto rounded-lg border bg-background">
                <Table>
                    <TableHeader>
                        <TableRow>
                            <TableHead>Nama</TableHead>
                            <TableHead>Tenant</TableHead>
                            <TableHead>Edisi</TableHead>
                            <TableHead>Internet</TableHead>
                            <TableHead>Keadaan</TableHead>
                            <TableHead>Rilis terpasang</TableHead>
                            <TableHead>Terakhir terlihat</TableHead>
                            <TableHead />
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        {sites.length === 0 && (
                            <TableRow>
                                <TableCell
                                    colSpan={8}
                                    className="py-10 text-center text-sm text-muted-foreground"
                                >
                                    Belum ada situs yang tercatat.
                                </TableCell>
                            </TableRow>
                        )}
                        {sites.map((row) => (
                            <TableRow key={row.id}>
                                <TableCell className="font-medium">
                                    {row.name}
                                </TableCell>
                                <TableCell>{row.tenant}</TableCell>
                                <TableCell className="font-mono text-xs">
                                    {row.edition}
                                </TableCell>
                                <TableCell>
                                    {labelFor(
                                        connectivityLabels,
                                        row.connectivity,
                                    )}
                                </TableCell>
                                <TableCell>
                                    <SiteStateBadge state={row.state} />
                                </TableCell>
                                <TableCell className="font-mono text-xs">
                                    {row.reportedRelease ?? '—'}
                                </TableCell>
                                <TableCell className="text-sm text-muted-foreground">
                                    {row.lastSeenAt
                                        ? `${row.lastSeenAt} (${row.lastSeenVia === 'file' ? 'file laporan' : 'heartbeat'})`
                                        : 'Belum pernah'}
                                </TableCell>
                                <TableCell className="text-end">
                                    <Button asChild size="sm" variant="ghost">
                                        <Link href={`/situs/${row.id}`}>
                                            Rincian
                                        </Link>
                                    </Button>
                                </TableCell>
                            </TableRow>
                        ))}
                    </TableBody>
                </Table>
            </div>
        </Shell>
    );
}
