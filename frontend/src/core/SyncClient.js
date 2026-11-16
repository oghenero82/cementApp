export class SyncClient {
  constructor(api, cache, registry) {
    Object.assign(this, { api, cache, registry });
    this.lastRun = 0;
    this.busy = null;
  }
  async run() {
    if (this.busy) return this.busy;
    this.busy = this.perform();
    try {
      return await this.busy;
    } finally {
      this.busy = null;
    }
  }
  async perform() {
    let position = (await this.cache.getMeta('cursor')) || { cursor: 0, afterId: 0 };
    let page;
    do {
      page = await this.api.request('sync', undefined, position);
      if (page.scope !== this.cache.scope) {
        const error = new Error('Your access scope changed; reconnecting is required.');
        error.scopeChanged = true;
        throw error;
      }
      if (page.registryRevision !== this.registry.revision)
        throw new Error('Metadata changed. Reload before syncing records.');
      await this.cache.apply(page);
      position = { cursor: page.cursor, afterId: page.afterId };
    } while (page.hasMore);
    this.lastRun = Date.now();
    return this.cache.all();
  }
  start(onUpdate, onError) {
    this.stop();
    this.timer = setInterval(() => this.run().then(onUpdate).catch(onError), 60000);
  }
  stop() {
    clearInterval(this.timer);
  }
}
