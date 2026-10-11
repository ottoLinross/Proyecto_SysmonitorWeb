const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const path = require('node:path');
const source = fs.readFileSync(path.join(__dirname, '../../public/js/cpu-memory.js'), 'utf8');

class Element {
    constructor() { this.dataset = {}; this.children = []; this.textContent = ''; }
    append(child) { this.children.push(child); }
    replaceChildren(fragment) { this.children = fragment.children; }
    removeAttribute(name) { if (name === 'datetime') delete this.dateTime; }
    set innerHTML(value) { throw new Error('Unsafe DOM API'); }
}

const definitions = {
    'cpu.model': 'text', 'cpu.logical_processors': 'integer', 'cpu.usage_percent': 'percent',
    'uptime.seconds': 'number', 'uptime.formatted': 'text',
    'load_average.one_minute': 'load', 'load_average.five_minutes': 'load', 'load_average.fifteen_minutes': 'load',
    'memory.total_bytes': 'bytes', 'memory.used_bytes': 'bytes', 'memory.free_bytes': 'bytes', 'memory.cached_bytes': 'bytes',
    'swap.total_bytes': 'bytes', 'swap.used_bytes': 'bytes', 'swap.free_bytes': 'bytes',
};
const payload = () => ({
    status: 'ok', cpu: { model: '<img src=x onerror=alert(1)>', logical_processors: 4, usage_percent: 42.5 },
    uptime: { seconds: 60.25, formatted: '0 días, 0 h, 1 min, 0 s' },
    load_average: { one_minute: 1.25, five_minutes: 2.5, fifteen_minutes: 3.75 },
    memory: { total_bytes: 1048576, used_bytes: 524288, free_bytes: 262144, cached_bytes: 131072 },
    swap: { total_bytes: 0, used_bytes: 0, free_bytes: 0 },
    top_processes: { cpu: [{ pid: 42, cpu_percent: 150.0 }], memory: [{ pid: 9, memory_percent: 5.5 }] },
    timestamp: '2026-10-10T12:00:00+00:00',
});

function harness() {
    const values = Object.entries(definitions).map(([metric, format]) => {
        const element = new Element(); element.dataset = { metric, format }; element.textContent = 'Server value'; return element;
    });
    const tables = ['cpu', 'memory'].map(ranking => {
        const element = new Element(); element.dataset.ranking = ranking; element.children = ['Server row']; return element;
    });
    const panel = new Element(); panel.dataset.metricsUrl = '/cpu-memoria/metricas';
    panel.querySelectorAll = selector => selector === '[data-metric]' ? values : tables;
    const status = new Element(); const lastRead = new Element(); lastRead.textContent = 'No disponible';
    const timers = new Map(); const listeners = {}; const calls = []; let nextId = 0;
    const state = { respond: async () => ({ ok: true, json: async () => payload() }) };
    vm.runInNewContext(source, {
        document: {
            getElementById: id => ({ 'cpu-memory-panel': panel, 'metrics-status': status, 'metrics-last-read': lastRead })[id],
            createElement: () => new Element(), createDocumentFragment: () => new Element(),
        },
        window: { addEventListener: (event, callback) => { listeners[event] = callback; } },
        AbortController,
        setTimeout: (callback, delay) => { const id = ++nextId; timers.set(id, { callback, delay }); return id; },
        clearTimeout: id => timers.delete(id),
        fetch: (url, options) => { calls.push({ url, options }); return state.respond(options); },
    });
    function trigger(delay) {
        const entry = [...timers].find(([, timer]) => timer.delay === delay);
        assert.ok(entry, `Timer ${delay} exists`); timers.delete(entry[0]); return entry[1].callback();
    }
    return { values, tables, status, lastRead, timers, listeners, calls, state, trigger,
        value: key => values.find(element => element.dataset.metric === key).textContent };
}

test('updates all targets safely, formats units and records timestamp', async () => {
    const h = harness(); assert.equal(h.calls.length, 0);
    await h.trigger(5000);
    assert.equal(h.calls.length, 1); assert.equal(h.calls[0].url, '/cpu-memoria/metricas');
    assert.equal(h.calls[0].options.method, 'GET'); assert.equal(h.calls[0].options.cache, 'no-store');
    for (const [key, format] of Object.entries(definitions)) {
        const value = key.split('.').reduce((data, part) => data[part], payload());
        const expected = format === 'bytes' ? (value / 1048576).toFixed(2) + ' MiB'
            : format === 'load' ? value.toFixed(2) : format === 'percent' ? value.toFixed(1) + ' %' : String(value);
        assert.equal(h.value(key), expected);
    }
    assert.equal(h.value('cpu.model'), '<img src=x onerror=alert(1)>');
    assert.equal(h.tables[0].children[0].children[0].textContent, '42');
    assert.equal(h.tables[0].children[0].children[1].textContent, 'No disponible');
    assert.equal(h.tables[0].children[0].children[2].textContent, '150.0 %');
    assert.equal(h.tables[1].children[0].children[2].textContent, '5.5 %');
    assert.equal(h.lastRead.dateTime, '2026-10-10T12:00:00.000Z'); assert.equal(h.status.dataset.state, 'ok');
    assert.equal(h.timers.size, 1);
});

