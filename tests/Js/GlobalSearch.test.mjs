import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import globalSearch from '../../resources/js/global-search.js';
import { generateRuntimeFunction } from '../../node_modules/@alpinejs/csp/src/parser.js';

function component(t) {
    const previousWindow = globalThis.window;
    const previousFetch = globalThis.fetch;
    const previousIframe = globalThis.HTMLIFrameElement;
    const previousScript = globalThis.HTMLScriptElement;
    globalThis.HTMLIFrameElement = class {};
    globalThis.HTMLScriptElement = class {};
    globalThis.window = { location: { origin: 'https://nordipass.test' } };
    t.after(() => {
        globalThis.window = previousWindow;
        globalThis.fetch = previousFetch;
        globalThis.HTMLIFrameElement = previousIframe;
        globalThis.HTMLScriptElement = previousScript;
    });
    const search = globalSearch();
    search.$refs = {
        form: {
            action: 'https://nordipass.test/catalog/products',
            dataset: { suggestionsUrl: 'https://nordipass.test/catalog/products/suggestions' },
        },
        input: { value: '' },
    };
    t.after(() => { clearTimeout(search.timer); search.controller?.abort(); });
    return search;
}

test('header search uses its form URLs for suggestions and full results', async (t) => {
    const search = component(t);
    let requestedUrl;
    globalThis.fetch = async (url) => {
        requestedUrl = url.toString();
        return { ok: true, json: async () => ({ data: [{ name: 'Anti-Fatigue Mat' }] }) };
    };
    search.query = 'anti';
    await search.search();
    assert.equal(requestedUrl, 'https://nordipass.test/catalog/products/suggestions?q=anti');
    assert.equal(search.resultsUrl(), 'https://nordipass.test/catalog/products?q=anti');
    assert.equal(search.items[0].name, 'Anti-Fatigue Mat');
});

test('strict CSP evaluates mutually exclusive loading, error and empty search states', (t) => {
    const search = component(t);
    const view = readFileSync(new URL('../../resources/views/layouts/header.blade.php', import.meta.url), 'utf8');
    const conditions = [...view.matchAll(/<p x-show="([^"]+)"/g)].map(match => generateRuntimeFunction(match[1]));
    assert.equal(conditions.length, 3);
    for (const [loading, error, expected] of [[true, false, [true, false, false]], [false, true, [false, true, false]], [false, false, [false, false, true]]]) {
        Object.assign(search, { loading, error });
        assert.deepEqual(conditions.map(condition => Boolean(condition({ scope: search }))), expected);
    }
    search.items = [{ name: 'Match' }];
    assert.deepEqual(conditions.map(condition => Boolean(condition({ scope: search }))), [false, false, false]);
});

test('failed search clears loading and reports an error instead of empty results', async (t) => {
    const search = component(t);
    globalThis.fetch = async () => ({ ok: false });
    search.query = 'failed';
    search.loading = true;
    await search.search();
    assert.equal(search.loading, false);
    assert.equal(search.error, true);
});

test('an older search response cannot replace the current query results', async (t) => {
    const search = component(t);
    let resolveFirst;
    let call = 0;
    globalThis.fetch = async () => {
        if (++call === 1) return new Promise(resolve => { resolveFirst = resolve; });
        return { ok: true, json: async () => ({ data: [{ name: 'New result' }] }) };
    };
    search.query = 'old';
    const first = search.search();
    search.query = 'new';
    await search.search();
    resolveFirst({ ok: true, json: async () => ({ data: [{ name: 'Old result' }] }) });
    await first;
    assert.equal(search.items[0].name, 'New result');
});
