import assert from 'node:assert/strict';
import test from 'node:test';
import Scanner from '../public/assets/scanner-controller.js';

function fixture(mode = 'select') {
    const nodes = {
        article: { value: 'old', options: [{ value: 'old' }, { value: 'new-id' }], dispatchEvent() {} },
        quantity: { value: '99', focus() {} },
        codes: { value: 'EXISTING', dispatchEvent() {}, focus() {} },
    };
    const instance = new Scanner({ scope: { element: { isConnected: true } } });
    Object.assign(instance, { sequence: 0, modeValue: mode, selectValue: 'article', hasSelectValue: ['select','deposit'].includes(mode), quantityValue: 'quantity', textareaValue: 'codes',
        csrfValue: 'fixture-csrf', statusTarget: { textContent: '' }, codeTarget: { value: '' }, returnUrlValue: '/consommations/retours' });
    globalThis.document = { getElementById: id => nodes[id] };
    globalThis.window = { location: { origin: 'https://stock.example.test', assign(url) { instance.redirect = url; } } };
    return { instance, nodes };
}

test('scan selects the article and clears quantity without submitting a movement', async () => {
    const f = fixture(); let calls = 0;
    globalThis.fetch = async (url, options) => {
        calls++;
        assert.equal(url, '/api/v1/articles/resolve-code'); assert.equal(options.method, 'POST');
        assert.equal(options.headers['X-CSRF-Token'], 'fixture-csrf');
        assert.deepEqual(JSON.parse(options.body), { code: '0012345678905' });
        return { ok: true, json: async () => ({ data: { id: 'new-id', active: true, sku: 'REF', name: 'Item' } }) };
    };
    await f.instance.accept('0012345678905');
    assert.equal(calls, 1); assert.equal(f.nodes.article.value, 'new-id'); assert.equal(f.nodes.quantity.value, '');
});
test('unknown code clears the prior selection and provides manual recovery', async () => {
    const f = fixture();
    globalThis.fetch = async () => ({ ok: false, json: async () => ({ detail: 'Code inconnu.' }) });
    await f.instance.accept('UNKNOWN');
    assert.equal(f.nodes.article.value, ''); assert.equal(f.instance.statusTarget.textContent, 'Code inconnu.');
});
test('registering an opaque QR URL writes text only, without requests or navigation', async () => {
    const f = fixture('register');
    globalThis.fetch = () => { throw new Error('No request expected'); };
    const qr = 'https://example.test/product?x=<script>alert(1)</script>';
    await f.instance.accept(qr);
    assert.equal(f.nodes.codes.value, `EXISTING\n${qr}`); assert.equal(f.instance.redirect, undefined);
    await f.instance.accept(qr); assert.equal(f.nodes.codes.value, `EXISTING\n${qr}`);
});
test('return scan filters eligible originals by article ID rather than creating a return', async () => {
    const f = fixture('return');
    globalThis.fetch = async () => ({ ok: true, json: async () => ({ data: { id: 'archived-id', active: false } }) });
    await f.instance.accept('RETURN-CODE');
    assert.equal(f.instance.redirect, 'https://stock.example.test/consommations/retours?article_id=archived-id');
});
test('late lookup response cannot overwrite a more recent scan', async () => {
    const f = fixture(); let resolveFirst;
    globalThis.fetch = async () => new Promise(resolve => { resolveFirst = resolve; });
    const first = f.instance.accept('FIRST');
    globalThis.fetch = async () => ({ ok: false, json: async () => ({ detail: 'Second inconnu' }) });
    await f.instance.accept('SECOND');
    resolveFirst({ ok: true, json: async () => ({ data: { id: 'new-id', active: true } }) }); await first;
    assert.equal(f.nodes.article.value, ''); assert.equal(f.instance.statusTarget.textContent, 'Second inconnu');
});

test('deposit scan accepts an archived article without submitting a return', async () => {
    const f = fixture('deposit');
    globalThis.fetch = async () => ({ ok: true, json: async () => ({ data: { id: 'new-id', active: false, sku: 'ARCHIVED', name: 'Old equipment' } }) });
    await f.instance.accept('ARCHIVED-CODE');
    assert.equal(f.nodes.article.value, 'new-id'); assert.equal(f.nodes.quantity.value, '');
    assert.equal(f.instance.redirect, undefined);
});

function lifecycleFixture() {
    const { instance } = fixture();
    const listeners = new Map();
    Object.assign(document, { hidden: false, addEventListener(name, fn) { listeners.set(name, fn); }, removeEventListener(name) { listeners.delete(name); } });
    Object.assign(window, { isSecureContext: true, addEventListener() {}, removeEventListener() {} });
    Object.defineProperty(globalThis, 'navigator', { configurable: true, value: { mediaDevices: { getUserMedia() {} } } });
    Object.assign(instance, { videoTarget: {}, startTarget: {}, resumeTarget: { hidden: true }, previewStatusTarget: { textContent: '' }, dialogTarget: { open: false, showModal() { this.open = true; }, close() { this.open = false; } } });
    instance.connect();
    return { instance, listeners };
}
test('visibility interruption stops the camera but preserves a resumable dialog', () => {
    const { instance, listeners } = lifecycleFixture();
    let starts = 0, stops = 0;
    instance.camera = { start() { starts++; }, stop() { stops++; } };
    instance.open({ preventDefault() {}, currentTarget: { focus() {} } });
    document.hidden = true; listeners.get('visibilitychange')();
    assert.equal(instance.dialogTarget.open, true); assert.equal(stops, 1);
    assert.equal(instance.resumeTarget.hidden, false); assert.match(instance.previewStatusTarget.textContent, /pause/);
    instance.resume(); assert.equal(starts, 1);
    document.hidden = false; instance.resume(); assert.equal(starts, 2);
    assert.equal(instance.resumeTarget.hidden, true);
    instance.close(); assert.equal(instance.dialogTarget.open, false);
    instance.resume(); assert.equal(starts, 2);
});
test('permission errors stay visible inside the dialog and permit a retry', () => {
    const { instance } = lifecycleFixture();
    instance.dialogTarget.open = true;
    instance.camera.onError({ name: 'NotAllowedError' });
    assert.equal(instance.dialogTarget.open, true);
    assert.match(instance.previewStatusTarget.textContent, /refusé/);
    assert.equal(instance.previewStatusTarget.textContent, instance.statusTarget.textContent);
    assert.equal(instance.resumeTarget.hidden, false);
});
test('leaving the page closes the dialog and stops capture', () => {
    const { instance } = lifecycleFixture(); let stops = 0;
    instance.dialogTarget.open = true; instance.camera = { stop() { stops++; } };
    instance.leaving(); assert.equal(instance.dialogTarget.open, false); assert.equal(stops, 1);
});
