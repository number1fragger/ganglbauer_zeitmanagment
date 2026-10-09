export type Role = 'chef' | 'vorarbeiter' | 'arbeiter'
export type Priority = 'hoch' | 'mittel' | 'nieder'
export type JobStatus = 'offen' | 'in_arbeit' | 'erledigt'

/** Kurzform eines Benutzers, wie sie an Arbeiten und Anfragen haengt. */
export interface UserRef {
  id: number
  fullName: string
  shortName: string
  initials: string
}

export interface User extends UserRef {
  username: string
  theme?: ThemePreference
  firstName: string
  lastName: string
  role: Role
  weeklyHours: number
  active: boolean
}

export type ThemePreference = 'light' | 'dark' | 'system'

/**
 * Eine Arbeit bzw. Aufgabe. Drei Zeitangaben sind strikt getrennt:
 * geplante Arbeitszeit (plannedMinutes), Termin im Kalender (startsAt/endsAt,
 * optional, auch mehrtaegig) und Ist-Zeit (actualSeconds = Summe der Abschnitte).
 */
export interface Job {
  id: number
  title: string
  description: string | null
  customer: string | null
  priority: Priority
  status: JobStatus
  plannedMinutes: number | null
  originalPlannedMinutes: number | null
  scheduled: boolean
  startsAt: string | null
  endsAt: string | null
  calendarMinutes: number | null
  assignee: UserRef | null
  completedAt: string | null
  createdAt: string
  actualSeconds: number
  actualMinutes: number
  remainingMinutes: number | null
  overrun: boolean
  overrunMinutes: number
  started: boolean
  running: boolean
  paused: boolean
  runningSince: string | null
  firstStartedAt: string | null
  entryCount: number
  workedDays: number
  /** Nur in der Detailansicht (GET /api/jobs/{id}) */
  timeEntries?: TimeEntry[]
}

/** Eingeplante Arbeit – startsAt/endsAt sind gesetzt. */
export type ScheduledJob = Job & { startsAt: string; endsAt: string }

export interface TimeEntry {
  id: number
  user: UserRef
  startedAt: string
  endedAt: string | null
  running: boolean
  autoClosed: boolean
  durationSeconds: number
}

export interface JobInput {
  title: string
  description: string | null
  customer: string | null
  priority: Priority
  startsAt: string | null
  endsAt: string | null
  plannedMinutes: number | null
  assigneeId: number | null
  confirmStartedChange?: boolean
}

export interface FollowUpMove {
  jobId: number
  title: string
  customer: string | null
  from: { startsAt: string; endsAt: string }
  to: { startsAt: string; endsAt: string }
  recommended: boolean
  warning: string | null
}

export interface FollowUp {
  jobId: number
  direction: 'earlier' | 'later'
  shiftMinutes: number
  plannedEnd: string
  actualEnd: string
  moves: FollowUpMove[]
  warnings: string[]
  /** nur in der Uebersicht */
  title?: string
  worker?: string | null
}

export interface WorkRequest {
  id: number
  user: UserRef
  neededAt: string
  status: 'offen' | 'zugeteilt' | 'zurueckgezogen'
  createdAt: string
}

export interface WorkerStatus {
  remainingMinutes: number
  openJobs: number
  availableFrom: string
}

export interface OverviewWorker extends WorkerStatus {
  user: UserRef & { role: Role; weeklyHours: number }
  current?: { jobId: number; title: string; runningSince: string } | null
}

export interface Conflict {
  worker: string | null
  from: string
  jobs: { id: number; title: string; startsAt: string }[]
}

export interface Overview {
  generatedAt: string
  workers: OverviewWorker[]
  requests: { id: number; user: UserRef; neededAt: string }[]
  warnings: { jobId: number; title: string; worker: string | null; overrunMinutes: number }[]
  conflicts: Conflict[]
  followUps: FollowUp[]
}

export interface SollIstReport {
  from: string
  to: string
  totals: {
    plannedMinutes: number
    actualMinutes: number
    jobs: number
    workers: number
    accuracyPercent: number | null
    accuracyDelta: number | null
    overruns: number
  }
  perWorker: { workerId: number | null; worker: string; plannedMinutes: number; actualMinutes: number }[]
  deviations: { jobId: number; title: string; worker: string; diffMinutes: number }[]
}

export interface UserInput {
  username?: string
  firstName: string
  lastName: string
  role: Role
  weeklyHours: number
  active: boolean
  password?: string
}
