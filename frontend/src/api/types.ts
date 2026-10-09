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
 * Eine Arbeit bzw. Aufgabe. Termin im Kalender (startsAt/endsAt, optional,
 * auch mehrtaegig) und Ist-Zeit (actualSeconds = Summe der Arbeitsabschnitte)
 * sind strikt getrennt. Eine geplante Arbeitsdauer gibt es nicht.
 */
export interface Job {
  id: number
  title: string
  description: string | null
  customer: string | null
  priority: Priority
  status: JobStatus
  scheduled: boolean
  startsAt: string | null
  endsAt: string | null
  calendarMinutes: number | null
  assignee: UserRef | null
  completedAt: string | null
  createdAt: string
  actualSeconds: number
  actualMinutes: number
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
  assigneeId: number | null
  confirmStartedChange?: boolean
}

/** Einplanen per Drag & Drop oder "Einplanen"-Dialog. Ohne Ende: technischer Standardblock. */
export interface ScheduleInput {
  startsAt: string
  endsAt?: string | null
  assigneeId?: number | null
  changeAssignee?: boolean
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
  scheduledEnd: string
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
  openJobs: number
  unscheduledJobs: number
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
  conflicts: Conflict[]
  followUps: FollowUp[]
}

export interface ActualTimeReport {
  from: string
  to: string
  totals: {
    actualMinutes: number
    previousActualMinutes: number
    workers: number
    jobsWorkedOn: number
    jobsCompleted: number
    multiDayJobs: number
    autoClosedEntries: number
  }
  perWorker: {
    workerId: number
    worker: string
    actualMinutes: number
    jobs: number
    days: number
  }[]
  perDay: { date: string; actualMinutes: number }[]
  topJobs: {
    jobId: number
    title: string
    worker: string
    status: JobStatus
    actualMinutes: number
    workedDays: number
  }[]
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