test('slow requests never queue another poll before completion', async () => {
    const h = harness(); let resolve;
    h.state.respond = () => new Promise(done => { resolve = done; });
    const pending = h.trigger(5000);
    assert.equal(h.status.dataset.state, 'updating'); assert.equal(h.calls.length, 1);
    assert.equal([...h.timers.values()].filter(timer => timer.delay === 5000).length, 0);
    resolve({ ok: true, json: async () => payload() }); await pending;
    assert.equal([...h.timers.values()].filter(timer => timer.delay === 5000).length, 1);
});

test('HTTP, network, JSON and malformed payload errors preserve last valid data and retry', async () => {
    const h = harness(); await h.trigger(5000);
    const values = h.values.map(element => element.textContent); const rows = h.tables.map(element => element.children);
    const time = h.lastRead.textContent;
    const malformed = payload(); malformed.top_processes.memory = [{ pid: 'bad', memory_percent: 10 }];
    for (const respond of [
        async () => ({ ok: false }),
        async () => { throw new Error('network'); },
        async () => ({ ok: true, json: async () => { throw new SyntaxError('bad JSON'); } }),
        async () => ({ ok: true, json: async () => malformed }),
    ]) {
        h.state.respond = respond; await h.trigger(5000);
        assert.deepEqual(h.values.map(element => element.textContent), values);
        h.tables.forEach((element, index) => assert.equal(element.children, rows[index]));
        assert.equal(h.lastRead.textContent, time); assert.equal(h.status.dataset.state, 'error');
    }
    h.state.respond = async () => ({ ok: true, json: async () => payload() });
    await h.trigger(5000); assert.equal(h.status.dataset.state, 'ok');
});

test('null and missing metrics are readable; zero and empty rankings remain valid', async () => {
    const h = harness(); const data = payload();
    data.cpu.model = null; delete data.uptime.formatted; data.cpu.usage_percent = 0;
    data.memory.cached_bytes = null; data.top_processes = { cpu: [], memory: [] }; data.timestamp = null;
    h.state.respond = async () => ({ ok: true, json: async () => data }); await h.trigger(5000);
    assert.equal(h.value('cpu.model'), 'No disponible'); assert.equal(h.value('uptime.formatted'), 'No disponible');
    assert.equal(h.value('memory.cached_bytes'), 'No disponible'); assert.equal(h.value('cpu.usage_percent'), '0.0 %');
    assert.equal(h.value('swap.total_bytes'), '0.00 MiB'); assert.equal(h.lastRead.textContent, 'No disponible');
    h.tables.forEach(table => assert.equal(table.children[0].children[0].textContent, 'No hay procesos disponibles para mostrar.'));
});

test('invalid numbers, timestamp and oversized rankings do not partially update the interface', async () => {
    const h = harness();
    for (const mutate of [
        data => { data.cpu.usage_percent = NaN; }, data => { data.cpu.usage_percent = 101; },
        data => { data.memory.used_bytes = -1; }, data => { data.load_average.one_minute = 'bad'; },
        data => { data.timestamp = 'invalid'; }, data => { data.top_processes.cpu = Array(6).fill({ pid: 1, cpu_percent: 1 }); },
    ]) {
        const data = payload(); mutate(data);
        h.state.respond = async () => ({ ok: true, json: async () => data }); await h.trigger(5000);
        assert.equal(h.value('cpu.model'), 'Server value'); assert.equal(h.status.dataset.state, 'error');
    }
});

test('timeout aborts the active request and allows a later retry', async () => {
    const h = harness();
    h.state.respond = options => new Promise((resolve, reject) => options.signal.addEventListener('abort', () => reject(new Error('aborted'))));
    const pending = h.trigger(5000); h.trigger(10000); await pending;
    assert.equal(h.calls[0].options.signal.aborted, true); assert.equal(h.status.dataset.state, 'error');
    assert.equal(h.timers.size, 1);
});

test('leaving cancels requests and timers; returning from page cache restarts polling', async () => {
    const h = harness();
    h.state.respond = options => new Promise((resolve, reject) => options.signal.addEventListener('abort', () => reject(new Error('aborted'))));
    const pending = h.trigger(5000); h.listeners.pagehide(); await pending;
    assert.equal(h.timers.size, 0); assert.equal(h.value('cpu.model'), 'Server value');
    h.listeners.pageshow({ persisted: true }); assert.equal(h.timers.size, 1);
    h.state.respond = async () => ({ ok: true, json: async () => payload() }); await h.trigger(5000);
    assert.equal(h.status.dataset.state, 'ok');
});

test('returning before an aborted request settles never duplicates polling timers', async () => {
    const h = harness();
    h.state.respond = options => new Promise((resolve, reject) => options.signal.addEventListener('abort', () => reject(new Error('aborted'))));
    const pending = h.trigger(5000);
    h.listeners.pagehide(); h.listeners.pageshow({ persisted: true }); await pending;
    assert.equal(h.timers.size, 1);
    assert.equal([...h.timers.values()][0].delay, 5000);
});
