/**
 * Duenne Schicht ueber fetch: haengt das Login-Token an, liest JSON und
 * macht aus Fehlerantworten der API eine lesbare Meldung.
 */

export class ApiError extends Error {
  constructor(
    message: string,
    readonly status: number,
  ) {
    super(message)
  }
}

interface ErrorBody {
  title?: string
  message?: string
  errors?: { field: string; message: string }[]
}

let tokenProvider: () => string | null = () => null
let onUnauthorized: () => void = () => {}

export function configureHttp(options: { token: () => string | null; unauthorized: () => void }): void {
  tokenProvider = options.token
  onUnauthorized = options.unauthorized
}

async function request<T>(method: string, path: string, body?: unknown): Promise<T> {
  const headers: Record<string, string> = { Accept: 'application/json' }
  const token = tokenProvider()

  if (token) headers.Authorization = `Bearer ${token}`
  if (body !== undefined) headers['Content-Type'] = 'application/json'

  let response: Response
  try {
    response = await fetch(path, {
      method,
      headers,
      body: body === undefined ? undefined : JSON.stringify(body),
    })
  } catch {
    throw new ApiError('Der Server ist nicht erreichbar. Bitte die Verbindung prüfen.', 0)
  }

  if (response.status === 204) return undefined as T

  const data: unknown = await response.json().catch(() => null)

  if (!response.ok) {
    if (response.status === 401 && path !== '/api/login') onUnauthorized()
    throw new ApiError(messageOf(data as ErrorBody | null, response.status), response.status)
  }

  return data as T
}

function messageOf(body: ErrorBody | null, status: number): string {
  if (body?.errors?.length) return body.errors.map((error) => error.message).join(' ')
  if (body?.title) return body.title
  if (status === 401) {
    // Lexik meldet falsche Zugangsdaten englisch, eigene Meldungen (z. B. deaktiviert) deutsch.
    return body?.message && body.message !== 'Invalid credentials.'
      ? body.message
      : 'Benutzername oder Passwort stimmt nicht.'
  }

  return `Unerwarteter Fehler (${status}).`
}

export const http = {
  get: <T>(path: string) => request<T>('GET', path),
  post: <T>(path: string, body?: unknown) => request<T>('POST', path, body),
  put: <T>(path: string, body?: unknown) => request<T>('PUT', path, body),
  delete: (path: string) => request<void>('DELETE', path),
}

export function errorMessage(error: unknown): string {
  return error instanceof Error ? error.message : 'Unbekannter Fehler.'
}
