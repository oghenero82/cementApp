export class MutationClient {
  constructor(api, cache, registry, sync) {
    Object.assign(this, { api, cache, registry, sync });
  }
  async save(type, operation, semanticData, row) {
    const envelope = this.registry.mutationEnvelope(
      type,
      operation,
      semanticData,
      crypto.randomUUID(),
    );
    if (row) {
      envelope.recordId = row.recordId;
      envelope.version = row.version;
    }
    return this.send(envelope);
  }
  async send(envelope) {
    this.registry.validateQueuedMutationRevision(envelope);
    const draft = { ...envelope, createdAt: new Date().toISOString(), queueState: 'pending' };
    await this.cache.putDraft(draft);
    try {
      const result = await this.api.request('mutation', envelope);
      await this.cache.applyChanges(result.changes || []);
      await this.cache.removeDraft(envelope.requestId);
      if (Date.now() - this.sync.lastRun >= 60000) await this.sync.run();
      return result;
    } catch (error) {
      draft.queueState = error.httpStatus && error.httpStatus < 500 ? 'failed' : 'pending';
      draft.queueError = error.message;
      await this.cache.putDraft(draft);
      error.pendingRequestId = envelope.requestId;
      error.ambiguous = draft.queueState === 'pending';
      throw error;
    }
  }
  async retry(draft) {
    this.registry.validateQueuedMutationRevision(draft);
    const { createdAt, queueState, queueError, ...envelope } = draft;
    return this.send(envelope);
  }
}
