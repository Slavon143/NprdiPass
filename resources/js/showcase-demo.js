const views = ['dashboard', 'catalogue', 'product', 'readiness', 'import', 'passport'];
const baseProducts = [
    { name: 'Industrial Storage Case', sku: 'ISC-240', score: 89, blockers: 1 },
    { name: 'Service Trolley', sku: 'ST-090', score: 89, blockers: 1 },
    { name: 'Battery Charging Station', sku: 'BCS-100', score: 81, blockers: 2 },
    { name: 'Workshop Bench', sku: 'WB-200', score: 100, blockers: 0 },
    { name: 'Tool Cabinet', sku: 'TC-120', score: 100, blockers: 0 },
];
const sampleRows = {
    csv: [{ name: 'Sample CSV Workbench', sku: 'CSV-001', score: 0, blockers: 1 }, { name: 'Sample CSV Tool Rack', sku: 'CSV-002', score: 0, blockers: 1 }],
    fortnox: [{ name: 'Sample Fortnox Cart', sku: 'FNX-DEMO-001', score: 0, blockers: 1 }, { name: 'Sample Fortnox Shelf', sku: 'FNX-DEMO-002', score: 0, blockers: 1 }],
};

export function createDemoState() {
    return { assessment: false, preview: null, imported: [], version: false };
}

// This reducer accepts only fixed examples. No provider, database, storage or
// network APIs participate, and no caller-supplied rows or account IDs are used.
export function transitionDemo(state, action) {
    if (action === 'reset') return createDemoState();
    if (action === 'fix') return { ...state, assessment: true };
    if (action === 'backToSource') return { ...state, preview: null };
    if (action === 'previewCsv') return { ...state, preview: 'csv' };
    if (action === 'previewFortnox') return { ...state, preview: 'fortnox' };
    if (action === 'apply' && Object.hasOwn(sampleRows, state.preview) && !state.imported.includes(state.preview)) {
        return { ...state, imported: [...state.imported, state.preview] };
    }
    if (action === 'publish' && state.assessment) return { ...state, version: true };
    return state;
}

export function demoProducts(state) {
    return baseProducts.map((product, index) => index === 0 && state.assessment
        ? { ...product, score: 97, blockers: 0 }
        : { ...product }).concat(state.imported.flatMap(source => (sampleRows[source] ?? []).map(row => ({ ...row }))));
}

export function demoPreview(state) {
    return (sampleRows[state.preview] ?? []).map(row => ({ ...row }));
}

