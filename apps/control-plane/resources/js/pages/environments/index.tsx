import { Button } from '@apperp/ui/button';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@apperp/ui/table';
import { Head, Link } from '@inertiajs/react';
import { Cloud, Server } from 'lucide-react';
import { InstallStateBadge, KindBadge, StatusBadge } from '@/components/badges';
import Shell from '@/components/shell';
import { progressDetail } from '@/lib/install-progress';
import type { InstallProgress } from '@/lib/install-progress';
import CreateDialog from '@/pages/environments/create-dialog';

type Row = {
    id: string;
    name: string;
    slug: string;
    kind: string;
    status: string;
    hosting: string;
    outboundAllowed: boolean;
    database: string;
    url: string | null;
    expiresAt: string | null;
    tenant: string;
    serverClient: InstallProgress | null;
    site: {
        id: string;
        serverAddress: string | null;
        address: string | null;
    } | null;
};

export default function Index({
    environments,
    tenant,
    kinds,
}: {
    environments: Row[];
    tenant: { id: string; name: string }[];
    kinds: string[];
}) {
    return (
        <Shell
            title="Lingkungan"
            description="Setiap tempat kerja yang dikenal sistem, beserta jenis dan tempat datanya berada."
            actions={<CreateDialog tenant={tenant} kinds={kinds} />}
        >
            <Head title="Lingkungan" />

            <div className="overflow-x-auto rounded-lg border bg-background">
                <Table>
                    <TableHeader>
                        <TableRow>
                            <TableHead>Nama</TableHead>
                            <TableHead>Tenant</TableHead>
                            <TableHead>Jenis</TableHead>
                            <TableHead>Berjalan di</TableHead>
                            <TableHead>Status</TableHead>
                            <TableHead>Alamat</TableHead>
                            <TableHead>Berakhir</TableHead>
                            <TableHead />
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        {environments.length === 0 && (
                            <TableRow>
                                <TableCell
                                    colSpan={8}
                                    className="py-10 text-center text-sm text-muted-foreground"
                                >
                                    Belum ada lingkungan yang tercatat.
                                </TableCell>
                            </TableRow>
                        )}
                        {environments.map((row) => (
                            <TableRow key={row.id}>
                                <TableCell className="font-medium">
                                    {row.name}
                                    <span className="ms-2 text-xs text-muted-foreground">
                                        {row.slug}
                                    </span>
                                </TableCell>
                                <TableCell>{row.tenant}</TableCell>
                                <TableCell>
                                    <KindBadge kind={row.kind} />
                                </TableCell>
                                <TableCell>
                                    {row.hosting === 'client_server' ? (
                                        <span className="inline-flex items-start gap-1.5 text-sm">
                                            <Server className="mt-0.5 size-3.5 shrink-0 text-muted-foreground" />
                                            <span>
                                                Server klien
                                                {row.site?.serverAddress && (
                                                    <span className="block font-mono text-xs text-muted-foreground">
                                                        {row.site.serverAddress}
                                                    </span>
                                                )}
                                            </span>
                                        </span>
                                    ) : (
                                        <span className="inline-flex items-center gap-1.5 text-sm">
                                            <Cloud className="size-3.5 shrink-0 text-muted-foreground" />
                                            Server kita
                                        </span>
                                    )}
                                </TableCell>
                                {/*
                                    Produksi di server klien tidak pernah disiapkan di sini, jadi status
                                    registry-nya menetap "Sedang disiapkan" selamanya — kata yang benar
                                    secara teknis dan salah bagi pembacanya. Yang ditampilkan untuknya
                                    keadaan pemasangan, dihitung `InstallProgress`.
                                */}
                                <TableCell>
                                    {row.serverClient ? (
                                        <div className="space-y-1">
                                            <InstallStateBadge
                                                state={row.serverClient.state}
                                            />
                                            {progressDetail(
                                                row.serverClient,
                                            ) && (
                                                <p className="text-xs text-muted-foreground">
                                                    {progressDetail(
                                                        row.serverClient,
                                                    )}
                                                </p>
                                            )}
                                        </div>
                                    ) : (
                                        <StatusBadge status={row.status} />
                                    )}
                                </TableCell>
                                {/*
                                    Alamat menggantikan nama database di kolom ini.

                                    Keduanya sama-sama teknis, tetapi cuma satu yang perlu dikirim
                                    ke pelanggan — dan nama database tetap terbaca di halaman
                                    rincian bagi yang memang mencarinya. Produksi di server klien
                                    tidak punya alamat di domain kita; alamatnya milik server itu.
                                */}
                                <TableCell className="max-w-[22rem] font-mono text-xs break-all">
                                    {row.hosting === 'client_server' ? (
                                        row.site?.address ? (
                                            <a
                                                href={row.site.address}
                                                target="_blank"
                                                rel="noreferrer"
                                                className="underline underline-offset-4"
                                            >
                                                {row.site.address.replace(
                                                    /^https?:\/\//,
                                                    '',
                                                )}
                                            </a>
                                        ) : (
                                            <span className="font-sans text-muted-foreground">
                                                Belum dicatat
                                            </span>
                                        )
                                    ) : row.url ? (
                                        <a
                                            href={row.url}
                                            target="_blank"
                                            rel="noreferrer"
                                            className="underline underline-offset-4"
                                        >
                                            {row.url.replace('https://', '')}
                                        </a>
                                    ) : (
                                        <span className="text-muted-foreground">
                                            {row.database}
                                        </span>
                                    )}
                                </TableCell>
                                <TableCell className="text-sm text-muted-foreground">
                                    {row.expiresAt ?? 'Tidak berakhir'}
                                </TableCell>
                                <TableCell className="text-end">
                                    <Button asChild size="sm" variant="ghost">
                                        <Link href={`/lingkungan/${row.id}`}>
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
