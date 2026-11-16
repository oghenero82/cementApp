import { parse } from 'acorn';
import { seededFieldRegistry } from '../frontend/src/registry/seededFieldRegistry.js';
import { seededSharedFieldRegistry } from '../frontend/src/registry/seededSharedFieldRegistry.js';
const fields = new Set(
  Object.values({ ...seededFieldRegistry, ...seededSharedFieldRegistry }).flatMap(Object.values),
);
// These are Mutation/Sync envelope keys, not business record data fields.
const protocol = new Set(['requestId', 'mutationId', 'physicalTableId']);
const distinctive = new Set([...fields].filter((s) => /[A-Z]/.test(s)));
const businessRoots = new Set([
  'record',
  'row',
  'item',
  'sale',
  'purchase',
  'payload',
  'mutation',
  'data',
]);
export function scanFields(source, filename = 'source.js') {
  const errors = [];
  const ast = parse(source, { ecmaVersion: 'latest', sourceType: 'module', locations: true });
  function walk(node) {
    if (!node || typeof node !== 'object') return;
    if (node.type === 'MemberExpression') {
      const key =
        node.computed && node.property.type === 'Literal'
          ? node.property.value
          : !node.computed
            ? node.property.name
            : null;
      const root =
        node.object.type === 'Identifier'
          ? node.object.name
          : node.object.type === 'MemberExpression'
            ? node.object.property.name
            : null;
      if (
        fields.has(key) &&
        (!protocol.has(key) || root === 'data') &&
        (distinctive.has(key) || businessRoots.has(root))
      )
        errors.push(`${filename}:${node.loc.start.line}: raw business field access ${key}`);
    }
    if (
      node.type === 'Literal' &&
      typeof node.value === 'string' &&
      distinctive.has(node.value) &&
      !protocol.has(node.value)
    )
      errors.push(`${filename}:${node.loc.start.line}: raw field-code literal ${node.value}`);
    if (
      node.type === 'CallExpression' &&
      node.callee.type === 'MemberExpression' &&
      node.callee.property.name === 'setAttribute' &&
      node.arguments[0]?.type === 'Literal' &&
      ['name', 'data-field', 'data-name'].includes(node.arguments[0].value) &&
      node.arguments[1]?.type === 'Literal' &&
      fields.has(node.arguments[1].value)
    )
      errors.push(`${filename}:${node.loc.start.line}: literal business form name`);
    for (const [key, value] of Object.entries(node))
      if (key !== 'loc') {
        if (Array.isArray(value)) value.forEach(walk);
        else if (value && typeof value === 'object') walk(value);
      }
  }
  walk(ast);
  return [...new Set(errors)];
}
