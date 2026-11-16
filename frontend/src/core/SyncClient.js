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
  validateRows(rows) {
    if (!Array.isArray(rows)) throw new Error('Sync records must be an array');
    for (const row of rows) {
      if (
        !row ||
        !Number.isSafeInteger(row.recordId) ||
        row.recordId <= 0 ||
        !Number.isSafeInteger(row.version) ||
        row.version <= 0 ||
        !Number.isSafeInteger(row.mutationId) ||
        row.mutationId < 0
      )
        throw new Error('Invalid sync record identity or version');
      if (this.registry.recordTypeIds[row.recordTypeCode] !== row.recordTypeId)
        throw new Error('Sync RecordType ID does not match authorized metadata');
      const allowed = new Set(Object.values(this.registry.fields(row.recordTypeCode)));
      if (
        !row.data ||
        typeof row.data !== 'object' ||
        Array.isArray(row.data) ||
        Object.keys(row.data).some((key) => !allowed.has(key))
      )
        throw new Error('Sync data does not match the activated field registry');
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
      this.validateRows(page.records);
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
