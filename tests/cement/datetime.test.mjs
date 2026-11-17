import { test } from 'node:test';
import assert from 'node:assert/strict';
import { datetimeInput, datetimeInstant } from '../../frontend/src/core/datetime.js';
test('form datetimes preserve local wall time and round-trip UTC instants', () => {
  const original = process.env.TZ;
  try {
    process.env.TZ = 'Africa/Lagos';
    assert.equal(datetimeInput('2026-11-17T09:30:00Z'), '2026-11-17T10:30');
    assert.equal(datetimeInstant('2026-11-17T10:30'), '2026-11-17T09:30:00.000Z');
    assert.equal(datetimeInput('2026-11-17T10:30'), '2026-11-17T10:30');
  } finally {
    if (original === undefined) delete process.env.TZ;
    else process.env.TZ = original;
  }
});
