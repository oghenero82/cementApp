/** RFC 4180-style CSV reader, including embedded quotes and multiline cells. */
export function parseCSV(text) {
  const rows = [];
  let row = [],
    cell = '',
    quoted = false;
  for (let i = 0; i < text.length; i++) {
    const ch = text[i];
    if (ch === '"') {
      if (quoted && text[i + 1] === '"') {
        cell += '"';
        i++;
      } else if (!quoted && cell.length) throw new Error('Unexpected quote in CSV');
      else quoted = !quoted;
    } else if (ch === ',' && !quoted) {
      row.push(cell);
      cell = '';
    } else if ((ch === '\n' || ch === '\r') && !quoted) {
      if (ch === '\r' && text[i + 1] === '\n') i++;
      row.push(cell);
      if (row.some((v) => v !== '')) rows.push(row);
      row = [];
      cell = '';
    } else cell += ch;
  }
  if (quoted) throw new Error('Unclosed quoted CSV cell');
  if (cell || row.length) {
    row.push(cell);
    rows.push(row);
  }
  return rows;
}
export function convertCSV(rows, descriptor, registry, type) {
  if (rows.length < 2) throw new Error('Include a header and at least one data row');
  const [headers, ...data] = rows;
  if (new Set(headers).size !== headers.length)
    throw new Error('CSV headers must be unique semantic aliases');
  for (const h of headers)
    if (!descriptor.fields.some((f) => f.alias === h) || descriptor.readOnlyAliases.includes(h))
      throw new Error('Unknown or service-owned header: ' + h);
  return data.map((row, index) => {
    if (row.length !== headers.length)
      throw new Error('Column count differs on row ' + (index + 2));
    const out = {};
    headers.forEach((alias, i) => {
      const field = descriptor.fields.find((f) => f.alias === alias);
      let value = row[i];
      if (value === '') return;
      if (['integer', 'reference', 'number'].includes(field.type)) {
        value = Number(value);
        if (!Number.isFinite(value)) throw new Error('Invalid number on row ' + (index + 2));
      } else if (field.type === 'boolean') {
        if (!['true', 'false'].includes(value)) throw new Error('Boolean must be true or false');
        value = value === 'true';
      } else if (['array', 'object'].includes(field.type)) value = JSON.parse(value);
      out[registry.field(type, alias)] = value;
    });
    return out;
  });
}
