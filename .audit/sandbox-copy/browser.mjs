import { chromium } from 'playwright';
import fs from 'node:fs';
import path from 'node:path';
import { spawn } from 'node:child_process';

const out = process.env.AUDIT_BROWSER_DIR;
const fixture = JSON.parse(fs.readFileSync(path.join(out, 'fixture.json'), 'utf8'));
const browser = await chromium.launch();
const page = await browser.newPage({ viewport: { width: 1280, height: 900 } });
const errors = [];
page.on('pageerror', error => errors.push(error.message));
let worker;
try {
    await page.goto('http://127.0.0.1:8123/login');
    await page.getByLabel('Email', { exact: true }).fill(fixture.email);
    await page.getByLabel('Kata sandi', { exact: true }).fill(process.env.COPY_BROWSER_PASSWORD);
    await page.getByRole('button', { name: 'Masuk dengan kata sandi', exact: true }).click();
    await page.waitForURL(url => !url.pathname.startsWith('/login'));
    await page.goto(`http://127.0.0.1:8123/lingkungan/${fixture.source_id}`);
    const panel = page.getByRole('region', { name: 'Salin production menjadi sandbox' });
    await panel.getByRole('button', { name: 'Buat sandbox dari production', exact: true }).waitFor();
    await page.screenshot({ path: path.join(out, 'production-copy-button.png'), fullPage: true });
    await panel.getByRole('button', { name: 'Buat sandbox dari production', exact: true }).click();
    const dialog = page.getByRole('dialog');
    await page.screenshot({ path: path.join(out, 'copy-dialog-clean.png') });
    await dialog.getByRole('button', { name: 'Buat sandbox', exact: true }).click();
    await dialog.getByText(/Isi nama sandbox/).first().waitFor();
    await dialog.getByLabel('Nama sandbox', { exact: true }).fill('Sandbox uji browser');
    await page.screenshot({ path: path.join(out, 'copy-dialog.png') });
    await dialog.getByRole('button', { name: 'Buat sandbox', exact: true }).click();
    await page.waitForURL(url => url.pathname.startsWith('/lingkungan/') && !url.pathname.endsWith(fixture.source_id));
    const targetId = new URL(page.url()).pathname.split('/').pop();
    await page.getByRole('region', { name: 'Salin production menjadi sandbox' }).getByText('Menunggu giliran', { exact: true }).waitFor();
    await page.getByText('Daftar aplikasi akan tersedia setelah penyalinan selesai.', { exact: true }).waitFor();
    await page.screenshot({ path: path.join(out, 'sandbox-queued.png'), fullPage: true });
    const completionReload = page.waitForResponse(response =>
        (response.request().headers()['x-inertia-partial-data'] ?? '').split(',').includes('modules'),
        { timeout: 180000 },
    );
    const stdout = fs.openSync(path.join(out, 'worker.log'), 'w');
    worker = spawn('php', ['artisan', 'queue:work', '--once', '--tries=1', '--no-interaction'], { cwd: path.join(process.env.GITHUB_WORKSPACE, 'apps/core'), env: process.env, stdio: ['ignore', stdout, stdout] });
    fs.closeSync(stdout);
    await page.getByRole('region', { name: 'Salin production menjadi sandbox' }).getByText('Sandbox aktif', { exact: true }).waitFor({ timeout: 180000 });
    const metadataResponse = await completionReload;
    if (metadataResponse.status() !== 200) throw new Error('Completion metadata refresh failed');
    const metadata = await metadataResponse.json();
    if (!Array.isArray(metadata.props.modules)) throw new Error('Completion response has no module list');
    await page.getByText('Belum ada aplikasi yang terpasang di lingkungan ini.', { exact: true }).waitFor();
    await page.screenshot({ path: path.join(out, 'sandbox-active.png'), fullPage: true });
    if (worker.exitCode === null) {
        await new Promise((resolve, reject) => {
            const timer = setTimeout(() => reject(new Error('Worker did not finish after activation')), 10000);
            worker.once('exit', code => { clearTimeout(timer); code === 0 ? resolve() : reject(new Error(`Worker exit ${code}`)); });
        });
    }
    if (worker.exitCode !== 0) throw new Error(`Worker exit ${worker.exitCode}`);
    if (errors.length) throw new Error(errors.join('\n'));
    fs.writeFileSync(path.join(out, 'browser-proof.json'), JSON.stringify({ sourceId: fixture.source_id, targetId, loggedInThroughUi: true, clickedCopyButton: true, formValidationShown: true, realCoreApi: true, realQueueWorker: true, activeObservedThroughPolling: true, completionMetadataReloaded: true, moduleCount: metadata.props.modules.length, pageErrors: errors }, null, 2));
    console.log('Browser click, validation, queued target, real worker and active polling passed.');
} catch (error) {
    await page.screenshot({ path: path.join(out, 'failure.png'), fullPage: true });
    fs.writeFileSync(path.join(out, 'page-error.txt'), String(error));
    throw error;
} finally {
    await browser.close();
    if (worker && worker.exitCode === null) worker.kill();
}

