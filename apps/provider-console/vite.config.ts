import react from '@vitejs/plugin-react';
import { defineConfig, loadEnv } from 'vite';

export default defineConfig(({ mode }) => {
    const env = loadEnv(mode, '.', 'PROVIDER_CONSOLE_');

    return {
        plugins: [react()],
        server: {
            proxy: {
                '/api': {
                    target: env.PROVIDER_CONSOLE_CONTROL_PLANE_URL ?? 'http://127.0.0.1:8000',
                    changeOrigin: true,
                },
            },
        },
    };
});
