/** Central browser-side RecordType field-code adapter.
 * Seeded from PHP seeder defaults, refreshable from authenticated Cement CI4 metadata.
 * No view should import seededFieldRegistry directly — import this singleton.
 */
import { seededFieldRegistry } from './seededFieldRegistry.js';
import { seededSharedFieldRegistry } from './seededSharedFieldRegistry.js';
const initialFieldRegistry = { ...seededFieldRegistry, ...seededSharedFieldRegistry };

const isPlainObject = (v) => !!v && typeof v === 'object' && !Array.isArray(v);
const clone = (v) => JSON.parse(JSON.stringify(v));

function validateFields(type, fields) {
  if (!isPlainObject(fields) || !Object.keys(fields).length)
    throw new Error('Invalid registry fields for ' + type);
  const seen = new Set();
  for (const [symbol, code] of Object.entries(fields)) {
    if (!/^[A-Z][A-Z0-9_]*$/.test(symbol))
      throw new Error('Invalid registry semantic symbol: ' + symbol);
    if (typeof code !== 'string' || !code.trim())
      throw new Error('Blank field code for ' + type + '.' + symbol);
    if (seen.has(code)) throw new Error('Duplicate field code for ' + type + ': ' + code);
    seen.add(code);
  }
}
function freezeSnapshot(obj) {
  const copy = clone(obj);
  for (const [type, fields] of Object.entries(copy)) {
    validateFields(type, fields);
    Object.freeze(fields);
  }
  return Object.freeze(copy);
}

export class RecordFieldRegistry {
  constructor(seed = initialFieldRegistry) {
    this._snapshot = freezeSnapshot(seed);
    this._revision = 'cement-seed-2026-11-16';
    this._ids = Object.freeze({});
  }

  get revision() {
    return this._revision;
  }
  get recordTypeIds() {
    return this._ids;
  }

  /** Returns semantic-name to current backend-code mapping for one RecordType. */
  fields(typeCode) {
    const result = this._snapshot[typeCode];
    if (!result) throw new Error('No field registry for RecordType: ' + typeCode);
    return result;
  }

  /** Only field-code lookup API permitted to forms, records, filters or mutation requests. */
  field(typeCode, symbol) {
    const code = this.fields(typeCode)[symbol];
    if (!code) throw new Error('Unknown field semantic alias: ' + typeCode + '.' + symbol);
    return code;
  }

  get(typeCode, record, symbol) {
    return record?.[this.field(typeCode, symbol)];
  }

  /** Semantic keys IN, exact authoritative field codes OUT. */
  encode(typeCode, semanticData) {
    if (!isPlainObject(semanticData)) throw new TypeError('Mutation data must be a plain object');
    const out = {};
    for (const [semantic, value] of Object.entries(semanticData)) {
      out[this.field(typeCode, semantic)] = value;
    }
    return out;
  }

  /**
   * Explicit schema activation from authenticated Cement CI4:
   * {revision:string, fieldsByRecordType:{typeCode:{SEMANTIC_ALIAS:'actualCode'}},
   *  recordTypeIds:{typeCode:number}}.
   *
   * CI4 must already validate and authorize the MetaForge definitions.
   * This method validates ALL incoming changes and commits atomically.
   * Old drafts keep their saved registryRevision and are NOT silently remapped.
   */
  activateMetadata(metadata) {
    if (
      !isPlainObject(metadata) ||
      typeof metadata.revision !== 'string' ||
      !metadata.revision.trim()
    ) {
      throw new Error('Metadata revision required');
    }
    if (!isPlainObject(metadata.fieldsByRecordType)) throw new Error('fieldsByRecordType required');
    const next = metadata.authoritative ? {} : clone(this._snapshot);
    if (metadata.authoritative) {
      for (const type of Object.keys(metadata.fieldsByRecordType)) {
        if (!initialFieldRegistry[type]) throw new Error('Unknown authorized RecordType: ' + type);
        next[type] = clone(initialFieldRegistry[type]);
        for (const alias of Object.keys(next[type])) {
          if (!(alias in metadata.fieldsByRecordType[type]))
            throw new Error('Missing required alias: ' + type + '.' + alias);
        }
      }
    }
    for (const [type, mapping] of Object.entries(metadata.fieldsByRecordType)) {
      if (!next[type]) throw new Error('Unknown RecordType in server metadata: ' + type);
      if (!isPlainObject(mapping)) throw new Error('Invalid metadata mapping for ' + type);
      // Partial updates are allowed only when they retain existing semantic identities.
      for (const [symbol, code] of Object.entries(mapping)) {
        if (!(symbol in next[type]))
          throw new Error('Unexpected semantic alias in ' + type + ': ' + symbol);
        next[type][symbol] = code;
      }
      validateFields(type, next[type]);
    }
    const nextIds = {};
    const usedIds = new Set();
    for (const [type, id] of Object.entries(metadata.recordTypeIds || {})) {
      if (!(type in next)) throw new Error('Unknown RecordType ID mapping for: ' + type);
      if (!Number.isInteger(id) || id <= 0) throw new Error('Invalid RecordType ID for ' + type);
      if (usedIds.has(id)) throw new Error('Duplicate RecordType ID');
      usedIds.add(id);
      nextIds[type] = id;
    }
    const frozen = freezeSnapshot(next);
    // All validation completed; replace state atomically.
    this._snapshot = frozen;
    this._ids = Object.freeze(nextIds);
    this._revision = metadata.revision;
    return this._revision;
  }

  mutationEnvelope(typeCode, operation, semanticData, requestId) {
    if (!requestId || typeof requestId !== 'string')
      throw new Error('Idempotent requestId required');
    const recordTypeId = this._ids[typeCode];
    // Local CI4 can resolve by code; MetaForge CI4 proxy must resolve authorized numeric ID.
    return {
      requestId,
      recordTypeCode: typeCode,
      ...(recordTypeId ? { recordTypeId } : {}),
      operation,
      data: this.encode(typeCode, semanticData),
      registryRevision: this._revision,
    };
  }

  validateQueuedMutationRevision(mutation) {
    if (mutation.registryRevision !== this._revision) {
      throw new Error(
        'Pending mutation uses an older registry revision; migrate/review it before replay',
      );
    }
  }
}

export const recordFieldRegistry = new RecordFieldRegistry();
