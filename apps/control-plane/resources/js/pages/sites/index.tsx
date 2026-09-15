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
import { InstallStateBadge } from '@/components/badges';
import Shell from '@/components/shell';
import { progressDetail } from '@/lib/install-progress';
import type { InstallProgress } from '@/lib/install-progress';

type SiteRow = {
    id: string;
    name: string;
    tenant: string;
    environment: { id: string; name: string } | null;
    progress: InstallProgress;
    lastSeenAt: string | null;
};

/**
 * Ringkasan server milik klien on-prem yang dikelola dari konsol ini.
 *
 * Tidak ada tombol "Situs baru". Server klien disiapkan dari halaman lingkungan produksinya, tempat
 * perintah pasang dan progresnya juga tampil; setiap baris di sini menaut ke sana. Situs lama yang
 * didaftarkan sebelum 15 September 2026 tidak menyebut lingkungan dan hanya punya rincian situsnya.
 */
export default function Index({ sites }: { sites: SiteRow[] }) {
    return (
        <Shell
            title="Situs"
            description="Server milik klien yang dikelola lewat agen. Menyiapkan server klien dan membuat perintah pasangnya dikerjakan dari halaman lingkungan produksi tenant."
        >
            <Head title="Situs" />

            <div className="overflow-x-auto rounded-lg border bg-background">
                <Table>
                    <TableHeader>
                        <TableRow>
                            <TableHead>Nama</TableHead>
                            <TableHead>Tenant</TableHead>
                            <TableHead>Lingkungan</TableHead>
                            <TableHead>Keadaan</TableHead>
                            <TableHead>Terakhir terlihat</TableHead>
                            <TableHead />
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        {sites.length === 0 && (
                            <TableRow>
                                <TableCell
                                    colSpan={6}
                                    className="py-10 text-center text-sm text-muted-foreground"
                                >
                                    Belum ada situs. Server klien disiapkan dari
                                    halaman lingkungan produksi yang berjalan di
                                    server klien.
                                </TableCell>
                            </TableRow>
                        )}
                        {sites.map((row) => (
                            <TableRow key={row.id}>
                                <TableCell className="font-medium">
                                    {row.name}
                                </TableCell>
                                <TableCell>{row.tenant}</TableCell>
                                <TableCell>
                                    {row.environment ? (
                                        <Link
                                            href={`/lingkungan/${row.environment.id}`}
                                            className="underline underline-offset-4"
                                        >
                                            {row.environment.name}
                                        </Link>
                                    ) : (
                                        <span className="text-xs text-muted-foreground">
                                            Didaftarkan tanpa lingkungan
                                        </span>
                                    )}
                                </TableCell>
                                <TableCell>
                                    <div className="space-y-1">
                                        <InstallStateBadge
                                            state={row.progress.state}
                                        />
                                        {progressDetail(row.progress) && (
                                            <p className="text-xs text-muted-foreground">
                                                {progressDetail(row.progress)}
                                            </p>
                                        )}
                                    </div>
                                </TableCell>
                                <TableCell className="text-sm text-muted-foreground">
                                    {row.lastSeenAt ?? 'Belum pernah'}
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
