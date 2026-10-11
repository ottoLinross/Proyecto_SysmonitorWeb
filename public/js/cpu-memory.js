(() => {
    'use strict';

    const panel = document.getElementById('cpu-memory-panel');
    if (!panel) return;

    const status = document.getElementById('metrics-status');
    const lastRead = document.getElementById('metrics-last-read');
    const metrics = [...panel.querySelectorAll('[data-metric]')];
    const rankings = [...panel.querySelectorAll('[data-ranking]')];
    const interval = 5000;
    const requestTimeout = 10000;
    const unavailable = 'No disponible';
    const historyLimit = 60;
    const history = [];
    const charts = ['cpu', 'memory'].map(kind => ({
        kind,
        plot: document.getElementById(`${kind}-chart-plot`),
        start: document.getElementById(`${kind}-chart-start`),
        end: document.getElementById(`${kind}-chart-end`),
        recent: document.getElementById(`${kind}-chart-recent`),
    }));
    let timer = null;
    let controller = null;
    let running = false;
    let stopped = false;

    const isObject = value => value !== null && typeof value === 'object' && !Array.isArray(value);
    const valueAt = (data, path) => path.split('.').reduce((value, key) => value?.[key], data);

    function memoryPercent(memory) {
        const total = memory.total_bytes;
        const used = memory.used_bytes;
        if (!Number.isFinite(total) || !Number.isFinite(used) || total <= 0 || used < 0 || used > total) return null;
        const percent = (used / total) * 100;
        return Number.isFinite(percent) ? Math.max(0, Math.min(100, percent)) : null;
    }

    function svgElement(name, attributes) {
        const element = document.createElementNS('http://www.w3.org/2000/svg', name);
        for (const [key, value] of Object.entries(attributes)) element.setAttribute(key, String(value));
        return element;
    }

    function timeLabel(time) {
        return new Date(time).toLocaleTimeString('es', { hour: '2-digit', minute: '2-digit', second: '2-digit' });
    }

    function drawCharts() {
        const end = history[history.length - 1].time;
        const start = end - Math.max(60000, end - history[0].time);
        for (const chart of charts) {
            if (!chart.plot || !chart.start || !chart.end || !chart.recent) continue;
            const fragment = document.createDocumentFragment();
            let path = '';
            let connected = false;
            for (const sample of history) {
                const value = sample[chart.kind];
                if (value === null) { connected = false; continue; }
                const x = 50 + ((sample.time - start) / (end - start)) * 540;
                const y = 180 - (value / 100) * 160;
                path += `${connected ? 'L' : 'M'}${x.toFixed(2)},${y.toFixed(2)} `;
                connected = true;
                fragment.append(svgElement('circle', { cx: x.toFixed(2), cy: y.toFixed(2), r: 3, class: 'chart-point' }));
            }
            if (path !== '') fragment.append(svgElement('path', { d: path.trim(), class: 'chart-line' }));
            chart.plot.replaceChildren(fragment);
            chart.start.textContent = timeLabel(start);
            chart.end.textContent = timeLabel(end);
            chart.recent.textContent = 'Lecturas recientes: ' + history.slice(-5).map(sample =>
                `${timeLabel(sample.time)}: ${sample[chart.kind] === null ? unavailable : sample[chart.kind].toFixed(1) + ' %'}`
            ).join('; ');
        }
    }

    function recordSample(snapshot) {
        if (!snapshot.date || (snapshot.cpu === null && snapshot.memory === null)) return;
        const time = snapshot.date.getTime();
        // No duplicar timestamps ni introducir muestras fuera de orden.
        if (history.length && time <= history[history.length - 1].time) return;
        history.push({ time, cpu: snapshot.cpu, memory: snapshot.memory });
        if (history.length > historyLimit) history.splice(0, history.length - historyLimit);
        drawCharts();
    }

    function format(value, kind) {
        if (value === null || value === undefined) return unavailable;
        if (kind === 'text') {
            if (typeof value !== 'string') throw new Error('Invalid text');
            return value.trim() === '' ? unavailable : value;
        }
        if (typeof value !== 'number' || !Number.isFinite(value) || value < 0) {
            throw new Error('Invalid number');
        }
        if (kind === 'integer' && (!Number.isInteger(value) || value < 1)) throw new Error('Invalid count');
        if (kind === 'bytes' && !Number.isInteger(value)) throw new Error('Invalid bytes');
        if (kind === 'percent') return value.toFixed(1) + ' %';
        if (kind === 'load') return value.toFixed(2);
        if (kind === 'bytes') return (value / 1048576).toFixed(2) + ' MiB';
        return String(value);
    }

    function buildRanking(rows, kind) {
        if (!Array.isArray(rows) || rows.length > 5) throw new Error('Invalid ranking');
        const fragment = document.createDocumentFragment();
        const metric = kind === 'cpu' ? 'cpu_percent' : 'memory_percent';
        for (const process of rows) {
            if (!isObject(process) || !Number.isInteger(process.pid) || process.pid < 1) {
                throw new Error('Invalid process');
            }
            const row = document.createElement('tr');
            // El contrato público excluye comandos. No asociar un PID nuevo con un comando antiguo.
            for (const [text, className] of [
                [String(process.pid), 'numeric'],
                [unavailable, 'command'],
                [format(process[metric], 'percent'), 'numeric'],
            ]) {
                const cell = document.createElement('td');
                cell.className = className;
                cell.textContent = text;
                row.append(cell);
            }
            fragment.append(row);
        }
        if (rows.length === 0) {
            const row = document.createElement('tr');
            const cell = document.createElement('td');
            cell.colSpan = 3;
            cell.className = 'empty';
            cell.textContent = 'No hay procesos disponibles para mostrar.';
            row.append(cell);
            fragment.append(row);
        }
        return fragment;
    }

    function prepare(data) {
        if (!isObject(data) || data.status !== 'ok' ||
            !['cpu', 'uptime', 'load_average', 'memory', 'swap', 'top_processes'].every(key => isObject(data[key]))) {
            throw new Error('Invalid payload');
        }
        if (data.cpu.usage_percent != null && data.cpu.usage_percent > 100) throw new Error('Invalid usage');
        const values = metrics.map(element => [element, format(valueAt(data, element.dataset.metric), element.dataset.format)]);
        const tables = rankings.map(element => [element, buildRanking(data.top_processes[element.dataset.ranking], element.dataset.ranking)]);
        let date = null;
        if (data.timestamp != null) {
            if (typeof data.timestamp !== 'string') throw new Error('Invalid timestamp');
            date = new Date(data.timestamp);
            if (!Number.isFinite(date.getTime())) throw new Error('Invalid timestamp');
        }
        return { values, tables, date, cpu: data.cpu.usage_percent ?? null, memory: memoryPercent(data.memory) };
    }

    async function refresh() {
        if (running || stopped) return;
        running = true;
        controller = new AbortController();
        const timeout = setTimeout(() => controller?.abort(), requestTimeout);
        status.dataset.state = 'updating';
        status.textContent = 'Actualizando métricas…';
        try {
            const response = await fetch(panel.dataset.metricsUrl, {
                method: 'GET', headers: { Accept: 'application/json' },
                credentials: 'same-origin', cache: 'no-store', signal: controller.signal,
            });
            if (!response.ok) throw new Error('HTTP error');
            const snapshot = prepare(await response.json());
            if (stopped) return;
            // Validar y construir todo antes de reemplazar los últimos datos válidos.
            for (const [element, text] of snapshot.values) element.textContent = text;
            for (const [element, fragment] of snapshot.tables) element.replaceChildren(fragment);
            if (snapshot.date) {
                lastRead.dateTime = snapshot.date.toISOString();
                lastRead.textContent = snapshot.date.toLocaleTimeString('es', { hour: '2-digit', minute: '2-digit', second: '2-digit' });
            } else {
                lastRead.removeAttribute('datetime');
                lastRead.textContent = unavailable;
            }
            recordSample(snapshot);
            status.dataset.state = 'ok';
            status.textContent = 'Métricas actualizadas.';
        } catch {
            if (!stopped) {
                status.dataset.state = 'error';
                status.textContent = 'No se pudo actualizar. Se conservan los últimos valores válidos; se reintentará.';
            }
        } finally {
            clearTimeout(timeout);
            controller = null;
            running = false;
            clearTimeout(timer);
            if (!stopped) timer = setTimeout(refresh, interval);
        }
    }

    window.addEventListener('pagehide', () => {
        stopped = true;
        clearTimeout(timer);
        controller?.abort();
    });
    window.addEventListener('pageshow', event => {
        if (event.persisted) {
            stopped = false;
            clearTimeout(timer);
            if (!running) timer = setTimeout(refresh, interval);
        }
    });
    timer = setTimeout(refresh, interval);
})();
