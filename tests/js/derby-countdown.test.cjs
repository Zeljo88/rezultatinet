const { readFileSync } = require('node:fs');
const { resolve } = require('node:path');
const vm = require('node:vm');
const assert = require('node:assert/strict');
const test = require('node:test');
const template = readFileSync(resolve(__dirname, '../../resources/views/components/derby-countdown.blade.php'), 'utf8');
const source = template.match(/x-data="([\s\S]*?)"\s+x-cloak/)[1]
  .replace('{{ $kickoff->getTimestamp() * 1000 }}', 'kickoff')
  .replace("{{ now('UTC')->getTimestampMs() }}", 'server');
function countdown({ skew = 0, delay = 0, remaining = 60000 } = {}) {
  const server = Date.parse('2026-10-10T12:59:00Z');
  let mono = delay, wall = server + skew, callback, cleared = 0, calls = 0, header = server;
  const listeners = new Map();
  const events = { addEventListener: (n, f) => listeners.set(n, f), removeEventListener: n => listeners.delete(n) };
  const document = { ...events, hidden: false };
  const context = vm.createContext({
    kickoff: server + remaining, server,
    Date: { now: () => wall, parse: Date.parse },
    performance: { now: () => mono, getEntriesByType: () => [{ responseStart: 0 }] },
    document, window: events, location: { href: 'https://local.test/' }, AbortController,
    fetch: async (url, options) => { calls++; assert.equal(options.method, 'HEAD'); assert.equal(options.cache, 'no-store'); return { ok: true, headers: { get: () => header === null ? null : new Date(header).toUTCString() } }; },
    setInterval: fn => { callback = fn; return 42; }, clearInterval: () => { cleared++; },
    setTimeout: () => 43, clearTimeout: () => {},
  });
  const state = vm.runInContext(`(${source})`, context);
  return { state, document, listeners, calls: () => calls, cleared: () => cleared,
    advance: ms => { mono += ms; wall += ms; callback(); },
    correct: offset => { wall = server + mono + offset; callback(); },
    server: value => { header = value === null ? null : server + value; },
  };
}
function safe(c) { for (const part of ['diff','days','hours','mins','secs']) assert.ok(Number.isInteger(c.state[part]) && c.state[part] >= 0, part); }
test('reviewer +/-120s initial clock skew, subsequent corrections, exact expiry and recovery', async () => {
  for (const skew of [-120000,120000,28800000]) {
    const c = countdown({skew}); c.state.init(); assert.equal(c.state.diff,60);
    c.advance(10000); c.correct(0); assert.equal(c.state.diff,50);
    c.correct(-3600000); assert.equal(c.state.diff,50);
    c.correct(3600000); assert.equal(c.state.diff,50);
    c.advance(49999); assert.equal(c.state.diff,1); safe(c);
    c.advance(1); assert.equal(c.state.diff,0); safe(c); assert.equal(c.cleared(),0);
    c.server(10000); await c.state.resync(); assert.equal(c.state.diff,50);
    c.advance(50000); assert.equal(c.state.diff,0); safe(c);
  }
});
test('delayed initialization, throttled timer and reload use server + elapsed not callback count', () => {
  const c = countdown({delay:15250,skew:120000}); c.state.init(); assert.equal(c.state.diff,45);
  c.advance(44750); assert.equal(c.state.diff,0); safe(c);
  const reload = countdown({remaining:15000,skew:-120000}); reload.state.init(); assert.equal(reload.state.diff,15);
});
test('OS sleep with paused monotonic clock resyncs on visibility / bfcache; zero stays recoverable', async () => {
  const c=countdown(); c.state.init(); c.document.hidden=true;
  await c.state.resync(); assert.equal(c.calls(),0);
  c.server(61000); c.document.hidden=false; c.listeners.get('visibilitychange')();
  await new Promise(setImmediate); assert.equal(c.state.diff,0); safe(c);
  c.server(30000); c.listeners.get('pageshow')({persisted:true});
  await new Promise(setImmediate); assert.equal(c.state.diff,30);
  c.listeners.get('pageshow')({persisted:false}); assert.equal(c.calls(),2);
});
test('missing server Date retains usable monotonic countdown; destruction removes all work', async () => {
  const c=countdown(); c.state.init(); c.server(null); await c.state.resync();
  c.advance(20000); assert.equal(c.state.diff,40); safe(c);
  c.state.destroy(); assert.equal(c.cleared(),1); assert.equal(c.listeners.size,0);
  await c.state.resync(); assert.equal(c.calls(),1);
});
test('zero/past and fractional boundaries never expose negative/fractional units', () => {
  for (const remaining of [-100000,-1,0,1,90061500]) {
    const c=countdown({remaining}); c.state.init(); safe(c);
    assert.equal(c.state.diff,Math.max(0,Math.ceil(remaining/1000)));
    c.state.destroy();
  }
  assert.match(template,/x-show="diff > 0"/); assert.match(template,/x-cloak/);
  assert.doesNotMatch(source,/Date\.now/);
});
