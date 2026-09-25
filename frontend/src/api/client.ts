import axios, { AxiosError } from 'axios'
import type { ApiError } from './types'

export const TOKEN_KEY = 'gz_token'

/**
 * "Angemeldet bleiben" speichert den Token dauerhaft (localStorage),
 * sonst nur bis der Browser geschlossen wird (sessionStorage).
 */
export function readToken(): string | null {
  return localStorage.getItem(TOKEN_KEY) ?? sessionStorage.getItem(TOKEN_KEY)
}

export const http = axios.create({
  baseURL: import.meta.env.VITE_API_BASE_URL || '',
  headers: { 'Content-Type': 'application/json' },
})

http.interceptors.request.use((config) => {
  const token = readToken()

  if (token) {
    config.headers.Authorization = `Bearer ${token}`
  }

  return config
})

/** Wird beim 401-Fall gesetzt, damit der Auth-Store ausloggen kann. */
let unauthorizedHandler: (() => void) | null = null

export function onUnauthorized(handler: () => void): void {
  unauthorizedHandler = handler
}

http.interceptors.response.use(
  (response) => response,
  (error: AxiosError<ApiError>) => {
    if (error.response?.status === 401) {
      unauthorizedHandler?.()
    }

    return Promise.reject(error)
  },
)

/** Macht aus einem beliebigen Fehler eine lesbare deutsche Meldung. */
export function errorMessage(error: unknown): string {
  if (axios.isAxiosError<ApiError>(error)) {
    const data = error.response?.data

    if (data?.errors?.length) {
      return data.errors.map((e) => e.message).join(' ')
    }

    if (data?.title) {
      return data.title
    }

    if (error.response?.status === 401) {
      return 'E-Mail oder Passwort stimmt nicht.'
    }

    if (!error.response) {
      return 'Das Backend ist nicht erreichbar. Laeuft "symfony serve" bzw. der PHP-Server?'
    }

    return `Fehler ${error.response.status}`
  }

  return 'Unbekannter Fehler'
}
