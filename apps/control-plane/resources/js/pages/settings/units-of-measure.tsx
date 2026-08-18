import { Head, router } from '@inertiajs/react';
import { ChevronDown, CircleHelp, Plus, Save } from 'lucide-react';
import { useMemo, useState } from 'react';
import { Button } from '@apperp/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@apperp/ui/card';
import {
    Collapsible,
    CollapsibleContent,
    CollapsibleTrigger,
} from '@apperp/ui/collapsible';
import {
    CollapsibleSection,
    CollapsibleSectionGroup,
} from '@apperp/ui/collapsible-section';
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
import { Tooltip, TooltipContent, TooltipTrigger } from '@apperp/ui/tooltip';
import type { BreadcrumbItem } from '@/types/navigation';

type Choice = { id: string; code: string; name: string; active: boolean };
type Unit = Choice & {
    symbol: string | null;
    decimal_places: number;
    uom_class_id: string;
    uom_system_id: string | null;
    deleted_at: string | null;
};
type Conversion = {
    id: string;
    from_unit_id: string;
    to_unit_id: string;
    factor: string | number;
    offset: string | number;
    rounding_scale: number | null;
};
type Props = {
    canManage: boolean;
    classes: Choice[];
    systems: Choice[];
    units: Unit[];
    conversions: Conversion[];
};

