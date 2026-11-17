export function datetimeInput(value) {
  const text = String(value);
  if (!/(?:Z|[+-]\d{2}:\d{2})$/.test(text)) return text.replace(' ', 'T').slice(0, 16);
  const date = new Date(text);
  if (!Number.isFinite(date.getTime())) throw new Error('Invalid datetime');
  const pad = (n) => String(n).padStart(2, '0');
  return (
    date.getFullYear() +
    '-' +
    pad(date.getMonth() + 1) +
    '-' +
    pad(date.getDate()) +
    'T' +
    pad(date.getHours()) +
    ':' +
    pad(date.getMinutes())
  );
}
export function datetimeInstant(value) {
  const date = new Date(value);
  if (!Number.isFinite(date.getTime())) throw new Error('Choose a valid datetime');
  return date.toISOString();
}