export function initShowcase() {
    const showcase = document.querySelector('[data-showcase]');
    if (!showcase) return;
    const copy = JSON.parse(showcase.querySelector('#showcase-copy').textContent);
    showcase.addEventListener('keydown', event => {
        const control = event.target instanceof Element ? event.target.closest('a[role="button"][data-demo-info]') : null;
        if (control && ['Enter', ' '].includes(event.key)) { event.preventDefault(); control.click(); }
    });
    showcase.addEventListener('click', event => {
        if (!(event.target instanceof Element)) return;
        const language = event.target.closest('[data-showcase-language]');
        if (language) showcase.querySelector('[data-language-dialog]').showModal();
        const close = event.target.closest('[data-dialog-close]');
        if (close) close.closest('dialog').close();
        const information = event.target.closest('[data-demo-info]');
        if (information) { event.preventDefault(); showcase.querySelector('[data-info-dialog]').showModal(); }
        const menu = event.target.closest('[data-showcase-menu]');
        if (menu) {
            const sidebar = showcase.querySelector('.app-sidebar');
            const open = !sidebar.classList.contains('sidebar-open');
            sidebar.classList.toggle('sidebar-open', open);
            showcase.querySelectorAll('[data-showcase-menu]').forEach(button => button.setAttribute('aria-expanded', String(open)));
        }
        const publicMenu = event.target.closest('[data-showcase-public-menu]');
        if (publicMenu) {
            const navigation = showcase.querySelector('[data-public-menu]');
            navigation.hidden = !navigation.hidden;
            publicMenu.setAttribute('aria-expanded', String(!navigation.hidden));
        }
    });
    const root = showcase.matches('[data-showcase-demo]') ? showcase : null;
    if (!root) return;
    let state = createDemoState();
    let view = views.includes(root.dataset.demoView) ? root.dataset.demoView : 'dashboard';
    let workspaceView = view === 'import' ? 'dashboard' : view;
    let source = 'csv';
    const locale = root.dataset.demoLang;
    const importDialog = root.querySelector('[data-demo-import-dialog]');
    const text = (selector, value) => root.querySelectorAll(selector).forEach(node => { node.textContent = String(value); });
    const firstText = (selector, value) => root.querySelectorAll(selector).forEach(node => {
        const child = [...node.childNodes].find(child => child.nodeType === Node.TEXT_NODE && child.textContent.trim());
        if (child) child.textContent = String(value) + (child.textContent.includes('%') ? '%' : '');
    });
    const rows = (target, products) => {
        target.replaceChildren();
        products.forEach(product => {
            const row = root.querySelector('[data-demo-row-template]').content.firstElementChild.cloneNode(true);
            const featured = product.sku === 'ISC-240';
            row.querySelector('[data-demo-row-name]').textContent = product.name;
            row.querySelector('[data-demo-row-sku]').textContent = product.sku;
            row.querySelector('[data-demo-row-score]').textContent = `${product.score}%`;
            row.querySelector('[data-demo-row-status]').textContent = featured && state.version ? 'Demo v1' : copy.reference.Draft;
            const readiness = row.querySelector('.table-readiness');
            readiness.classList.toggle('needs-data', product.blockers > 0);
            readiness.querySelector('span').textContent = product.blockers > 0 ? copy.reference['Needs data'] : (locale === 'sv' ? 'Redo' : 'Ready');
            const progress = row.querySelector('[data-slot="progress"]');
            progress.setAttribute('aria-valuenow', String(product.score));
            row.querySelector('[data-demo-row-progress]').style.transform = `translateX(${product.score - 100}%)`;
            row.querySelectorAll('[data-demo-nav]').forEach(link => {
                link.setAttribute('aria-label', `${link.dataset.demoNav === 'readiness' ? copy.readiness : copy.product}: ${product.name}`);
                if (!featured) {
                    link.removeAttribute('data-demo-nav');
                    link.removeAttribute('href');
                    link.dataset.demoInfo = '';
                    link.tabIndex = 0;
                    link.setAttribute('role', 'button');
                }
            });
            target.append(row);
        });
    };
    const render = () => {
        const effectiveView = view === 'import' ? workspaceView : view;
        root.querySelectorAll('[data-demo-panel]').forEach(panel => { panel.hidden = panel.dataset.demoPanel !== effectiveView; });
        root.querySelectorAll('[data-demo-shell]').forEach(shell => { shell.hidden = shell.dataset.demoShell === 'public' ? view !== 'passport' : view === 'passport'; });
        root.querySelectorAll('[data-demo-nav]').forEach(link => {
            const active = link.dataset.demoNav === effectiveView || (link.dataset.demoSidebar === 'catalogue' && ['product', 'readiness'].includes(effectiveView));
            link.classList.toggle('active', active && link.classList.contains('sidebar-link'));
            if (active) link.setAttribute('aria-current', 'page'); else link.removeAttribute('aria-current');
        });
        root.querySelectorAll('[data-demo-locale]').forEach(link => { const url = new URL(link.href); url.searchParams.set('view', view); link.href = url.href; });
        text('[data-demo-page-title]', copy.reference[['product', 'readiness', 'catalogue'].includes(effectiveView) ? 'Products' : 'Dashboard']);
        firstText('[data-demo-score-number]', state.assessment ? 97 : 89);
        root.querySelectorAll('[data-demo-progress]').forEach(progress => {
            progress.setAttribute('aria-valuenow', state.assessment ? '97' : '89');
            progress.setAttribute('aria-valuetext', state.assessment ? '97%' : '89%');
            const indicator = progress.querySelector('[data-slot="progress-indicator"]');
            if (indicator) indicator.style.transform = `translateX(${state.assessment ? -3 : -11}%)`;
        });
        root.querySelectorAll('[data-demo-blocker]').forEach(node => { node.hidden = state.assessment; });
        root.querySelectorAll('[data-demo-fixed]').forEach(node => { node.hidden = !state.assessment; });
        const readinessHeading = root.querySelector('[data-demo-value="status"]');
        if (readinessHeading) readinessHeading.textContent = state.assessment ? copy.reference['Ready with a warning'] : copy.reference['A little more to complete.'];
        text('[data-demo-value="passed"]', state.assessment ? 34 : 33);
        text('[data-demo-blocker-count]', state.assessment ? 0 : 1);
        text('[data-demo-passed-label]', `${state.assessment ? 34 : 33} ${locale === 'sv' ? 'kontroller' : 'checks'}`);
        text('[data-demo-publish-hint]', state.assessment ? copy.reference['Ready with a warning'] : copy.reference['Resolve blockers to publish']);
        text('[data-demo-value="version"]', state.version ? copy.demo_version : copy.reference['Draft preview']);
        root.querySelector('[data-demo-action="publish"]').disabled = !state.assessment;
        const products = demoProducts(state);
        text('[data-demo-stat="products"]', products.length);
        text('[data-demo-stat="ready_products"]', products.filter(product => product.blockers === 0).length);
        text('[data-demo-stat="required_fixes"]', products.reduce((sum, product) => sum + product.blockers, 0));
        text('[data-demo-stat="versions"]', state.version ? 1 : 0);
        text('[data-demo-attention-count]', `${products.filter(product => product.blockers > 0).length} ${copy.products.toLowerCase()}`);
        text('.capacity-summary p', `Pilot · ${products.length}/100 ${copy.products.toLowerCase()}`);
        root.querySelectorAll('[data-demo-products]').forEach(target => rows(target, target.dataset.demoProducts === 'attention' ? products.filter(product => product.blockers > 0) : products));
        const stage = state.preview === null ? 'source' : state.imported.includes(state.preview) ? 'complete' : 'preview';
        root.querySelectorAll('[data-demo-import-stage]').forEach(node => { node.hidden = node.dataset.demoImportStage !== stage; });
        root.querySelectorAll('[data-demo-import-step]').forEach(node => node.classList.toggle('active', Number(node.dataset.demoImportStep) === ['source', 'preview', 'complete'].indexOf(stage)));
        root.querySelectorAll('[data-demo-source]').forEach(button => { button.classList.toggle('selected', button.dataset.demoSource === source); button.setAttribute('aria-pressed', String(button.dataset.demoSource === source)); });
        text('[data-demo-sample-prompt]', source === 'fortnox' ? copy.sample_fortnox : copy.reference['Choose sample CSV']);
        text('[data-demo-source-name]', state.preview === 'fortnox' ? 'Fortnox · demo' : 'CSV · demo');
        const importRows = root.querySelector('[data-demo-import-rows]'); importRows.replaceChildren();
        demoPreview(state).forEach(product => {
            const row = document.createElement('tr');
            [product.name, product.sku, copy.reference.Draft].forEach(value => { const cell = document.createElement('td'); cell.textContent = value; row.append(cell); });
            importRows.append(row);
        });
        root.querySelector('[data-demo-action="apply"]').disabled = state.preview === null || state.imported.includes(state.preview);
        if (view === 'import' && !importDialog.open) importDialog.showModal();
        if (view !== 'import' && importDialog.open) importDialog.close();
    };
    const navigate = next => {
        if (!views.includes(next)) return;
        if (next === 'import' && view !== 'import') workspaceView = view === 'passport' ? 'product' : view;
        view = next;
        if (next !== 'import') workspaceView = next;
        const url = new URL(window.location.href); url.searchParams.set('view', view); url.searchParams.set('lang', locale);
        window.history.pushState({}, '', url);
        render();
        root.querySelector('.app-sidebar').classList.remove('sidebar-open');
        if (view !== 'import') {
            const heading = root.querySelector(`[data-demo-panel="${view}"] h1`);
            heading?.setAttribute('tabindex', '-1'); heading?.focus({ preventScroll: true });
            window.scrollTo({ top: 0 });
        }
    };
    importDialog.addEventListener('cancel', event => { event.preventDefault(); navigate(workspaceView); });
    root.addEventListener('click', event => {
        if (!(event.target instanceof Element)) return;
        if (event.target.closest('[data-demo-import-close]')) { navigate(workspaceView); return; }
        const selection = event.target.closest('[data-demo-source]');
        if (selection) { source = selection.dataset.demoSource; state = transitionDemo(state, 'backToSource'); render(); return; }
        const device = event.target.closest('[data-demo-device]');
        if (device) {
            root.querySelector('.passport-paper').classList.toggle('mobile-preview', device.dataset.demoDevice === 'mobile');
            root.querySelectorAll('[data-demo-device]').forEach(button => { button.classList.toggle('selected', button === device); button.setAttribute('aria-pressed', String(button === device)); });
            return;
        }
        const link = event.target.closest('[data-demo-nav]');
        if (link && !event.ctrlKey && !event.metaKey && !event.shiftKey && event.button === 0) { event.preventDefault(); navigate(link.dataset.demoNav); return; }
        const button = event.target.closest('[data-demo-action]');
        if (!button || button.disabled) return;
        const action = button.dataset.demoAction === 'previewSelected' ? source === 'fortnox' ? 'previewFortnox' : 'previewCsv' : button.dataset.demoAction;
        state = transitionDemo(state, action);
        if (action === 'previewCsv') source = 'csv';
        if (action === 'previewFortnox') source = 'fortnox';
        render();
        const message = { reset: 'reset_message', fix: 'fix_message', publish: 'published_message' }[action];
        text('[data-demo-notice]', message ? copy[message] : action === 'check' ? copy.reference['Readiness checked locally.'] : '');
        if (action === 'publish' && state.version) navigate('passport');
    });
    window.addEventListener('popstate', () => {
        const next = new URL(window.location.href).searchParams.get('view');
        view = views.includes(next) ? next : 'dashboard';
        if (view !== 'import') workspaceView = view;
        render();
    });
    render();
}
