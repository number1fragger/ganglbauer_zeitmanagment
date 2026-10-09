import type { Job, Priority, Role } from '@/api/types'

export const priorities: { value: Priority; label: string; color: string; soft: string }[] = [
  { value: 'hoch', label: 'Hoch', color: '#dc2626', soft: '#fef2f2' },
  { value: 'mittel', label: 'Mittel', color: '#f59e0b', soft: '#fff8e8' },
  { value: 'nieder', label: 'Nieder', color: '#16a34a', soft: '#e7f6ec' },
]

export const DONE_COLOR = '#9ca3af'

export function priorityColor(priority: Priority): string {
  return priorities.find((p) => p.value === priority)?.color ?? DONE_COLOR
}

export const roles: { value: Role; label: string; color: string; description: string }[] = [
  {
    value: 'chef',
    label: 'Chef',
    color: '#2563eb',
    description: 'Alle Arbeiten planen und zuteilen, Zeiten ändern, Auswertung einsehen, Benutzer verwalten.',
  },
  {
    value: 'vorarbeiter',
    label: 'Vorarbeiter',
    color: '#f59e0b',
    description: 'Arbeiten planen und Zeiten ändern, Auswertung einsehen – keine Benutzerverwaltung.',
  },
  {
    value: 'arbeiter',
    label: 'Arbeiter',
    color: '#16a34a',
    description: 'Nur eigene Arbeiten sehen, abhaken und Arbeit anfordern.',
  },
]

export function roleOf(role: Role) {
  return roles.find((r) => r.value === role) ?? roles[2]!
}

/** Was jede Rolle darf – gespiegelt aus der Rechteprüfung im Backend. */
export const permissions: { key: string; label: string; roles: Role[] }[] = [
  { key: 'plan', label: 'Planung', roles: ['chef', 'vorarbeiter'] },
  { key: 'own', label: 'Eigene Arbeiten', roles: ['chef', 'vorarbeiter', 'arbeiter'] },
  { key: 'time', label: 'Zeit ändern', roles: ['chef', 'vorarbeiter', 'arbeiter'] },
  { key: 'report', label: 'Auswertung', roles: ['chef', 'vorarbeiter'] },
  { key: 'users', label: 'Benutzer', roles: ['chef'] },
]

export function canPlan(role: Role | undefined): boolean {
  return role === 'chef' || role === 'vorarbeiter'
}

/** Farbe je Arbeiter in Wochen- und Monatsansicht. */
const workerPalette = [
  { color: '#2563eb', soft: '#eaf0fe' },
  { color: '#7c3aed', soft: '#f1ebfe' },
  { color: '#0d9488', soft: '#e6f6f4' },
  { color: '#db2777', soft: '#fdebf3' },
  { color: '#ea580c', soft: '#fdeee4' },
  { color: '#4f46e5', soft: '#ecebfd' },
]

export function workerColor(index: number) {
  return workerPalette[((index % workerPalette.length) + workerPalette.length) % workerPalette.length]!
}

export function isDone(job: Job): boolean {
  return job.status === 'erledigt'
}
