import { useState } from 'react';
import { createRoot } from 'react-dom/client';
import './styles.css';

type App = {
    id: string;
    name: string;
    version: string;
    status: string;
    description: string | null;
};

function ProviderConsole() {
    const [apps, setApps] = useState<App[]>([]);
    const [message, setMessage] = useState('');
    const [loading, setLoading] = useState(false);

    async function loadApps() {
        setLoading(true);
        setMessage('');

        try {
            const response = await fetch('/api/v1/provider/apps', { credentials: 'include' });
            if (!response.ok) {
                throw new Error(response.status === 403 ? 'Anda tidak punya akses untuk melihat katalog aplikasi.' : 'Katalog aplikasi belum bisa dimuat.');
            }

            const payload = await response.json() as { data: App[] };
            setApps(payload.data);
        } catch (error) {
            setMessage(error instanceof Error ? error.message : 'Katalog aplikasi belum bisa dimuat.');
        } finally {
            setLoading(false);
        }
    }

    return (
        <main>
            <header>
                <div>
                    <p>OPERASI PROVIDER</p>
                    <h1>Katalog aplikasi</h1>
                    <span>Daftar produk yang dikenali oleh Control Plane.</span>
                </div>
                <button type="button" onClick={loadApps} disabled={loading}>
                    {loading ? 'Memuat...' : 'Muat katalog'}
                </button>
            </header>

            {message && <p className="message" role="alert">{message}</p>}
            {!message && !loading && apps.length === 0 && <p className="empty">Pilih “Muat katalog” untuk melihat aplikasi.</p>}
            {apps.length > 0 && (
                <ul>
                    {apps.map((app) => (
                        <li key={app.id}>
                            <div>
                                <strong>{app.name}</strong>
                                <span>{app.description || 'Belum ada keterangan.'}</span>
                            </div>
                            <small>{app.version} · {app.status}</small>
                        </li>
                    ))}
                </ul>
            )}
        </main>
    );
}

createRoot(document.getElementById('root')!).render(<ProviderConsole />);
