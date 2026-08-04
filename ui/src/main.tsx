import { createRoot } from 'react-dom/client';
import { TooltipProvider } from '@apperp/ui/tooltip';
import { Toaster } from '@apperp/ui/sonner';
import App from './App';
import './styles.css';

createRoot(document.getElementById('root')!).render(
    <TooltipProvider delayDuration={0}>
        <App />
        <Toaster position="top-right" />
    </TooltipProvider>,
);
