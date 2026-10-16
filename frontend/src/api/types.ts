export type Role = 'chef' | 'sekretaerin' | 'arbeiter' | 'lehrling'
export type Priority = 'hoch' | 'mittel' | 'nieder'
export type JobStatus = 'offen' | 'in_arbeit' | 'erledigt'

/** Kurzform eines Benutzers, wie sie an Arbeiten und Anfragen haengt. */
export interface UserRef {
  id: number
  fullName: string
  shortName: string
  initials: string
  role?: Role
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

/** Hinweis zu einem Termin – gespeichert wird trotzdem. */
export interface ScheduleWarning {
  type: 'arbeitszeit' | 'ueberschneidung' | 'abwesenheit'
  message: string
  jobId?: number
  absenceId?: number
  userId?: number
}

/**
 * Eine Arbeit bzw. Aufgabe. Termin im Kalender (startsAt/endsAt, optional,
 * auch mehrtaegig) und Ist-Zeit (actualSeconds = Summe der Arbeitsabschnitte)
 * sind strikt getrennt. workMinutes ist die geplante Dauer = Arbeitszeit
 * innerhalb des Termins (berechnet, nicht gespeichert).
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
  workMinutes: number | null
  assignee: UserRef | null
  /** Optional: Lehrling als zusaetzliche Unterstuetzung */
  apprentice: UserRef | null
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
  /** Nach dem Speichern bzw. in der Detailansicht */
  warnings?: ScheduleWarning[]
}

/** Eingeplante Arbeit – startsAt/endsAt sind gesetzt. */
export type ScheduledJob = Job & { startsAt: string; endsAt: string }

export interface TimeEntry {
  id: number
  /** null, wenn der Benutzer inzwischen geloescht wurde – dann gilt userName */
  user: UserRef | null
  userName: string
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
  apprenticeId: number | null
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
  workerId?: number
  from: string
  jobs: { id: number; title: string; startsAt: string }[]
}

export interface AbsenceConflict {
  worker: string
  workerId: number
  job: { id: number; title: string; startsAt: string }
  absence: { id: number; label: string; period: string }
}

export interface Overview {
  generatedAt: string
  workers: OverviewWorker[]
  requests: { id: number; user: UserRef; neededAt: string }[]
  conflicts: Conflict[]
  absenceConflicts: AbsenceConflict[]
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
    /** null bei geloeschten Benutzern */
    workerId: number | null
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

export type AbsenceType = 'urlaub' | 'arzttermin' | 'persoenlich' | 'berufsschule' | (string & {})

export interface AbsenceTypeOption {
  value: AbsenceType
  label: string
  allDay: boolean
}

/** Urlaub oder persoenlicher Termin. note/createdBy nur fuer Chef und Sekretaerin. */
export interface Absence {
  id: number
  user: UserRef
  type: AbsenceType
  label: string
  startsAt: string
  /** Exklusiv; bei ganztaegigen Eintraegen 00:00 nach dem letzten Tag */
  endsAt: string
  allDay: boolean
  /** Letzter Tag (JJJJ-MM-TT) */
  lastDay: string
  note?: string | null
  createdBy?: UserRef | null
  createdAt?: string
  updatedAt?: string | null
  /** Nach dem Speichern: Auftraege in diesem Zeitraum (bleiben unveraendert) */
  conflicts?: { id: number; title: string; startsAt: string | null; endsAt: string | null }[]
}

export interface AbsenceInput {
  userId: number | null
  type: AbsenceType
  allDay: boolean
  startDate?: string | null
  endDate?: string | null
  startsAt?: string | null
  endsAt?: string | null
  note?: string | null
}

export interface ScheduleCheck {
  startsAt: string
  endsAt: string
  workMinutes: number
  warnings: ScheduleWarning[]
}

export interface DeletionImpact {
  openJobs: number
  doneJobs: number
  apprenticeJobs: number
  timeEntries: number
  workRequests: number
  absences: number
  running: boolean
}

export interface WorkRequestReport {
  from: string
  to: string
  rule: string
  totals: {
    requests: number
    previousRequests: number
    fulfilled: number
    open: number
    withdrawn: number
    workersWithRequests: number
    averagePerWorker: number
  }
  perWorker: {
    workerId: number | null
    worker: string
    role: Role | null
    deleted: boolean
    active: boolean
    requests: number
    fulfilled: number
    open: number
    withdrawn: number
    share: number
  }[]
}
