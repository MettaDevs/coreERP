import { createRoot } from 'react-dom/client';
import './styles.css';

function App() {
    return (
        <main>
            <p className="eyebrow">Management Asset</p>
            <h1>Siap dikelola</h1>
            <p>Menu aset, kategori, dan grup akan muncul di Web Shell sesuai akses Anda.</p>
        </main>
    );
}

createRoot(document.getElementById('root')!).render(<App />);
