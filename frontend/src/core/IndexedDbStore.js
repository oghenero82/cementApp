export class IndexedDbStore {
  async open(scope, identity = scope) {
    this.close();
    this.scope = scope;
    this.db = await new Promise((resolve, reject) => {
      const req = indexedDB.open('cement-authorized-' + identity, 1);
      req.onupgradeneeded = () => {
        const db = req.result;
        db.createObjectStore('record', { keyPath: ['recordTypeId', 'recordId'] });
        db.createObjectStore('outbox', { keyPath: 'requestId' });
        db.createObjectStore('meta');
      };
      req.onsuccess = () => resolve(req.result);
      req.onerror = () => reject(req.error);
    });
    if ((await this.getMeta('accessScope')) !== scope) await this.resetRecords(null);
    return this;
  }
  close() {
    this.db?.close();
    this.db = null;
  }
  async transaction(stores, mode, action) {
    return new Promise((resolve, reject) => {
      const tx = this.db.transaction(stores, mode);
      let result;
      try {
        result = action(tx);
      } catch (e) {
        tx.abort();
        reject(e);
        return;
      }
      tx.oncomplete = () => resolve(result);
      tx.onerror = () => reject(tx.error);
      tx.onabort = () => reject(tx.error || new Error('Cache transaction aborted'));
    });
  }
  async all(store = 'record') {
    return new Promise((resolve, reject) => {
      const req = this.db.transaction(store).objectStore(store).getAll();
      req.onsuccess = () => resolve(req.result);
      req.onerror = () => reject(req.error);
    });
  }
  async getMeta(key) {
    return new Promise((resolve, reject) => {
      const req = this.db.transaction('meta').objectStore('meta').get(key);
      req.onsuccess = () => resolve(req.result);
      req.onerror = () => reject(req.error);
    });
  }
  async putDraft(draft) {
    await this.transaction(['outbox'], 'readwrite', (tx) => tx.objectStore('outbox').put(draft));
  }
  async removeDraft(requestId) {
    await this.transaction(['outbox'], 'readwrite', (tx) =>
      tx.objectStore('outbox').delete(requestId),
    );
  }
  applyRows(tx, records) {
    const recordsStore = tx.objectStore('record'),
      meta = tx.objectStore('meta');
    for (const row of records) {
      const key = 'version:' + row.recordTypeId + ':' + row.recordId;
      const request = meta.get(key);
      request.onsuccess = () => {
        const prior = request.result;
        if (
          prior &&
          (prior.mutationId > row.mutationId ||
            (prior.mutationId === row.mutationId && prior.version > row.version))
        )
          return;
        if (row.deleted) recordsStore.delete([row.recordTypeId, row.recordId]);
        else recordsStore.put(row);
        meta.put({ mutationId: row.mutationId, version: row.version }, key);
      };
    }
  }
  async resetRecords(revision) {
    await this.transaction(['record', 'meta'], 'readwrite', (tx) => {
      tx.objectStore('record').clear();
      tx.objectStore('meta').clear();
      tx.objectStore('meta').put(revision, 'registryRevision');
      tx.objectStore('meta').put(this.scope, 'accessScope');
    });
  }
  async applyChanges(records) {
    await this.transaction(['record', 'meta'], 'readwrite', (tx) => this.applyRows(tx, records));
  }
  async apply(page) {
    await this.transaction(['record', 'meta'], 'readwrite', (tx) => {
      this.applyRows(tx, page.records);
      tx.objectStore('meta').put({ cursor: page.cursor, afterId: page.afterId }, 'cursor');
    });
  }
}
