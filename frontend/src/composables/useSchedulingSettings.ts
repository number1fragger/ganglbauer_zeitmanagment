import { ref } from 'vue'
import { settingsApi } from '@/api'

/**
 * Einstellung "Parallele Aufträge erlauben" – eine Quelle für alle Ansichten
 * (Kalender, Aufgaben). null = noch nicht geladen.
 *
 * Erlaubt (Standard im Backend): gleichzeitige Aufträge derselben Person sind
 * kein Konflikt. Ausgeschaltet: Überschneidungen werden als Konflikt gezeigt
 * (und vom Backend beim Einplanen aufgelöst).
 */
export const parallelScheduling = ref<boolean | null>(null)

/** Neu laden – jemand anderer kann die Einstellung inzwischen geändert haben. */
export async function loadSchedulingSettings(): Promise<void> {
  try {
    parallelScheduling.value = (await settingsApi.get()).parallelScheduling
  } catch {
    /* bleibt beim bisherigen Stand */
  }
}

/** Gelten Überschneidungen zweier Aufträge gerade als Konflikt? */
export function overlapsAreConflicts(): boolean {
  return parallelScheduling.value === false
}
