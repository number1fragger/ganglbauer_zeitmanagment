/** Chef > Vorarbeiter > Arbeiter – siehe role_hierarchy im Backend. */
export type Role = 'ROLE_ADMIN' | 'ROLE_FOREMAN' | 'ROLE_USER'

export interface User {
  id: number
  email: string
  firstName: string
  lastName: string
  fullName: string
  roles: string[]
  role: Role
  roleLabel: string
  initials: string
  weeklyHours: number
  dailyMinutes: number
  active: boolean
  createdAt: string
}

export type WorkerRef = Pick<User, 'id' | 'firstName' | 'lastName' | 'fullName' | 'initials'>

export type Priority = 'niedrig' | 'normal' | 'hoch' | 'dringend'
export type JobStatus = 'offen' | 'in_arbeit' | 'erledigt'

/** Eine Arbeit in der Werkstatt. */
export interface Job {
  id: number
  title: string
  description: string | null
  customer: string | null
  priority: Priority
  status: JobStatus
  plannedMinutes: number
  originalPlannedMinutes: number
  extendedMinutes: number
  startsAt: string | null
  dueAt: string | null
  assignee: WorkerRef | null
  completedAt: string | null
  createdAt: string
  actualMinutes: number
  remainingMinutes: number
  overrun: boolean
  overrunMinutes: number
  running: boolean
  progressPercent: number
}

export interface TimeEntry {
  id: number
  user: WorkerRef
  job: Pick<Job, 'id' | 'title' | 'customer'>
  note: string | null
  startedAt: string
  endedAt: string | null
  running: boolean
  durationMinutes: number
  createdAt: string
}

export type WorkRequestStatus = 'offen' | 'zugeteilt' | 'zurueckgezogen'

export interface WorkRequest {
  id: number
  user: WorkerRef
  neededAt: string
  note: string | null
  status: WorkRequestStatus
  createdAt: string
}

/** Kurzform einer Arbeit, wie sie in der Uebersicht mitgeliefert wird. */
export interface OverviewJob {
  id: number
  title: string
  customer: string | null
  priority: Priority
  plannedMinutes: number
  actualMinutes: number
  remainingMinutes: number
  overrun: boolean
  dueAt: string | null
}

export interface OverviewWorker {
  user: { id: number; fullName: string; dailyMinutes: number }
  openJobs: number
  remainingMinutes: number
  overrunJobs: number
  availableFrom: string
  availableToday: boolean
  currentJob: OverviewJob | null
  nextJob: OverviewJob | null
  workRequest: { id: number; neededAt: string; note: string | null } | null
}

export interface Overview {
  generatedAt: string
  workers: OverviewWorker[]
  totals: {
    workers: number
    openJobs: number
    remainingMinutes: number
    openRequests: number
  }
}

export interface SollIstWorker {
  workerId: number | null
  worker: string
  jobs: number
  originalPlannedMinutes: number
  plannedMinutes: number
  actualMinutes: number
  diffMinutes: number
  accuracyPercent: number
}

export interface SollIstJob {
  jobId: number
  title: string
  customer: string | null
  worker: string
  originalPlannedMinutes: number
  plannedMinutes: number
  actualMinutes: number
  diffMinutes: number
  completedAt: string | null
}

export interface SollIstReport {
  from: string
  to: string
  perWorker: SollIstWorker[]
  jobs: SollIstJob[]
}

export interface ApiError {
  title: string
  status?: number
  errors?: { field: string; message: string }[]
}

/** Eine Arbeit, wie sie der Kalender liefert. */
export interface CalendarJob {
  jobId: number
  title: string
  customer: string | null
  priority: Priority
  status: JobStatus
  done: boolean
  running: boolean
  overrun: boolean
  plannedMinutes: number
  actualMinutes: number
  assignee: { id: number; fullName: string; initials: string } | null
  startsAt: string | null
  dueAt: string | null
}

/** Ein Tagesabschnitt einer Arbeit – mehrtaegige Arbeiten haben mehrere. */
export interface CalendarSegment extends CalendarJob {
  workerId: number
  start: string
  end: string
  part: number
  parts: number
  plannedEnd: string
  late: boolean
  /** Geplantes Ende ist vorbei, die Arbeit aber noch nicht erledigt. */
  behind: boolean
}

export interface CalendarWorker {
  id: number
  fullName: string
  initials: string
  dailyMinutes: number
}

export interface CalendarData {
  from: string
  to: string
  dayStartHour: number
  workers: CalendarWorker[]
  segments: CalendarSegment[]
  unscheduled: CalendarJob[]
}
