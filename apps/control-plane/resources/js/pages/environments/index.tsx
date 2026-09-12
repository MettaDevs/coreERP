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
import { KindBadge, StatusBadge } from '@/components/badges';
import Shell from '@/components/shell';
import CreateDialog from '@/pages/environments/create-dialog';

type Row = {
    id: string;
    name: string;
    slug: string;
    kind: string;
    status: string;
    outboundAllowed: boolean;
    database: string;
    expiresAt: string | null;
    tenant: string;
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
                            <TableHead>Pelanggan</TableHead>
                            <TableHead>Jenis</TableHead>
                            <TableHead>Status</TableHead>
                            <TableHead>Database</TableHead>
                            <TableHead>Berakhir</TableHead>
                            <TableHead />
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        {environments.length === 0 && (
                            <TableRow>
                                <TableCell
                                    colSpan={7}
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
                                    <StatusBadge status={row.status} />
                                </TableCell>
                                <TableCell className="font-mono text-xs">
                                    {row.database}
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
