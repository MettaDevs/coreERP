import { defineConfig, loadEnv } from 'vite';
import react from '@vitejs/plugin-react';

export default defineConfig(({ mode }) => {
    const env = loadEnv(mode, '.', 'WEB_SHELL_');

    return {
        plugins: [react()],
        server: {
            proxy: {
                '/api': {
                    target: env.WEB_SHELL_CONTROL_PLANE_URL ?? 'http://127.0.0.1:8000',
                    changeOrigin: true,
                },
            },
        },
    };
});
