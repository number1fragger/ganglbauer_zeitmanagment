import { shallowRef } from 'vue'
import { configApi } from '@/api'
import {
  durationMinutes,
  endFor,
  timeWarnings,
  type WorkingHoursConfig,
} from '@/utils/workTime'

/**
 * Regulaere Arbeitszeiten vom Server (GET /api/working-hours) – im Frontend
 * steht keine eigene Kopie. Geladen wird einmal nach der Anmeldung
 * (auth.loadUser), bevor eine Ansicht angezeigt wird.
 */
export const workingHours = shallowRef<WorkingHoursConfig | null>(null)

export async function loadWorkingHours(): Promise<void> {
  if (!workingHours.value) workingHours.value = await configApi.workingHours()
}

function config(): WorkingHoursConfig {
  if (!workingHours.value) throw new Error('Die Arbeitszeiten sind noch nicht geladen.')
  return workingHours.value
}

/** Dauer eines Termins in Arbeitsminuten (Mittagspause, Abende, Wochenenden zaehlen nicht). */
export function workDuration(startsAt: Date, endsAt: Date): number {
  return durationMinutes(config(), startsAt, endsAt)
}

/** Ende aus Beginn und Dauer in Arbeitsminuten. */
export function workEnd(startsAt: Date, minutes: number): Date {
  return endFor(config(), startsAt, minutes)
}

/** Hinweise zu Beginn/Ende ausserhalb der Arbeitszeit. */
export function workTimeWarnings(startsAt: Date, endsAt: Date): string[] {
  return workingHours.value ? timeWarnings(workingHours.value, startsAt, endsAt) : []
}
