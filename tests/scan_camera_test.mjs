import assert from 'node:assert/strict';
import test from 'node:test';
import { readFile } from 'node:fs/promises';

// Import the browser module without changing the application's module layout.
const source = await readFile(new URL('../public/assets/scan-camera.js', import.meta.url), 'utf8');
const { ScanCamera } = await import(`data:text/javascript;base64,${Buffer.from(source).toString('base64')}`);
const deferred = () => { let resolve; const promise = new Promise(r => resolve = r); return { promise, resolve }; };
const tick = () => new Promise(resolve => setImmediate(resolve));

function fixture({ permission, decode } = {}) {
    let stops = 0, controlStops = 0, callback, constraints;
    const track = { stop() { stops++; } };
    const stream = { getTracks: () => [track] };
    const results = [], errors = [];
    const video = { srcObject: stream, pause() {} };
    const reader = { decodeFromStream: async (_stream, _video, cb) => { callback = cb; return decode ? decode.promise : { stop() { controlStops++; } }; } };
    const camera = new ScanCamera({ video,
        mediaDevices: { async getUserMedia(value) { constraints = value; return permission ? permission.promise : stream; } },
        createReader: async () => reader, onResult: result => results.push(result), onError: error => errors.push(error),
    });
    return { camera, stream, video, results, errors, get constraints() { return constraints; }, get stops() { return stops; }, get controlStops() { return controlStops; }, read(text) { callback({ getText: () => text }); }, error(error) { callback(undefined, error); } };
}

test('rear camera is requested without audio and duplicate frames are ignored', async () => {
    const f = fixture(); await f.camera.start();
    assert.equal(f.constraints.audio, false);
    assert.equal(f.constraints.video.facingMode.ideal, 'environment');
    f.read('0012345678905'); f.read('0012345678905');
    assert.deepEqual(f.results, ['0012345678905']);
    assert.ok(f.stops > 0); assert.equal(f.video.srcObject, null);
});
test('closing while permission is pending releases the late stream', async () => {
    const permission = deferred(), f = fixture({ permission });
    const pending = f.camera.start(); await tick(); f.camera.stop();
    permission.resolve(f.stream); await pending;
    assert.ok(f.stops > 0); assert.deepEqual(f.results, []);
});
test('closing while decoder startup is pending also stops the late controls', async () => {
    const decode = deferred(), f = fixture({ decode });
    const pending = f.camera.start(); await tick(); f.camera.stop();
    let stopped = false; decode.resolve({ stop() { stopped = true; } }); await pending;
    assert.equal(stopped, true); assert.ok(f.stops > 0);
    f.read('stale'); assert.deepEqual(f.results, []);
});
test('camera permission failure is reported without creating a stream', async () => {
    const f = fixture();
    f.camera.mediaDevices = { getUserMedia: async () => { throw Object.assign(new Error(), { name: 'NotAllowedError' }); } };
    await f.camera.start(); assert.equal(f.errors[0].name, 'NotAllowedError'); assert.deepEqual(f.results, []);
});
test('a QR payload is returned as text without navigation or execution', async () => {
    const f = fixture(); await f.camera.start();
    f.read('https://example.test/article?value=<script>alert(1)</script>');
    assert.equal(f.results[0], 'https://example.test/article?value=<script>alert(1)</script>');
});
test('a frame with no readable code does not stop subsequent reads', async () => {
    const f = fixture(); await f.camera.start();
    f.error({ getKind: () => 'NotFoundException' });
    f.read('AFTER-BLANK-FRAME');
    assert.deepEqual(f.results, ['AFTER-BLANK-FRAME']); assert.deepEqual(f.errors, []);
});
test('fatal decoder errors release the camera and inform the caller', async () => {
    const f = fixture(); await f.camera.start(); f.error(new Error('Canvas unavailable'));
    assert.equal(f.errors.length, 1); assert.ok(f.stops > 0); assert.equal(f.video.srcObject, null);
});
