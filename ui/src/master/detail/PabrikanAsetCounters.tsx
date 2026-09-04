import { Empty, EmptyDescription } from '@apperp/ui/empty';
import { Input } from '@apperp/ui/input';
import { PabrikanAsetDetail } from './pabrikanAsetDetail';

export default function PabrikanAsetCounters({
    detail,
    loading,
    error,
}: {
    detail: PabrikanAsetDetail | null;
    loading: boolean;
    error: string;
}) {
    if (loading)
        return <p className="text-sm text-muted-foreground">Memuat jumlah model dan aset…</p>;
    if (error) return <p className="text-sm text-destructive">{error}</p>;
    if (!detail)
        return (
            <Empty>
                <EmptyDescription>Jumlah turunan belum tersedia.</EmptyDescription>
            </Empty>
        );

    return (
        <div className="grid gap-4 pt-1 sm:grid-cols-2">
            <Input id="jumlah-model" label="Model" value={detail.model_count ?? '–'} disabled />
            <Input id="jumlah-aset" label="Aset" value={detail.asset_count ?? '–'} disabled />
        </div>
    );
}
