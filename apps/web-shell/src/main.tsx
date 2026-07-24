import { useEffect, useState } from 'react';
import { createRoot } from 'react-dom/client';
import './styles.css';

type AppEntry = {
    id: string;
    name: string;
    description: string;
    entry: string;
    version: string;
};

type LaunchManifest = {
    tenant: { id: string; name: string };
    apps: AppEntry[];
};

function WebShell() {
    const [manifest, setManifest] = useState<LaunchManifest | null>(null);
    const [open, setOpen] = useState(false);
    const [error, setError] = useState(false);

    useEffect(() => {
        fetch('/api/v1/launch-manifest', { credentials: 'include' })
            .then(async (response) => {
                if (!response.ok) throw new Error('Launch manifest tidak tersedia.');
                return response.json() as Promise<{ data: LaunchManifest }>;
            })
            .then(({ data }) => setManifest(data))
            .catch(() => setError(true));
    }, []);

    return (
        <main className="shell">
            <header className="header">
                <a className="brand" href="/">CoreERP</a>
                <div className="context">{manifest?.tenant.name ?? 'Memuat workspace...'}</div>
                <button
                    aria-expanded={open}
                    aria-label="Buka daftar aplikasi"
                    className="launcher-button"
                    onClick={() => setOpen((current) => !current)}
                    type="button"
                >
                    <span aria-hidden="true">⠿</span>
                </button>
            </header>

            {open && (
                <section aria-label="Daftar aplikasi" className="launcher">
                    {manifest?.apps.map((app) => (
                        <a className="app-card" href={app.entry} key={app.id}>
                            <span className="app-icon" aria-hidden="true">{app.name.slice(0, 1)}</span>
                            <span>{app.name}</span>
                        </a>
                    ))}
                    {manifest && manifest.apps.length === 0 && <p className="empty">Belum ada aplikasi yang siap dibuka.</p>}
                </section>
            )}

            <section className="welcome">
                <p className="eyebrow">Workspace</p>
                <h1>Satu pintu untuk semua aplikasi</h1>
                <p>Pilih aplikasi dari tombol grid di kanan atas.</p>
                {error && <p className="error">Daftar aplikasi tidak dapat dimuat. Silakan masuk kembali.</p>}
            </section>
        </main>
    );
}

createRoot(document.getElementById('root')!).render(<WebShell />);
