import assert from 'node:assert/strict';
import test from 'node:test';
import appShell from '../../resources/js/app-shell.js';
import { generateRuntimeFunction } from '../../node_modules/@alpinejs/csp/src/parser.js';

function storage(t, initial = null) {
    const previousWindow = globalThis.window;
    const values = new Map(initial === null ? [] : [['nordipass.sidebar.collapsed', initial]]);
    globalThis.window = { localStorage: {
        getItem: (key) => values.get(key) ?? null,
        setItem: (key, value) => values.set(key, value),
    } };
    t.after(() => { globalThis.window = previousWindow; });
    return values;
}

test('sidebar preference survives navigation and can be expanded again', (t) => {
    storage(t);
    const shell = appShell();
    shell.init();
    assert.equal(shell.sidebarCollapsed, false);
    shell.toggleSidebar();
    const nextPage = appShell();
    nextPage.init();
    assert.equal(nextPage.sidebarCollapsed, true);
    nextPage.toggleSidebar();
    const thirdPage = appShell();
    thirdPage.init();
    assert.equal(thirdPage.sidebarCollapsed, false);
});

test('unavailable storage does not break sidebar controls', (t) => {
    storage(t);
    globalThis.window.localStorage = {
        getItem() { throw new Error('blocked'); },
        setItem() { throw new Error('blocked'); },
    };
    const shell = appShell();
    shell.init();
    shell.toggleSidebar();
    assert.equal(shell.sidebarCollapsed, true);
    shell.toggleSidebar();
    assert.equal(shell.sidebarCollapsed, false);
});

test('desktop preference does not collapse the mobile drawer', (t) => {
    storage(t, 'true');
    const shell = appShell();
    shell.init();
    assert.equal(shell.sidebarOpen, false);
    shell.sidebarOpen = true;
    shell.closeMobileSidebar();
    assert.equal(shell.sidebarOpen, false);
    assert.equal(shell.sidebarCollapsed, true);
});

test('sidebar state bindings work with the strict CSP parser', (t) => {
    storage(t);
    const shell = appShell();
    const collapsed = generateRuntimeFunction('sidebarCollapsed');
    const expanded = generateRuntimeFunction('!sidebarCollapsed');
    assert.equal(collapsed({ scope: shell }), false);
    assert.equal(expanded({ scope: shell }), true);
    shell.toggleSidebar();
    assert.equal(collapsed({ scope: shell }), true);
    assert.equal(expanded({ scope: shell }), false);
});
