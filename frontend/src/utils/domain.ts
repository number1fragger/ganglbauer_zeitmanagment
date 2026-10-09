import type { Job, JobStatus, Priority, Role, ScheduledJob, User } from '@/api/types'

/** Farben sind CSS-Variablen, damit sie im Dark Mode automatisch passen. */
export const priorities: { value: Priority; label: string; color: string }[] = [
  { value: 'hoch', label: 'Hoch', color: 'var(--prio-high)' },
  { value: 'mittel', label: 'Mittel', color: 'var(--prio-medium)' },
  { value: 'nieder', label: 'Nieder', color: 'var(--prio-low)' },
]

export const DONE_COLOR = 'var(--faint)'

export function priorityColor(priority: Priority): string {
  return priorities.find((p) => p.value === priority)?.color ?? DONE_COLOR
}

export function priorityLabel(priority: Priority): string {
  return priorities.find((p) => p.value === priority)?.label ?? priority
}

export function priorityWeight(priority: Priority): number {
  return { hoch: 3, mittel: 2, nieder: 1 }[priority]
}

/** Spalten der Aufgabenverwaltung – entsprechen den Status im Backend. */
export const statuses: { value: JobStatus; label: string; color: string }[] = [
  { value: 'offen', label: 'Offen', color: 'var(--status-open)' },
  { value: 'in_arbeit', label: 'In Bearbeitung', color: 'var(--status-progress)' },
  { value: 'erledigt', label: 'Abgeschlossen', color: 'var(--status-done)' },
]

/** Feiner als der Status: unterscheidet "laeuft" und "pausiert". */
export type WorkState = 'open' | 'running' | 'paused' | 'done'

export function workState(job: Job): WorkState {
  if (job.status === 'erledigt') return 'done'
  if (job.running) return 'running'
  if (job.status === 'in_arbeit') return 'paused'
  return 'open'
}

export const workStates: Record<WorkState, { label: string; color: string }> = {
  open: { label: 'Offen', color: 'var(--status-open)' },
  running: { label: 'Läuft', color: 'var(--status-progress)' },
  paused: { label: 'Pausiert', color: 'var(--status-paused)' },
  done: { label: 'Abgeschlossen', color: 'var(--status-done)' },
}

export const roles: { value: Role; label: string; color: string; description: string }[] = [
  {
    value: 'chef',
    label: 'Chef',
    color: 'var(--primary)',
    description:
      'Alle Arbeiten planen und zuteilen, Zeiten ändern, Auswertung einsehen, Benutzer verwalten.',
  },
  {
    value: 'vorarbeiter',
    label: 'Vorarbeiter',
    color: 'var(--prio-medium)',
    description:
      'Arbeiten planen und Zeiten ändern, Auswertung einsehen – keine Benutzerverwaltung.',
  },
  {
    value: 'arbeiter',
    label: 'Arbeiter',
    color: 'var(--success)',
    description: 'Eigene Arbeiten sehen, starten, pausieren, abschließen und Arbeit anfordern.',
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
const WORKER_COLORS = 6

export function workerColor(index: number): string {
  if (index < 0) return 'var(--worker-none)'
  return `var(--worker-${(index % WORKER_COLORS) + 1})`
}

export function isDone(job: Job): boolean {
  return job.status === 'erledigt'
}

export function isScheduled(job: Job): job is ScheduledJob {
  return job.startsAt !== null && job.endsAt !== null
}

/** Darf diese Arbeit per Drag & Drop verschoben werden? Begonnene/abgeschlossene nicht. */
export function isMovable(job: Job): boolean {
  return !isDone(job) && !job.started
}

/** Darf der Benutzer die Arbeit starten bzw. fortsetzen? Nur der Zuständige. */
export function canStart(job: Job, user: User | null): boolean {
  if (!user || isDone(job) || job.running) return false
  if (job.assignee) return job.assignee.id === user.id
  return user.role !== 'chef'
}

/** Pausieren/Abschließen: der Zuständige oder die Planung. */
export function canWorkOn(job: Job, user: User | null): boolean {
  if (!user) return false
  return canPlan(user.role) || job.assignee?.id === user.id
}

/** Überfällig: Termin-Ende vorbei und nicht abgeschlossen. */
export function isOverdue(job: Job, now = new Date()): boolean {
  return !isDone(job) && job.endsAt !== null && new Date(job.endsAt) < now
}

/**
 * Terminkonflikte: Arbeiten desselben Arbeiters, deren Termine sich
 * überschneiden. Liefert je Arbeit die Titel der kollidierenden Arbeiten.
 */
export function findConflicts(jobs: Job[]): Map<number, string[]> {
  const result = new Map<number, string[]>()
  const relevant = jobs.filter(
    (job): job is ScheduledJob => isScheduled(job) && !isDone(job) && job.assignee !== null,
  )

  relevant.forEach((a, i) => {
    for (const b of relevant.slice(i + 1)) {
      if (a.assignee?.id !== b.assignee?.id) continue
      if (new Date(a.startsAt) < new Date(b.endsAt) && new Date(b.startsAt) < new Date(a.endsAt)) {
        result.set(a.id, [...(result.get(a.id) ?? []), b.title])
        result.set(b.id, [...(result.get(b.id) ?? []), a.title])
      }
    }
  })

  return result
}
