export const el = (tag, attrs = {}, ...children) => {
  const node = document.createElement(tag);
  for (const [key, value] of Object.entries(attrs)) {
    if (value === undefined || value === null || (value === false && !key.startsWith('aria-')))
      continue;
    if (key.startsWith('on') && typeof value === 'function')
      node.addEventListener(key.slice(2).toLowerCase(), value);
    else if (key === 'class') node.className = value;
    else if (key === 'text') node.textContent = value;
    else if (key in node && !key.startsWith('aria')) node[key] = value;
    else
      node.setAttribute(key, key.startsWith('aria-') ? String(value) : value === true ? '' : value);
  }
  for (const child of children.flat(Infinity))
    if (child !== null && child !== undefined && child !== false)
      node.append(child instanceof Node ? child : document.createTextNode(String(child)));
  return node;
};
export const labelOf = (alias) =>
  alias
    .toLowerCase()
    .split('_')
    .map((s) => s.charAt(0).toUpperCase() + s.slice(1))
    .join(' ');
let activeCurrency = 'NGN';
export const setCurrency = (currency) => {
  new Intl.NumberFormat('en', { style: 'currency', currency }).format(0);
  activeCurrency = currency;
};
export const money = (value) =>
  new Intl.NumberFormat('en-NG', {
    style: 'currency',
    currency: activeCurrency,
    maximumFractionDigits: 2,
  }).format(Number(value) || 0);
export const number = (value) =>
  new Intl.NumberFormat('en-NG', { maximumFractionDigits: 2 }).format(Number(value) || 0);
export const display = (value) =>
  value === undefined || value === null
    ? '—'
    : typeof value === 'object'
      ? JSON.stringify(value)
      : String(value);
export const button = (text, handler, kind = '') =>
  el('button', { class: `btn ${kind}`, type: 'button', onClick: handler }, text);
export function notify(message, error = false) {
  const box = el(
    'div',
    { class: `toast ${error ? 'error' : ''}`, role: error ? 'alert' : 'status' },
    message,
  );
  document.querySelector('#notifications').append(box);
  setTimeout(() => box.remove(), 7000);
}
export function icon(kind = 'grid') {
  const paths = {
    grid: 'M3 3h7v7H3z M14 3h7v7h-7z M3 14h7v7H3z M14 14h7v7h-7z',
    truck:
      'M3 5h11v12H3z M14 9h4l3 4v4h-7 M7 20a2 2 0 1 0 0-4 2 2 0 0 0 0 4 M18 20a2 2 0 1 0 0-4 2 2 0 0 0 0 4',
    chart: 'M3 3v18h18 M7 16v-4 M12 16V7 M17 16v-7',
    users:
      'M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2 M9 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8 M17 4a4 4 0 0 1 0 7 M22 21v-2a4 4 0 0 0-3-4',
    box: 'M3 7l9-4 9 4v10l-9 4-9-4z M3 7l9 4 9-4 M12 11v10',
    search: 'M21 21l-6-6 M10 17a7 7 0 1 0 0-14 7 7 0 0 0 0 14',
    settings:
      'M12 8a4 4 0 1 0 0 8 4 4 0 0 0 0-8 M12 2v3 M12 19v3 M2 12h3 M19 12h3 M5 5l2 2 M17 17l2 2 M5 19l2-2 M17 7l2-2',
    wallet: 'M3 5h17v15H3z M15 10h6v5h-6z',
    sync: 'M20 7a8 8 0 0 0-14-2L3 8 M3 3v5h5 M4 17a8 8 0 0 0 14 2l3-3 M21 21v-5h-5',
  };
  const svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
  svg.setAttribute('viewBox', '0 0 24 24');
  svg.setAttribute('class', 'ico');
  svg.setAttribute('aria-hidden', 'true');
  const path = document.createElementNS(svg.namespaceURI, 'path');
  path.setAttribute('d', paths[kind] || paths.grid);
  svg.append(path);
  return svg;
}
