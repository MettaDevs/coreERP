import { Head } from '@inertiajs/react';
import { Box, Database, PackageCheck } from 'lucide-react';

import Heading from '@/components/heading';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';

type App = {
    id: string;
    name: string;
    version: string;
    status: string;
    database_name: string;
    description: string;
};

export default function AppCatalog({ apps }: { apps: App[] }) {
    return (
        <>
            <Head title="Katalog aplikasi" />
            <main className="min-h-screen bg-muted/30">
                <div className="mx-auto flex w-full max-w-5xl flex-col gap-6 p-6">
                    <header className="flex flex-col gap-3">
                        <Badge className="gap-1" variant="secondary">
                            <PackageCheck />
                            Core Platform
                        </Badge>
                        <Heading
                            title="Katalog aplikasi"
                            description="Aplikasi resmi yang dikenali Control Plane dan dirilis secara mandiri."
                        />
                    </header>

                    <Alert>
                        <Database />
                        <AlertTitle>Database ownership</AlertTitle>
                        <AlertDescription>
                            Core memakai core_erp. Setiap aplikasi memiliki
                            database sendiri dan tidak melakukan query lintas
                            database.
                        </AlertDescription>
                    </Alert>

                    <section
                        className="grid gap-4 md:grid-cols-2"
                        aria-label="Aplikasi resmi"
                    >
                        {apps.map((app) => (
                            <Card key={app.id}>
                                <CardHeader>
                                    <CardTitle className="flex items-center gap-2">
                                        <Box />
                                        {app.name}
                                    </CardTitle>
                                    <CardDescription>
                                        {app.description}
                                    </CardDescription>
                                    <Badge variant="outline">
                                        {app.status}
                                    </Badge>
                                </CardHeader>
                                <CardContent className="flex flex-col gap-2 text-sm">
                                    <div className="flex justify-between gap-4">
                                        <span className="text-muted-foreground">
                                            ID aplikasi
                                        </span>
                                        <code>{app.id}</code>
                                    </div>
                                    <div className="flex justify-between gap-4">
                                        <span className="text-muted-foreground">
                                            Version
                                        </span>
                                        <code>{app.version}</code>
                                    </div>
                                    <div className="flex justify-between gap-4">
                                        <span className="text-muted-foreground">
                                            Database
                                        </span>
                                        <code>{app.database_name}</code>
                                    </div>
                                </CardContent>
                            </Card>
                        ))}
                    </section>
                </div>
            </main>
        </>
    );
}
