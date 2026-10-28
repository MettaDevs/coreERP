import { Suspense } from "react";
import { Card, CardContent } from "@apperp/ui/card";
import App from "../App";
import type { ModulePageProps } from "../App";

/**
 * Titik masuk Inertia untuk seluruh layar modul Procurement.
 *
 * Didaftarkan dan dipanggil oleh Core melalui resolusi `procurement::ModulePage`.
 */
export default function ModulePage(props: ModulePageProps) {
    return (
        <Suspense
            fallback={
                <main className="p-6">
                    <Card>
                        <CardContent className="py-12 text-center">
                            <p className="text-muted-foreground text-sm">
                                Memuat antarmuka pengadaan...
                            </p>
                        </CardContent>
                    </Card>
                </main>
            }
        >
            <App {...props} />
        </Suspense>
    );
}

