export class ApiError extends Error {
  constructor(message, httpStatus, fields = {}) {
    super(message);
    this.httpStatus = httpStatus;
    this.fields = fields;
  }
}
export class ApiClient {
  constructor() {
    this.csrfToken = null;
  }
  async upload(file, type, path = 'files/upload') {
    const form = new FormData();
    form.append('attachment', file);
    form.append('recordTypeCode', type);
    const response = await fetch('/api/' + path, {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'X-Cement-CSRF': this.csrfToken },
      body: form,
    });
    const payload = await response.json();
    if (!response.ok) throw new ApiError(payload.message, response.status, payload.fields);
    return payload;
  }
  async request(path, body, query) {
    const url = new URL('/api/' + path, location.origin);
    if (query)
      for (const [key, value] of Object.entries(query))
        if (value !== undefined && value !== '') url.searchParams.set(key, value);
    const response = await fetch(url, {
      method: body === undefined ? 'GET' : 'POST',
      credentials: 'same-origin',
      headers: {
        Accept: 'application/json',
        ...(body === undefined
          ? {}
          : { 'Content-Type': 'application/json', 'X-Cement-CSRF': this.csrfToken || '' }),
      },
      body: body === undefined ? undefined : JSON.stringify(body),
    });
    let payload;
    try {
      payload = await response.json();
    } catch {
      throw new ApiError(
        'Server returned an unreadable response. The operation is unconfirmed.',
        response.status,
      );
    }
    if (!response.ok)
      throw new ApiError(payload.message || 'Request failed', response.status, payload.fields);
    if (payload.csrfToken) this.csrfToken = payload.csrfToken;
    return payload;
  }
}
