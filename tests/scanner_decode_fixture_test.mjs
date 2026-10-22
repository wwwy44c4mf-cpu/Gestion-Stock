import assert from 'node:assert/strict';
import test from 'node:test';
import { readFile } from 'node:fs/promises';
import { BrowserMultiFormatReader } from '../public/assets/vendor/zxing.js';

// Synthetic pixel fixtures: no camera, network or photos from the installation.
const fixtures = JSON.parse(await readFile(new URL('Fixtures/scan_codes.json', import.meta.url), 'utf8'));
for (const fixture of fixtures) {
    test(`shipped decoder reads ${fixture.text}`, () => {
        const width = fixture.rows[0].length * fixture.scale;
        const height = fixture.rows.length * fixture.scale;
        const data = new Uint8ClampedArray(width * height * 4);
        for (let y = 0; y < height; y++) for (let x = 0; x < width; x++) {
            const index = (y * width + x) * 4;
            const color = fixture.rows[Math.floor(y / fixture.scale)][Math.floor(x / fixture.scale)] === '1' ? 0 : 255;
            data[index] = data[index + 1] = data[index + 2] = color;
            data[index + 3] = 255;
        }
        const canvas = { width, height, getContext: () => ({ getImageData: () => ({ data }) }) };
        assert.equal(new BrowserMultiFormatReader().decodeFromCanvas(canvas).getText(), fixture.text);
    });
}
