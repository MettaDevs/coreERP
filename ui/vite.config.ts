import { defineConfig, loadEnv } from 'vite';
import react from '@vitejs/plugin-react';
import tailwindcss from '@tailwindcss/vite';

export default defineConfig(({ mode }) => {
    const env = loadEnv(mode, '.', '');

    return {
        // Web Shell menyajikan UI ini di bawah prefix yang memuat nama placement,
        // dan placement berbeda antar deployment sedangkan image release-nya satu.
        // Base absolut karena itu memaksa satu build per placement; base relatif
        // membuat satu image jalan di semua placement. Aman karena app memakai
        // hash routing, jadi URL dokumen tetap berada di root direktorinya.
        base: './',
        plugins: [react(), tailwindcss()],
        server: {
            proxy: {
                '/api': {
                    target: `http://${env.API_UPSTREAM ?? '127.0.0.1:8000'}`,
                    changeOrigin: true,
                },
            },
        },
    };
});
