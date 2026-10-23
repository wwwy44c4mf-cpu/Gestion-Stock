/** Own every stream, including permission/decode promises resolving after close. */
export class ScanCamera {
    constructor({ video, mediaDevices, createReader, onResult, onError }) {
        Object.assign(this, { video, mediaDevices, createReader, onResult, onError });
        this.generation = 0;
    }
    async start() {
        this.stop();
        const generation = this.generation;
        let stream;
        try {
            const reader = await this.createReader();
            if (generation !== this.generation) return;
            stream = await this.mediaDevices.getUserMedia({
                audio: false,
                video: { facingMode: { ideal: 'environment' }, width: { ideal: 1280 }, height: { ideal: 720 } },
            });
            if (generation !== this.generation) { this.release(stream); return; }
            this.stream = stream;
            const controls = await reader.decodeFromStream(stream, this.video, (result, error) => {
                if (generation !== this.generation) return;
                if (!result) {
                    const kind = error?.getKind?.() || error?.name;
                    if (error && !['NotFoundException', 'ChecksumException', 'FormatException'].includes(kind)) {
                        this.stop(); this.onError(error);
                    }
                    return;
                }
                const text = result.getText();
                this.stop(); // Prevent repeated frames from generating repeated actions.
                this.onResult(text);
            });
            if (generation !== this.generation) { controls.stop(); this.release(stream); return; }
            this.controls = controls;
        } catch (error) {
            if (generation !== this.generation) { this.release(stream); return; }
            this.stop();
            this.onError(error);
        }
    }
    release(stream) { stream?.getTracks().forEach(track => track.stop()); }
    stop() {
        this.generation++;
        try { this.controls?.stop(); } catch { /* Tracks are still released below. */ }
        this.controls = null;
        this.release(this.stream);
        this.stream = null;
        this.video.pause?.();
        this.video.srcObject = null;
    }
}
