import { computed, ref, watch } from 'vue'
import { defineStore } from 'pinia'
import type { ThemePreference } from '@/api/types'

const STORAGE_KEY = 'werkstatt_theme'
const media =
  typeof window !== 'undefined' ? window.matchMedia('(prefers-color-scheme: dark)') : null

function readStored(): ThemePreference | null {
  try {
    const value = localStorage.getItem(STORAGE_KEY)
    return value === 'light' || value === 'dark' || value === 'system' ? value : null
  } catch {
    return null
  }
}

/**
 * Hell/Dunkel/System. Ohne gespeicherte Wahl gilt die Systemeinstellung.
 * Die Wahl liegt im Browser (sofort beim Laden, siehe index.html) und –
 * sobald angemeldet – im Benutzerkonto, damit sie auf allen Geraeten gilt.
 */
export const useThemeStore = defineStore('theme', () => {
  const preference = ref<ThemePreference>(readStored() ?? 'system')
  const systemDark = ref(media?.matches ?? false)

  media?.addEventListener('change', (event) => (systemDark.value = event.matches))

  const effective = computed<'light' | 'dark'>(() =>
    preference.value === 'system' ? (systemDark.value ? 'dark' : 'light') : preference.value,
  )

  /** Gesetzt von der Anmeldung: speichert die Wahl im Konto. */
  let persistRemote: ((theme: ThemePreference) => Promise<unknown>) | null = null

  function apply(theme: 'light' | 'dark', animate: boolean): void {
    const root = document.documentElement
    if (animate) {
      root.classList.add('theme-switching')
      window.setTimeout(() => root.classList.remove('theme-switching'), 250)
    }
    root.dataset.theme = theme
    document
      .querySelector('meta[name="theme-color"]')
      ?.setAttribute('content', theme === 'dark' ? '#12161c' : '#ffffff')
  }

  apply(effective.value, false)
  watch(effective, (theme) => apply(theme, true))

  function set(next: ThemePreference, remote = true): void {
    preference.value = next
    try {
      localStorage.setItem(STORAGE_KEY, next)
    } catch {
      /* privater Modus – dann eben nur fuer diese Sitzung */
    }
    if (remote) void persistRemote?.(next).catch(() => undefined)
  }

  /** Reihum: System -> Hell -> Dunkel */
  function cycle(): void {
    set(preference.value === 'system' ? 'light' : preference.value === 'light' ? 'dark' : 'system')
  }

  function connect(saveRemote: (theme: ThemePreference) => Promise<unknown>): void {
    persistRemote = saveRemote
  }

  return { preference, effective, set, cycle, connect }
})
