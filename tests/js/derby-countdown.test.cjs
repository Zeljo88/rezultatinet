const { readFileSync } = require('node:fs');
const { resolve } = require('node:path');
const vm = require('node:vm');
const assert = require('node:assert/strict');
const test = require('node:test');
const template = readFileSync(resolve(__dirname, '../../resources/views/components/derby-countdown.blade.php'), 'utf8');
const source = template.match(/x-data="([\s\S]*?)"\s+x-cloak/)[1]
  .replace('{{ $kickoff->getTimestamp() * 1000 }}', 'kickoff');
function countdown(iso, now) {
  let clock = Date.parse(now), callback, cleared = 0, scheduled = 0;
  const context = vm.createContext({
    kickoff: Date.parse(iso), Date: { now: () => clock },
    setInterval: fn => { callback = fn; scheduled++; return 42; },
    clearInterval: () => { cleared++; },
  });
  const state = vm.runInContext(`(${source})`, context);
  return { state, now: v => { clock = Date.parse(v); }, tick: () => callback(), cleared: () => cleared, scheduled: () => scheduled };
}
test('future fractional second rounds to a positive whole second; rolls all units', () => {
  const c = countdown('2026-10-11T13:00:00+02:00', '2026-10-10T09:58:58.500Z');
  c.state.init();
  assert.deepEqual([c.state.days, c.state.hours, c.state.mins, c.state.secs], [1, 1, 1, 2]);
  for (const part of ['days', 'hours', 'mins', 'secs']) assert.ok(Number.isInteger(c.state[part]) && c.state[part] >= 0);
  c.now('2026-10-11T10:59:59.999Z'); c.tick(); assert.equal(c.state.diff, 1);
  c.now('2026-10-11T11:00:00Z'); c.tick(); assert.equal(c.state.diff, 0); assert.equal(c.cleared(), 1);
});
test('zero/past hide immediately without a running interval or negative fractions', () => {
  for (const now of ['2026-10-10T11:00:00Z', '2026-10-10T11:00:00.001Z', '2026-11-11T12:00:00Z']) {
    const c = countdown('2026-10-10T13:00:00+02:00', now); c.state.init();
    assert.deepEqual([c.state.diff,c.state.days,c.state.hours,c.state.mins,c.state.secs], [0,0,0,0,0]);
    assert.equal(c.scheduled(), 0);
  }
  assert.match(template, /x-show="diff > 0"/);
});
test('sleep/throttling recomputes from wall-clock epoch and destruction clears timer', () => {
  const c = countdown('2026-10-10T13:00:00Z', '2026-10-10T05:17:00Z'); c.state.init();
  assert.equal(c.state.diff, 27780);
  c.now('2026-10-10T12:59:59Z'); c.tick(); assert.equal(c.state.diff, 1);
  c.state.destroy(); assert.equal(c.cleared(), 1);
});
test('Z, positive and negative ISO offsets identify the same kickoff through DST fallback', () => {
  for (const iso of ['2026-10-25T01:30:00Z','2026-10-25T02:30:00+01:00','2026-10-24T21:30:00-04:00']) {
    const c = countdown(iso, '2026-10-25T00:30:00Z'); c.state.init();
    assert.equal(c.state.diff, 3600);
  }
});
