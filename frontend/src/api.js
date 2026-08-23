/**
 * Thin wrapper over fetch for the JSON API.
 *
 * Same-origin, so the session cookie rides along automatically; the only
 * real work here is turning error payloads into thrown Errors so callers
 * can use try/catch rather than checking status codes everywhere.
 */

class ApiError extends Error {
  constructor(message, status) {
    super(message)
    this.name = 'ApiError'
    this.status = status
  }
}

async function request(method, path, body) {
  const options = {
    method,
    credentials: 'same-origin',
    headers: { Accept: 'application/json' },
  }

  if (body !== undefined) {
    options.headers['Content-Type'] = 'application/json'
    options.body = JSON.stringify(body)
  }

  const response = await fetch(`/api${path}`, options)

  if (response.status === 204) {
    return null
  }

  const text = await response.text()
  let payload = null

  try {
    payload = text ? JSON.parse(text) : null
  } catch {
    // Non-JSON body (an export, or a server error page).
    if (!response.ok) {
      throw new ApiError(text || response.statusText, response.status)
    }
    return text
  }

  if (!response.ok) {
    throw new ApiError(payload?.error?.message ?? response.statusText, response.status)
  }

  return payload
}

export const api = {
  get: (path) => request('GET', path),
  post: (path, body) => request('POST', path, body ?? {}),
  patch: (path, body) => request('PATCH', path, body ?? {}),
  put: (path, body) => request('PUT', path, body ?? {}),
  delete: (path) => request('DELETE', path),
}

export { ApiError }
