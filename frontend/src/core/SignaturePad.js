import { el, button } from './dom.js';
export class SignaturePad {
  constructor(api, type, initial) {
    Object.assign(this, { api, type });
    this.reference = initial;
    this.dirty = false;
    this.drawn = false;
  }
  render() {
    const canvas = el('canvas', {
      width: 640,
      height: 180,
      class: 'signature-pad',
      tabIndex: 0,
      'aria-label': 'Draw receiver signature',
    });
    this.canvas = canvas;
    const ctx = canvas.getContext('2d');
    ctx.lineWidth = 2.5;
    ctx.lineCap = 'round';
    ctx.strokeStyle = '#182b48';
    let active = false;
    const point = (e) => {
      const box = canvas.getBoundingClientRect();
      return [
        ((e.clientX - box.left) * canvas.width) / box.width,
        ((e.clientY - box.top) * canvas.height) / box.height,
      ];
    };
    canvas.addEventListener('pointerdown', (e) => {
      e.preventDefault();
      canvas.setPointerCapture(e.pointerId);
      active = true;
      this.dirty = true;
      this.drawn = true;
      this.reference = null;
      ctx.beginPath();
      ctx.moveTo(...point(e));
    });
    canvas.addEventListener('pointermove', (e) => {
      if (!active) return;
      e.preventDefault();
      ctx.lineTo(...point(e));
      ctx.stroke();
    });
    canvas.addEventListener('pointerup', () => (active = false));
    canvas.addEventListener('pointercancel', () => (active = false));
    this.state = el(
      'small',
      { class: 'field-help', role: 'status' },
      this.reference
        ? 'Saved signature attached'
        : 'Draw with a mouse, pen or finger, then use the signature.',
    );
    const save = button(
      'Use signature',
      async () => {
        if (!this.drawn) {
          this.state.textContent = 'Draw the signature first.';
          return;
        }
        save.disabled = true;
        try {
          const blob = await new Promise((resolve) => canvas.toBlob(resolve, 'image/png'));
          if (!blob) throw new Error('Signature image could not be created');
          const result = await this.api.upload(
            new File([blob], 'signature.png', { type: 'image/png' }),
            this.type,
          );
          this.reference = result.reference;
          this.dirty = false;
          this.state.textContent = 'Signature uploaded and ready to attach.';
        } catch (error) {
          this.state.textContent = error.message;
        } finally {
          save.disabled = false;
        }
      },
      'secondary',
    );
    return el(
      'div',
      { class: 'signature-editor' },
      canvas,
      el(
        'div',
        { class: 'actions' },
        button('Clear signature', () => {
          ctx.clearRect(0, 0, 640, 180);
          this.reference = null;
          this.dirty = false;
          this.drawn = false;
          this.cleared = true;
          this.state.textContent = 'Signature cleared.';
        }),
        save,
      ),
      this.state,
    );
  }
  value() {
    if (this.dirty) throw new Error('Use the drawn signature before saving the delivery proof');
    return this.reference;
  }
}