export default function UnitsOfMeasure({
    canManage,
    classes,
    systems,
    units,
    conversions,
}: Props) {
    const [isUnitsListOpen, setIsUnitsListOpen] = useState(true);
    const [classCode, setClassCode] = useState('');
    const [className, setClassName] = useState('');
    const [systemCode, setSystemCode] = useState('');
    const [systemName, setSystemName] = useState('');
    const [unit, setUnit] = useState({
        code: '',
        name: '',
        symbol: '',
        decimal_places: '2',
        uom_class_id: classes[0]?.id ?? '',
        uom_system_id: '',
    });
    const [conversion, setConversion] = useState({
        from_unit_id: units[0]?.id ?? '',
        to_unit_id: units[1]?.id ?? '',
        factor: '',
        offset: '0',
        rounding_scale: '',
    });
    const classNameById = useMemo(
        () => new Map(classes.map((item) => [item.id, item.name])),
        [classes],
    );
    const unitNameById = useMemo(
        () =>
            new Map(
                units.map((item) => [item.id, `${item.name} (${item.code})`]),
            ),
        [units],
    );
    const post = (url: string, data: Record<string, string | number | null>) =>
        router.post(url, data);
    return (
        <>
            <Head title="Satuan" />
            <main className="mx-auto flex w-full max-w-7xl flex-col gap-6 p-6">
                <div>
                    <h1 className="text-2xl font-semibold">Satuan</h1>
                    <p className="text-muted-foreground">
                        Atur satuan dan konversi yang dapat dipakai semua
                        aplikasi.
                    </p>
                </div>
                <Card>
                    <CardHeader>
                        <CardTitle>Kelas dan sistem satuan</CardTitle>
                    </CardHeader>
                    <CardContent className="grid gap-4 lg:grid-cols-2">
                        <form
                            className="flex flex-wrap gap-2"
                            onSubmit={(event) => {
                                event.preventDefault();
                                post('/settings/units-of-measure/classes', {
                                    code: classCode,
                                    name: className,
                                });
                            }}
                        >
                            <Input
                                label="Kode kelas"
                                value={classCode}
                                onChange={(e) => setClassCode(e.target.value)}
                                required
                                disabled={!canManage}
                            />
                            <Input
                                label="Nama kelas"
                                value={className}
                                onChange={(e) => setClassName(e.target.value)}
                                required
                                disabled={!canManage}
                            />
                            <Button type="submit" disabled={!canManage}>
                                <Plus /> Tambah kelas
                            </Button>
                        </form>
                        <form
                            className="flex flex-wrap gap-2"
                            onSubmit={(event) => {
                                event.preventDefault();
                                post('/settings/units-of-measure/systems', {
                                    code: systemCode,
                                    name: systemName,
                                });
                            }}
                        >
                            <Input
                                label="Kode sistem"
                                value={systemCode}
                                onChange={(e) => setSystemCode(e.target.value)}
                                required
                                disabled={!canManage}
                            />
                            <Input
                                label="Nama sistem"
                                value={systemName}
                                onChange={(e) => setSystemName(e.target.value)}
                                required
                                disabled={!canManage}
                            />
                            <Button type="submit" disabled={!canManage}>
                                <Plus /> Tambah sistem
                            </Button>
                        </form>
                    </CardContent>
                </Card>
                <Card>
                    <CardHeader>
                        <CardTitle>Tambah satuan</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <form
                            className="grid gap-3 md:grid-cols-3"
                            onSubmit={(event) => {
                                event.preventDefault();
                                post('/settings/units-of-measure', {
                                    ...unit,
                                    uom_system_id: unit.uom_system_id || null,
                                });
                            }}
                        >
                            <Input
                                label="Kode"
                                value={unit.code}
                                onChange={(e) =>
                                    setUnit({ ...unit, code: e.target.value })
                                }
                                required
                                disabled={!canManage}
                            />
                            <Input
                                label="Nama"
                                value={unit.name}
                                onChange={(e) =>
                                    setUnit({ ...unit, name: e.target.value })
                                }
                                required
                                disabled={!canManage}
                            />
                            <Input
                                label="Simbol"
                                value={unit.symbol}
                                onChange={(e) =>
                                    setUnit({ ...unit, symbol: e.target.value })
                                }
                                disabled={!canManage}
                            />
                            <NativeSelect
                                label="Kelas"
                                value={unit.uom_class_id}
                                onChange={(e) =>
                                    setUnit({
                                        ...unit,
                                        uom_class_id: e.target.value,
                                    })
                                }
                                disabled={!canManage}
                            >
                                {classes.map((item) => (
                                    <option key={item.id} value={item.id}>
                                        {item.name}
                                    </option>
                                ))}
                            </NativeSelect>
                            <NativeSelect
                                label="Sistem"
                                value={unit.uom_system_id}
                                onChange={(e) =>
                                    setUnit({
                                        ...unit,
                                        uom_system_id: e.target.value,
                                    })
                                }
                                disabled={!canManage}
                            >
                                <option value="">Tidak ditentukan</option>
                                {systems.map((item) => (
                                    <option key={item.id} value={item.id}>
                                        {item.name}
                                    </option>
                                ))}
                            </NativeSelect>
                            <Input
                                label="Jumlah angka desimal"
                                type="number"
                                min="0"
                                max="12"
                                value={unit.decimal_places}
                                onChange={(e) =>
                                    setUnit({
                                        ...unit,
                                        decimal_places: e.target.value,
                                    })
                                }
                                disabled={!canManage}
                            />
                            <Button type="submit" disabled={!canManage}>
                                <Save /> Simpan satuan
                            </Button>
                        </form>
                    </CardContent>
                </Card>
                <Card>
                    <CardHeader>
                        <CardTitle>Konversi umum</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <form
                            className="grid gap-3 md:grid-cols-3"
                            onSubmit={(event) => {
                                event.preventDefault();
                                post('/settings/units-of-measure/conversions', {
                                    ...conversion,
                                    rounding_scale:
                                        conversion.rounding_scale || null,
                                });
                            }}
                        >
                            <NativeSelect
                                label="Dari satuan"
                                value={conversion.from_unit_id}
                                onChange={(e) =>
                                    setConversion({
                                        ...conversion,
                                        from_unit_id: e.target.value,
                                    })
                                }
                                disabled={!canManage}
                            >
                                {units
                                    .filter((item) => item.active)
                                    .map((item) => (
                                        <option key={item.id} value={item.id}>
                                            {item.name} ({item.code})
                                        </option>
                                    ))}
                            </NativeSelect>
                            <NativeSelect
                                label="Ke satuan"
                                value={conversion.to_unit_id}
                                onChange={(e) =>
                                    setConversion({
                                        ...conversion,
                                        to_unit_id: e.target.value,
                                    })
                                }
                                disabled={!canManage}
                            >
                                {units
                                    .filter((item) => item.active)
                                    .map((item) => (
                                        <option key={item.id} value={item.id}>
                                            {item.name} ({item.code})
                                        </option>
                                    ))}
                            </NativeSelect>
                            <Input
                                label="Faktor konversi"
                                type="number"
                                min="0.000000000001"
                                step="any"
                                value={conversion.factor}
                                onChange={(e) =>
                                    setConversion({
                                        ...conversion,
                                        factor: e.target.value,
                                    })
                                }
                                required
                                disabled={!canManage}
                            />
                            <Input
                                label="Pergeseran"
                                type="number"
                                step="any"
                                value={conversion.offset}
                                onChange={(e) =>
                                    setConversion({
                                        ...conversion,
                                        offset: e.target.value,
                                    })
                                }
                                disabled={!canManage}
                            />
                            <Input
                                label="Pembulatan desimal"
                                type="number"
                                min="0"
                                max="12"
                                value={conversion.rounding_scale}
                                onChange={(e) =>
                                    setConversion({
                                        ...conversion,
                                        rounding_scale: e.target.value,
                                    })
                                }
                                disabled={!canManage}
                            />
                            <Button
                                type="submit"
                                disabled={!canManage || units.length < 2}
                            >
                                <Save /> Simpan konversi
                            </Button>
                        </form>
                        <p className="mt-3 text-sm text-muted-foreground">
                            Konversi khusus produk, seperti satu dus berisi
                            beberapa barang, akan diatur oleh PIM/Inventory saat
                            tersedia.
                        </p>

                        <CollapsibleSectionGroup defaultValue={[]} className="mt-6">
                            <CollapsibleSection
                                value="conversions"
                                title="Konversi yang tersedia"
                                summary={`${conversions.length} konversi`}
                            >
                                {conversions.length === 0 ? (
                                    <p className="text-sm text-muted-foreground">
                                        Belum ada konversi umum.
                                    </p>
                                ) : (
                                    <div className="overflow-x-auto">
                                        <Table>
                                            <TableHeader>
                                                <TableRow>
                                                    <TableHead>
                                                        Dari satuan
                                                    </TableHead>
                                                    <TableHead>
                                                        Ke satuan
                                                    </TableHead>
                                                    <TableHead>
                                                        <Tooltip clickToPin>
                                                            <TooltipTrigger
                                                                asChild
                                                            >
                                                                <button
                                                                    type="button"
                                                                    className="inline-flex items-center gap-1"
                                                                    aria-label="Penjelasan faktor konversi"
                                                                >
                                                                    Faktor
                                                                    konversi{' '}
                                                                    <CircleHelp className="size-3.5" />
                                                                </button>
                                                            </TooltipTrigger>
                                                            <TooltipContent className="max-w-72">
                                                                <p>
                                                                    Rumus:
                                                                    hasil =
                                                                    nilai asal
                                                                    × faktor
                                                                    konversi +
                                                                    pergeseran.
                                                                </p>
                                                                <p className="mt-1">
                                                                    Contoh: 2
                                                                    kg × 1000 =
                                                                    2000 g.
                                                                </p>
                                                            </TooltipContent>
                                                        </Tooltip>
                                                    </TableHead>
                                                    <TableHead>
                                                        Pergeseran
                                                    </TableHead>
                                                    <TableHead>
                                                        Pembulatan
                                                    </TableHead>
                                                </TableRow>
                                            </TableHeader>
                                            <TableBody>
                                                {conversions.map((item) => (
                                                    <TableRow key={item.id}>
                                                        <TableCell>
                                                            {unitNameById.get(
                                                                item.from_unit_id,
                                                            ) ??
                                                                'Satuan tidak tersedia'}
                                                        </TableCell>
                                                        <TableCell>
                                                            {unitNameById.get(
                                                                item.to_unit_id,
                                                            ) ??
                                                                'Satuan tidak tersedia'}
                                                        </TableCell>
                                                        <TableCell>
                                                            {item.factor}
                                                        </TableCell>
                                                        <TableCell>
                                                            {item.offset}
                                                        </TableCell>
                                                        <TableCell>
                                                            {item.rounding_scale ??
                                                                'Tidak dibulatkan'}
                                                        </TableCell>
                                                    </TableRow>
                                                ))}
                                            </TableBody>
                                        </Table>
                                    </div>
                                )}
                            </CollapsibleSection>
                        </CollapsibleSectionGroup>
                    </CardContent>
                </Card>
                <Card>
                    <Collapsible
                        open={isUnitsListOpen}
                        onOpenChange={setIsUnitsListOpen}
                        className="group"
                    >
                        <CardHeader className="flex flex-row items-center justify-between">
                            <CardTitle>Daftar satuan</CardTitle>
                            <CollapsibleTrigger asChild>
                                <button
                                    type="button"
                                    aria-label="Buka atau tutup daftar satuan"
                                    className="p-1 rounded-md hover:bg-muted cursor-pointer"
                                >
                                    <ChevronDown className="size-4 text-muted-foreground transition-transform group-data-[state=open]:rotate-180" />
                                </button>
                            </CollapsibleTrigger>
                        </CardHeader>
                        <CollapsibleContent>
                            <CardContent>
                                <div className="overflow-x-auto">
                                    <Table>
                                        <TableHeader>
                                            <TableRow>
                                                <TableHead>Kode</TableHead>
                                                <TableHead>Nama</TableHead>
                                                <TableHead>Kelas</TableHead>
                                                <TableHead>Simbol</TableHead>
                                                <TableHead>Desimal</TableHead>
                                            </TableRow>
                                        </TableHeader>
                                        <TableBody>
                                            {units.map((item) => (
                                                <TableRow key={item.id}>
                                                    <TableCell>
                                                        {item.code}
                                                    </TableCell>
                                                    <TableCell>
                                                        {item.name}
                                                    </TableCell>
                                                    <TableCell>
                                                        {classNameById.get(
                                                            item.uom_class_id,
                                                        )}
                                                    </TableCell>
                                                    <TableCell>
                                                        {item.symbol ?? '—'}
                                                    </TableCell>
                                                    <TableCell>
                                                        {item.decimal_places}
                                                    </TableCell>
                                                </TableRow>
                                            ))}
                                        </TableBody>
                                    </Table>
                                </div>
                            </CardContent>
                        </CollapsibleContent>
                    </Collapsible>
                </Card>
            </main>
        </>
    );
}

UnitsOfMeasure.layout = {
    breadcrumbs: [
        { title: 'Satuan', href: '/settings/units-of-measure' },
    ] satisfies BreadcrumbItem[],
};
