// Render og-default.html to public/assets/og/og-default.png at 1200x630.
//
// Usage: node og-template/render.mjs
//
// Uses the global puppeteer install per project convention; never adds
// node_modules to this PHP repo. The HTML loads /assets/images/immunity-mark.png
// as a CSS mask, so we serve the file via Puppeteer's data: scheme by
// loading the page through a small in-process HTTP server.

import { createServer } from 'node:http';
import { readFile } from 'node:fs/promises';
import { dirname, resolve, extname } from 'node:path';
import { fileURLToPath } from 'node:url';
// Resolve the global Puppeteer install. The Mac homebrew node setup keeps
// global modules at /opt/homebrew/lib/node_modules; the system constraint
// for this repo is "global puppeteer only, no local node_modules", so we
// reach for the global install directly via its file path.
import { createRequire } from 'node:module';
const require = createRequire(import.meta.url);
const globalNodeModules = process.env.NODE_PATH || '/opt/homebrew/lib/node_modules';
const puppeteer = require(`${globalNodeModules}/puppeteer/lib/cjs/puppeteer/puppeteer.js`).default
    ?? require(`${globalNodeModules}/puppeteer/lib/cjs/puppeteer/puppeteer.js`);

const __dirname = dirname(fileURLToPath(import.meta.url));
const repoRoot = resolve(__dirname, '..');
const templatePath = resolve(__dirname, 'og-default.html');
const outputPath = resolve(repoRoot, 'public/assets/og/og-default.png');

const MIME = {
    '.html': 'text/html; charset=utf-8',
    '.png':  'image/png',
    '.jpg':  'image/jpeg',
    '.jpeg': 'image/jpeg',
    '.svg':  'image/svg+xml',
    '.css':  'text/css; charset=utf-8',
    '.js':   'application/javascript; charset=utf-8',
};

// Serve the template HTML and proxy /assets/* from public/assets/* so the
// brand-mark image resolves the same way it does in production.
const server = createServer(async (req, res) => {
    try {
        let urlPath = req.url ?? '/';
        if (urlPath === '/' || urlPath === '/og-default.html') {
            const html = await readFile(templatePath, 'utf8');
            res.writeHead(200, { 'content-type': 'text/html; charset=utf-8' });
            res.end(html);
            return;
        }
        if (urlPath.startsWith('/assets/')) {
            const filePath = resolve(repoRoot, 'public' + urlPath);
            try {
                const buf = await readFile(filePath);
                res.writeHead(200, { 'content-type': MIME[extname(filePath)] ?? 'application/octet-stream' });
                res.end(buf);
                return;
            } catch {
                res.writeHead(404);
                res.end('asset not found: ' + urlPath);
                return;
            }
        }
        res.writeHead(404);
        res.end('not found');
    } catch (err) {
        res.writeHead(500);
        res.end(String(err));
    }
});

await new Promise((r) => server.listen(0, '127.0.0.1', r));
const port = server.address().port;

const browser = await puppeteer.launch({ headless: 'new' });
try {
    const page = await browser.newPage();
    await page.setViewport({ width: 1200, height: 630, deviceScaleFactor: 1 });
    await page.goto(`http://127.0.0.1:${port}/`, { waitUntil: 'networkidle0' });
    // Brief settle so Fraunces / Inter / JetBrains Mono finish their swap.
    await new Promise((r) => setTimeout(r, 600));
    await page.screenshot({
        path: outputPath,
        type: 'png',
        clip: { x: 0, y: 0, width: 1200, height: 630 },
    });
    console.log(`OG image rendered to ${outputPath}`);
} finally {
    await browser.close();
    server.close();
}
