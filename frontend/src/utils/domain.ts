import type { Absence, Job, JobStatus, Priority, Role, ScheduledJob, User, UserRef } from '@/api/types'

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
      'Alle Arbeiten planen und zuteilen, Zeiten ändern, Abwesenheiten eintragen, Auswertung einsehen, Benutzer verwalten und löschen.',
  },
  {
    value: 'sekretaerin',
    label: 'Sekretärin',
    color: 'var(--prio-medium)',
    description:
      'Arbeiten planen und Zeiten ändern, Urlaub und persönliche Termine eintragen, Auswertung einsehen – keine Benutzerverwaltung.',
  },
  {
    value: 'arbeiter',
    label: 'Arbeiter',
    color: 'var(--success)',
    description: 'Eigene Arbeiten sehen, starten, pausieren, abschließen und Arbeit anfordern.',
  },
  {
    value: 'lehrling',
    label: 'Lehrling',
    color: 'var(--worker-3)',
    description:
      'Wie ein Arbeiter für eigene Aufträge. Kann Aufträgen zusätzlich als Lehrling zugeordnet werden – sieht diese im Kalender, die Zeiterfassung bleibt beim Zuständigen.',
  },
]

export function roleOf(role: Role | undefined) {
  return roles.find((r) => r.value === role) ?? roles[2]!
}

/** Was jede Rolle darf – gespiegelt aus der Rechteprüfung im Backend. */
export const permissions: { key: string; label: string; roles: Role[] }[] = [
  { key: 'plan', label: 'Planung', roles: ['chef', 'sekretaerin'] },
  { key: 'own', label: 'Eigene Arbeiten', roles: ['chef', 'sekretaerin', 'arbeiter', 'lehrling'] },
  { key: 'time', label: 'Zeit ändern', roles: ['chef', 'sekretaerin', 'arbeiter', 'lehrling'] },
  { key: 'assist', label: 'Als Lehrling einteilbar', roles: ['lehrling'] },
  { key: 'absence', label: 'Abwesenheiten', roles: ['chef', 'sekretaerin'] },
  { key: 'report', label: 'Auswertung', roles: ['chef', 'sekretaerin'] },
  { key: 'users', label: 'Benutzer', roles: ['chef'] },
]

export function canPlan(role: Role | undefined): boolean {
  return role === 'chef' || role === 'sekretaerin'
}

/** Nur Lehrlinge koennen einem Auftrag zusaetzlich zugeordnet werden (geprueft auch im Backend). */
export function isApprentice(user: Pick<UserRef, 'role'> | null | undefined): boolean {
  return user?.role === 'lehrling'
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

/** Zustaendige Person und Lehrling eines Auftrags. */
export function peopleOf(job: Job): UserRef[] {
  return [job.assignee, job.apprentice].filter((p): p is UserRef => p !== null)
}

/** Ein Konflikt: eine Person ist zur selben Zeit doppelt eingeplant bzw. abwesend. */
export type ConflictInfo =
  | { kind: 'overlap'; person: UserRef; a: ScheduledJob; b: ScheduledJob; from: Date }
  | { kind: 'absence'; person: UserRef; job: ScheduledJob; absence: Absence; from: Date }

/**
 * Alle Konflikte im geladenen Zeitraum – wie die Hinweise im Backend
 * (ScheduleChecker): zustaendige Person oder Lehrling ist zur selben Zeit
 * fuer einen anderen Auftrag eingeplant oder abwesend. Nichts wird
 * verhindert, alles wird angezeigt.
 */
export function listConflicts(jobs: Job[], absences: Absence[] = []): ConflictInfo[] {
  const relevant = jobs.filter(
    (job): job is ScheduledJob => isScheduled(job) && !isDone(job) && peopleOf(job).length > 0,
  )
  const overlaps = (s1: string, e1: string, s2: string, e2: string) =>
    new Date(s1) < new Date(e2) && new Date(s2) < new Date(e1)
  const later = (x: string, y: string) => new Date(Math.max(new Date(x).getTime(), new Date(y).getTime()))

  const result: ConflictInfo[] = []
  relevant.forEach((a, i) => {
    for (const b of relevant.slice(i + 1)) {
      if (!overlaps(a.startsAt, a.endsAt, b.startsAt, b.endsAt)) continue
      for (const person of peopleOf(a)) {
        if (peopleOf(b).some((p) => p.id === person.id))
          result.push({ kind: 'overlap', person, a, b, from: later(a.startsAt, b.startsAt) })
      }
    }
    for (const absence of absences) {
      const person = peopleOf(a).find((p) => p.id === absence.user.id)
      if (person && overlaps(a.startsAt, a.endsAt, absence.startsAt, absence.endsAt))
        result.push({ kind: 'absence', person, job: a, absence, from: later(a.startsAt, absence.startsAt) })
    }
  })
  return result
}

/** Je Auftrag die Hinweise als Text – fuer Markierung und Tooltip im Kalender. */
export function findConflicts(jobs: Job[], absences: Absence[] = []): Map<number, string[]> {
  const result = new Map<number, string[]>()
  const add = (id: number, message: string) => result.set(id, [...(result.get(id) ?? []), message])

  for (const c of listConflicts(jobs, absences)) {
    if (c.kind === 'overlap') {
      add(c.a.id, `${c.person.shortName} gleichzeitig bei „${c.b.title}“`)
      add(c.b.id, `${c.person.shortName} gleichzeitig bei „${c.a.title}“`)
    } else {
      add(c.job.id, `${c.person.shortName} abwesend: ${c.absence.label}`)
    }
  }
  return result
}
