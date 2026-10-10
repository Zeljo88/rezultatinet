// Native Chromium HTTP cache regression. No Playwright routing or clock installation:
// either would hide the real cache/navigation behaviour under review.
const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const http = require('node:http');
const { execFileSync } = require('node:child_process');
const { chromium } = require(process.env.PLAYWRIGHT_MODULE || '/home/azureuser/.npm-global/lib/node_modules/openclaw/node_modules/playwright-core');
const root = path.resolve(__dirname, '../..');
const output = process.env.UI_BROWSER_OUTPUT;
const rejected = '3d405a541437c57d28566883eec71a7aa25b3e6f';

test('native cache failure reproduced; disconnected banner stays absent through cache, clocks and lifecycle', { timeout: 60000 }, async () => {
    assert.ok(output, 'Render UI_BROWSER_OUTPUT fixtures with tests/browser/RenderUiTest.php first');
    const runtime = fs.readFileSync(path.join(root, 'vendor/livewire/livewire/dist/livewire.esm.js'), 'utf8')
        .replace(/export \{\s*export_Alpine as Alpine,\s*Livewire2 as Livewire\s*\};/, 'window.Alpine=export_Alpine;window.Alpine.start();');
    const old = execFileSync('git', ['show', rejected + ':resources/views/components/derby-countdown.blade.php'], { cwd: root, encoding: 'utf8' });
    const expression = old.match(/x-data="([\s\S]*?)"\s+x-cloak/)[1];
    const pages = Object.fromEntries(['home', 'yesterday', 'tomorrow'].map(name => {
        const html = fs.readFileSync(path.join(output, name + '.html'), 'utf8');
        assert.ok(!/kickoffMs:|serverMs:|String\(days\)|Dinamo vs Hajduk/.test(html), 'Actual Laravel response must not contain banner');
        // Retain real rendered DOM; load only local Alpine/CSS, not analytics, push or live transports.
        return [name, html.replace(/<script\b[^>]*>[\s\S]*?<\/script>/gi, '').replace(/<link\b[^>]*>/gi, '')
            .replace('</head>', '<style>' + fs.readFileSync(path.join(output, 'production-style.css'), 'utf8') + '</style></head>')
            .replace('</body>', '<script>' + runtime + '</script></body>')];
    }));
    const hits = {}; const heads = []; const results = [];
    const server = http.createServer((req, res) => {
        hits[req.method + ' ' + req.url] = (hits[req.method + ' ' + req.url] || 0) + 1;
        if (req.method === 'HEAD') heads.push(req.url);
        res.setHeader('Cache-Control', 'public, max-age=30, s-maxage=60, stale-while-revalidate=300');
        res.setHeader('Content-Type', 'text/html; charset=utf-8');
        res.setHeader('Content-Security-Policy', "default-src 'none'; script-src 'unsafe-inline' 'unsafe-eval'; style-src 'unsafe-inline'; img-src data:; connect-src 'none'");
        if (req.url === '/rejected') {
            const now = Date.now();
            const data = expression.replace('{{ $kickoff->getTimestamp() * 1000 }}', String(now + 5000))
                .replace("{{ now('UTC')->getTimestampMs() }}", String(now));
            return res.end('<style>[x-cloak]{display:none!important}</style><div id="old" x-data="' + data + '" x-cloak x-show="diff > 0"><span x-text="secs"></span></div><script>' + runtime + '</script>');
        }
        const name = { '/': 'home', '/jucer': 'yesterday', '/sutra': 'tomorrow' }[req.url];
        if (name) res.setHeader('X-Test-Kickoff-Ms', String(Date.now() + 5000));
        res.end(name ? pages[name] : '<title>Away</title>away');
    });
    await new Promise(resolve => server.listen(0, '127.0.0.1', resolve));
    const origin = 'http://127.0.0.1:' + server.address().port;
    let browser;
    try {
        browser = await chromium.launch({ executablePath: process.env.CHROMIUM_PATH || '/snap/bin/chromium', headless: true,
            ignoreDefaultArgs: ['--disable-back-forward-cache'],
            args: ['--no-sandbox', '--host-resolver-rules=MAP * ~NOTFOUND, EXCLUDE 127.0.0.1'] });
        const oldPage = await browser.newPage();
        const captureOld = async () => {
            await oldPage.goto(origin + '/rejected');
            await oldPage.waitForFunction(() => window.Alpine && Alpine.$data(document.querySelector('#old')).timer);
            return oldPage.locator('#old').evaluate(el => {
                const d = Alpine.$data(el); const n = performance.getEntriesByType('navigation')[0];
                return { diff: d.diff, kickoffMs: d.kickoffMs, serverMs: d.serverMs, actualNow: Date.now(), visible: getComputedStyle(el).display !== 'none', deliveryType: n.deliveryType, transferSize: n.transferSize };
            });
        };
        const first = await captureOld();
        await oldPage.goto(origin + '/away'); await oldPage.waitForTimeout(6500);
        const cached = await captureOld();
        assert.equal(hits['GET /rejected'], 1);
        assert.equal(cached.transferSize, 0); assert.equal(cached.serverMs, first.serverMs);
        assert.ok(cached.actualNow > cached.kickoffMs && cached.diff > 0 && cached.visible);
        results.push({ name: 'reviewer-failure-reproduced-on-exact-rejected-expression', first, cached });
        await oldPage.close();
        for (const skew of [-120000, 120000]) {
            const context = await browser.newContext({ serviceWorkers: 'block', timezoneId: 'Europe/Zagreb' });
            await context.addInitScript(initial => {
                const NativeDate = Date; window.uiClockSkew = initial;
                window.Date = class extends NativeDate {
                    constructor(...args) { super(...(args.length ? args : [NativeDate.now() + window.uiClockSkew])); }
                    static now() { return NativeDate.now() + window.uiClockSkew; }
                };
                window.lifecycle = [];
                addEventListener('pageshow', e => lifecycle.push({ event: 'pageshow', persisted: e.persisted }));
                document.addEventListener('visibilitychange', () => lifecycle.push({ event: 'visibility', state: document.visibilityState }));
            }, skew);
            const p = await context.newPage(); const errors = [];
            p.on('pageerror', e => errors.push(e.message));
            const check = async (name, label = '10. oktobar 2026.') => {
                await p.waitForFunction(() => !!window.Alpine);
                assert.equal(await p.locator('[x-data*="kickoffMs"], [x-text*="days"], [x-data*="diff:"]').count(), 0);
                const text = await p.locator('body').innerText();
                assert.ok(!/Dinamo vs Hajduk|Zvezda vs Partizan|Sarajevo vs Željezničar/.test(text));
                assert.ok(text.includes(label));
                if (label.startsWith('10.')) assert.ok(text.includes('13:30') && text.includes('Arsenal') && text.includes('Leeds'));
                const receipt = await p.evaluate(() => ({ nav: performance.getEntriesByType('navigation')[0].toJSON(), lifecycle, skew: uiClockSkew }));
                results.push({ name, skew, ...receipt }); return receipt;
            };
            const countBefore = hits['GET /'] || 0;
            const initialResponse = await p.goto(origin + '/');
            const testKickoffMs = Number(initialResponse.headers()['x-test-kickoff-ms']);
            await check('initial');
            await p.goto(origin + '/away'); await p.waitForTimeout(6500);
            const cachedResponse = await p.goto(origin + '/');
            assert.equal(Number(cachedResponse.headers()['x-test-kickoff-ms']), testKickoffMs);
            assert.ok(Date.now() > testKickoffMs, 'Same five-second near-expiry scenario as rejected candidate');
            const cachedPage = await check('native-cache-revisit-past-five-second-expiry');
            results.push({ name: 'cached-candidate-near-expiry-receipt', skew, testKickoffMs, actualNow: Date.now(), bannerCount: 0 });
            assert.equal(cachedPage.nav.transferSize, 0); assert.equal(hits['GET /'], countBefore + 1);
            await p.reload(); await check('reload');
            await p.evaluate(() => { window.uiClockSkew = 0; }); await check('wall-clock-correction');
            await p.evaluate(() => { window.uiClockSkew = 28800000; }); await check('large-forward-correction');
            await p.evaluate(() => { window.uiClockSkew = -28800000; }); await check('large-backward-correction');
            // Actual history traversal. Assert bfcache restoration, not just a dispatched event.
            await p.goto(origin + '/away'); await p.goBack({ waitUntil: 'commit' });
            const back = await check('native-history-back');
            assert.ok(back.lifecycle.some(e => e.event === 'pageshow' && e.persisted), 'Chromium must actually restore from bfcache');
            await p.goForward({ waitUntil: 'commit' }); await p.goBack({ waitUntil: 'commit' }); await check('native-history-forward-back');
            const session = await context.newCDPSession(p);
            await session.send('Page.setWebLifecycleState', { state: 'frozen' });
            await p.waitForTimeout(1100);
            await session.send('Page.setWebLifecycleState', { state: 'active' });
            await p.evaluate(() => { window.uiClockSkew = 86400000; document.dispatchEvent(new Event('visibilitychange')); });
            await check('browser-freeze-resume-and-one-day-wall-jump');
            // Deterministic paused-monotonic offline sleep model (not a physical OS suspend).
            await context.setOffline(true);
            await p.evaluate(() => { Object.defineProperty(performance, 'now', { configurable: true, value: () => 0 }); });
            await p.waitForTimeout(100);
            await p.evaluate(() => EventTarget.prototype.dispatchEvent.call(window, new PageTransitionEvent('pageshow', { persisted: true })));
            await check('offline-paused-monotonic-resume'); await context.setOffline(false);
            await p.goto(origin + '/jucer'); await check('yesterday', '9. oktobar 2026.');
            await p.goto(origin + '/sutra'); await check('tomorrow', '11. oktobar 2026.');
            assert.deepEqual(errors, []); await context.close();
        }
        for (const timezoneId of ['UTC', 'America/New_York', 'Europe/Zagreb']) {
            for (const width of [390, 1440]) {
                const context = await browser.newContext({ timezoneId, viewport: { width, height: 900 }, serviceWorkers: 'block' });
                const p = await context.newPage(); await p.goto(origin + '/');
                assert.ok((await p.locator('body').innerText()).includes('13:30'));
                assert.equal(await p.evaluate(() => document.documentElement.scrollWidth > innerWidth), false);
                if (timezoneId === 'Europe/Zagreb') await p.screenshot({ path: path.join(output, 'candidate-' + width + '.png'), fullPage: true });
                results.push({ name: 'zone-viewport-kickoff', timezoneId, width, kickoff: '13:30' }); await context.close();
            }
        }
        assert.deepEqual(heads, [], 'Removal must not create time freshness requests');
        fs.writeFileSync(path.join(output, 'native-cache-browser.json'), JSON.stringify({ status: 'PASS', rejected, results, hits, heads, network: 'Loopback only; no route interception; external DNS blocked; CSP blocks external resources', limitation: 'Browser freeze and explicit paused-monotonic/offline model; no claim of physical OS suspension' }, null, 2));
    } finally {
        if (browser) await browser.close();
        await new Promise(resolve => server.close(resolve));
    }
});
