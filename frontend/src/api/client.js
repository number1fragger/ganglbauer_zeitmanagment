/**
 * Duenne Schicht ueber fetch: haengt das JWT an, wandelt JSON um und macht
 * aus Fehlern eine lesbare deutsche Meldung (ApiError.message).
 */
const BASE_URL = import.meta.env.VITE_API_BASE_URL || ''
export const TOKEN_KEY = 'gz_token'

export class ApiError extends Error {
  constructor(message, status) {
    super(message)
    this.status = status
  }
}

/** "Angemeldet bleiben" = localStorage, sonst nur bis der Browser zu ist. */
export function readToken() {
  return localStorage.getItem(TOKEN_KEY) ?? sessionStorage.getItem(TOKEN_KEY)
}

let unauthorizedHandler = null

/** Wird vom Auth-Store gesetzt: Bei 401 automatisch abmelden. */
export function onUnauthorized(handler) {
  unauthorizedHandler = handler
}

export async function request(path, { method = 'GET', body, query } = {}) {
  const params = new URLSearchParams()
  for (const [key, value] of Object.entries(query ?? {})) {
    if (value !== null && value !== undefined) params.set(key, value)
  }

  const headers = { Accept: 'application/json' }
  if (body !== undefined) headers['Content-Type'] = 'application/json'

  const token = readToken()
  if (token) headers.Authorization = `Bearer ${token}`

  let response
  try {
    response = await fetch(`${BASE_URL}${path}${params.size ? `?${params}` : ''}`, {
      method,
      headers,
      body: body === undefined ? undefined : JSON.stringify(body),
    })
  } catch {
    throw new ApiError('Das Backend ist nicht erreichbar. Läuft ./dev.sh backend?', 0)
  }

  if (response.status === 204) return null

  const data = await response.json().catch(() => null)

  if (!response.ok) {
    if (response.status === 401 && path !== '/api/login') unauthorizedHandler?.()
    throw new ApiError(messageFrom(data, response.status), response.status)
  }

  return data
}

function messageFrom(data, status) {
  if (data?.errors?.length) return data.errors.map((e) => e.message).join(' ')
  if (status === 401 && (!data?.message || data.message === 'Invalid credentials.')) {
    return 'E-Mail oder Passwort stimmt nicht.'
  }

  return data?.title ?? data?.message ?? `Fehler ${status}`
}

export const api = {
  get: (path, query) => request(path, { query }),
  post: (path, body = {}) => request(path, { method: 'POST', body }),
  patch: (path, body) => request(path, { method: 'PATCH', body }),
  put: (path, body) => request(path, { method: 'PUT', body }),
  delete: (path) => request(path, { method: 'DELETE' }),
}
