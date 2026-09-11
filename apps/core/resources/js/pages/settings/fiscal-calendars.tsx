import { Button } from '@apperp/ui/button';
import { Input } from '@apperp/ui/input';
import { NativeSelect } from '@apperp/ui/native-select';
import { Head, useForm } from '@inertiajs/react';
import { useState } from 'react';
import AppLayout from '@/layouts/app-layout';
import type { BreadcrumbItem } from '@/types/navigation';

type Period = {
    ordinal: number;
    name: string;
    starts_on: string;
    ends_on: string;
};
type Year = {
    id: string;
    name: string;
    starts_on: string;
    ends_on: string;
    periods: Period[];
};
type Calendar = { id: string; code: string; name: string; years: Year[] };
type LegalEntity = {
    id: string;
    name: string;
    company_code: string | null;
    fiscal_calendar_id: string | null;
};
type Props = {
    canManage: boolean;
    calendars: Calendar[];
    legalEntities: LegalEntity[];
};

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Pengaturan', href: '/settings/number-sequences' },
    { title: 'Kalender fiskal', href: '/settings/fiscal-calendars' },
];

function CreateCalendar({ canManage }: { canManage: boolean }) {
    const form = useForm({ code: '', name: '' });

    return (
        <form
            className="flex flex-wrap items-end gap-2 rounded-md border p-3"
            onSubmit={(event) => {
                event.preventDefault();
                form.post('/settings/fiscal-calendars', {
                    preserveScroll: true,
                    onSuccess: () => form.reset(),
                });
            }}
        >
            <Input
                label="Kode"
                placeholder="FY-JULI"
                value={form.data.code}
                disabled={!canManage}
                onChange={(event) => form.setData('code', event.target.value)}
            />
            <Input
                label="Nama"
                placeholder="Tahun buku Juli-Juni"
                value={form.data.name}
                disabled={!canManage}
                onChange={(event) => form.setData('name', event.target.value)}
            />
            <Button type="submit" disabled={!canManage || form.processing}>
                Tambah kalender
            </Button>
        </form>
    );
}

function AddYear({
    calendar,
    canManage,
}: {
    calendar: Calendar;
    canManage: boolean;
}) {
    const form = useForm({ name: '', starts_on: '', months: 12 });

    return (
        <form
            className="flex flex-wrap items-end gap-2"
            onSubmit={(event) => {
                event.preventDefault();
                form.post(`/settings/fiscal-calendars/${calendar.id}/years`, {
                    preserveScroll: true,
                    onSuccess: () => form.reset(),
                });
            }}
        >
            <Input
                label="Nama tahun"
                placeholder="FY2027"
                value={form.data.name}
                disabled={!canManage}
                onChange={(event) => form.setData('name', event.target.value)}
            />
            <Input
                label="Mulai"
                type="date"
                value={form.data.starts_on}
                disabled={!canManage}
                onChange={(event) =>
                    form.setData('starts_on', event.target.value)
                }
            />
            <Input
                label="Jumlah periode"
                type="number"
                min="1"
                max="24"
                className="w-32"
                value={form.data.months}
                disabled={!canManage}
                onChange={(event) =>
                    form.setData('months', Number(event.target.value))
                }
            />
            <Button
                type="submit"
                variant="secondary"
                disabled={!canManage || form.processing}
            >
                Tambah tahun
            </Button>
            {/* The service rejects overlapping years and periods that leave gaps, and reports them against
                starts_on or name, so both are surfaced here rather than swallowed. */}
            {form.errors.starts_on && (
                <p className="w-full text-sm text-destructive">
                    {form.errors.starts_on}
                </p>
            )}
            {form.errors.name && (
                <p className="w-full text-sm text-destructive">
                    {form.errors.name}
                </p>
            )}
        </form>
    );
}

function AssignEntity({
    calendars,
    entity,
    canManage,
}: {
    calendars: Calendar[];
    entity: LegalEntity;
    canManage: boolean;
}) {
    const [selected, setSelected] = useState(entity.fiscal_calendar_id ?? '');
    const form = useForm({ legal_entity_id: entity.id });

    return (
        <div className="flex flex-wrap items-end gap-2 border-t py-2">
            <span className="min-w-48 text-sm">
                {entity.name}
                {entity.company_code ? ` (${entity.company_code})` : ''}
            </span>
            <NativeSelect
                label="Kalender"
                value={selected}
                disabled={!canManage}
                onChange={(event) => setSelected(event.target.value)}
            >
                <option value="">Belum ditetapkan</option>
                {calendars.map((calendar) => (
                    <option key={calendar.id} value={calendar.id}>
                        {calendar.code}
                    </option>
                ))}
            </NativeSelect>
            <Button
                type="button"
                variant="secondary"
                disabled={!canManage || selected === '' || form.processing}
                onClick={() =>
                    form.post(`/settings/fiscal-calendars/${selected}/assign`, {
                        preserveScroll: true,
                    })
                }
            >
                Tetapkan
            </Button>
        </div>
    );
}

export default function FiscalCalendars({
    canManage,
    calendars,
    legalEntities,
}: Props) {
    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Kalender fiskal" />
            <div className="space-y-6 p-4">
                <div>
                    <h1 className="text-lg font-semibold">Kalender fiskal</h1>
                    <p className="text-sm text-muted-foreground">
                        Kalender fiskal dimiliki entitas legal dan menentukan
                        periode penomoran dokumen. Periode harus berurutan tanpa
                        celah dan menutup seluruh tahun fiskal.
                    </p>
                </div>

                <CreateCalendar canManage={canManage} />

                {calendars.length === 0 && (
                    <p className="text-sm text-muted-foreground">
                        Belum ada kalender fiskal. Reset nomor per tahun atau
                        periode fiskal baru dapat dipakai setelah kalender
                        dibuat dan ditetapkan ke entitas legal.
                    </p>
                )}

                {calendars.map((calendar) => (
                    <section
                        key={calendar.id}
                        className="space-y-3 rounded-md border p-3"
                    >
                        <h2 className="font-medium">
                            {calendar.code} — {calendar.name}
                        </h2>
                        {calendar.years.map((year) => (
                            <div
                                key={year.id}
                                className="rounded-md bg-muted/40 p-2 text-sm"
                            >
                                <strong>{year.name}</strong> {year.starts_on} →{' '}
                                {year.ends_on}
                                <span className="text-muted-foreground">
                                    {' '}
                                    ({year.periods.length} periode)
                                </span>
                            </div>
                        ))}
                        <AddYear calendar={calendar} canManage={canManage} />
                    </section>
                ))}

                <section className="space-y-1 rounded-md border p-3">
                    <h2 className="font-medium">Entitas legal</h2>
                    {legalEntities.length === 0 ? (
                        <p className="text-sm text-muted-foreground">
                            Belum ada entitas legal pada bisnis ini.
                        </p>
                    ) : (
                        legalEntities.map((entity) => (
                            <AssignEntity
                                key={entity.id}
                                entity={entity}
                                calendars={calendars}
                                canManage={canManage}
                            />
                        ))
                    )}
                </section>
            </div>
        </AppLayout>
    );
}
