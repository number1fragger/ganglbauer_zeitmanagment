/// <reference types="vite/client" />

interface ImportMetaEnv {
  /** Backend-Adresse ohne /api am Ende, leer = gleiche Domain */
  readonly VITE_API_URL?: string
}

interface ImportMeta {
  readonly env: ImportMetaEnv
}
