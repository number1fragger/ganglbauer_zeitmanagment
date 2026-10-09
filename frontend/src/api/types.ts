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
  firstName: string
  lastName: string
  role: Role
  weeklyHours: number
  active: boolean
}

export interface Job {
  id: number
  title: string
  customer: string | null
  priority: Priority
  status: JobStatus
  plannedMinutes: number
  originalPlannedMinutes: number
  startsAt: string
  endsAt: string
  assignee: UserRef | null
  completedAt: string | null
  actualMinutes: number
  remainingMinutes: number
  overrun: boolean
  overrunMinutes: number
  running: boolean
}

export interface JobInput {
  title: string
  customer: string | null
  priority: Priority
  startsAt: string
  endsAt: string
  plannedMinutes: number
  assigneeId: number | null
  done: boolean
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
}

export interface Overview {
  generatedAt: string
  workers: OverviewWorker[]
  requests: { id: number; user: UserRef; neededAt: string }[]
  warnings: { jobId: number; title: string; worker: string | null; overrunMinutes: number }[]
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
