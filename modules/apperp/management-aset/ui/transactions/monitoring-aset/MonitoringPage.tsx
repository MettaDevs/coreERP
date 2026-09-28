import { useEffect, useState } from 'react';
import { Card, CardContent, CardHeader, CardTitle } from '@apperp/ui/card';
import {
    Empty,
    EmptyDescription,
    EmptyHeader,
    EmptyTitle,
} from '@apperp/ui/empty';
import { api, errorMessage } from '../../api';
type Aset = {
    id: string;
    kode: string;
    lifecycle_state: string;
    acquisition_value: string;
    currency_code: string;
};
export default function MonitoringPage() {
    const [aset, setAset] = useState<Aset[]>([]);
    const [error, setError] = useState('');
    useEffect(() => {
        api<{ data: Aset[] }>('/aset')
            .then((x) => setAset(x.data))
            .catch((e) =>
                setError(errorMessage(e, 'Monitoring belum dapat dimuat.')),
            );
    }, []);

    return (
        <Card className="min-h-full rounded-none border-0 shadow-none">
            <CardHeader className="border-b px-5 py-3">
                <CardTitle>Monitoring aset</CardTitle>
            </CardHeader>
            <CardContent className="px-0">
                {error && (
                    <p className="text-destructive px-5 py-3 text-sm">
                        {error}
                    </p>
                )}
                {!aset.length ? (
                    <Empty>
                        <EmptyHeader>
                            <EmptyTitle>
                                Belum ada aset untuk dipantau
                            </EmptyTitle>
                            <EmptyDescription>
                                Monitoring menampilkan status register aset yang
                                tersedia.
                            </EmptyDescription>
                        </EmptyHeader>
                    </Empty>
                ) : (
                    <div className="divide-y">
                        {aset.map((aset) => (
                            <div
                                className="flex items-center justify-between px-5 py-3"
                                key={aset.id}
                            >
                                <div>
                                    <p className="font-medium">{aset.kode}</p>
                                    <p className="text-muted-foreground text-sm">
                                        Status: {aset.lifecycle_state}
                                    </p>
                                </div>
                                <span>
                                    {aset.currency_code}{' '}
                                    {aset.acquisition_value}
                                </span>
                            </div>
                        ))}
                    </div>
                )}
            </CardContent>
        </Card>
    );
}
