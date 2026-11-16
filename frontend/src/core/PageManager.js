export class PageManager {
  constructor(context, root) {
    Object.assign(this, { context, root });
    this.listener = () => this.render();
    window.addEventListener('hashchange', this.listener);
  }
  async render() {
    this.current?.destroy?.();
    this.current = null;
    await this.context.route(decodeURIComponent(location.hash.slice(1) || 'dashboard'), this.root);
  }
  destroy() {
    window.removeEventListener('hashchange', this.listener);
    this.current?.destroy?.();
  }
}
