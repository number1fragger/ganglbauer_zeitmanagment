import { http } from './http'
import type {
  Absence,
  AbsenceInput,
  AbsenceTypeOption,
  DeletionImpact,
  FollowUp,
  Job,
  JobInput,
  Overview,
  ActualTimeReport,
  ScheduleInput,
  User,
  ThemePreference,
  UserInput,
  WorkerStatus,
  WorkRequest,
  WorkRequestReport,
  ScheduleCheck,
} from './types'
import type { WorkingHoursConfig } from '@/utils/workTime'

const query = (params: Record<string, string>) => new URLSearchParams(params).toString()

export const authApi = {
  login: (username: string, password: string) =>
    http.post<{ token: string }>('/api/login', { username, password }),
  me: () => http.get<User>('/api/me'),
  status: () => http.get<WorkerStatus>('/api/me/status'),
  changePassword: (currentPassword: string, newPassword: string) =>
    http.put<void>('/api/me/password', { currentPassword, newPassword }),
  savePreferences: (theme: ThemePreference) => http.put<User>('/api/me/preferences', { theme }),
}

export interface BoardFilter {
  assignee?: number | null
  priority?: string | null
  q?: string
  doneDays?: number
}

export const jobsApi = {
  inRange: (from: Date, to: Date) =>
    http.get<Job[]>(`/api/jobs?${query({ from: from.toISOString(), to: to.toISOString() })}`),
  board: (filter: BoardFilter = {}) => {
    const params: Record<string, string> = {}
    if (filter.assignee) params.assignee = String(filter.assignee)
    if (filter.priority) params.priority = filter.priority
    if (filter.q) params.q = filter.q
    if (filter.doneDays !== undefined) params.doneDays = String(filter.doneDays)
    return http.get<Job[]>(`/api/jobs/board?${query(params)}`)
  },
  mine: () => http.get<Job[]>('/api/jobs/mine'),
  get: (id: number) => http.get<Job>(`/api/jobs/${id}`),
  create: (input: JobInput) => http.post<Job>('/api/jobs', input),
  update: (id: number, input: JobInput) => http.put<Job>(`/api/jobs/${id}`, input),
  remove: (id: number) => http.delete(`/api/jobs/${id}`),
  /** Vorschau: Ende/Dauer nach Arbeitszeiten und Hinweise, bevor gespeichert wird. */
  check: (params: {
    startsAt: string
    endsAt?: string | null
    durationMinutes?: number | null
    assigneeId?: number | null
    apprenticeId?: number | null
    jobId?: number | null
  }) => {
    const values: Record<string, string> = { startsAt: params.startsAt }
    if (params.endsAt) values.endsAt = params.endsAt
    if (params.durationMinutes) values.durationMinutes = String(params.durationMinutes)
    if (params.assigneeId) values.assigneeId = String(params.assigneeId)
    if (params.apprenticeId) values.apprenticeId = String(params.apprenticeId)
    if (params.jobId) values.jobId = String(params.jobId)
    return http.get<ScheduleCheck>(`/api/jobs/check?${query(values)}`)
  },
  /** Einplanen/Verschieben – eine Arbeit hat genau einen Termin, nie einen zweiten. */
  schedule: (id: number, input: ScheduleInput) => http.put<Job>(`/api/jobs/${id}/schedule`, input),
  /** Aus dem Kalender nehmen – zurueck in die To-do-Liste, Ist-Zeit bleibt. */
  unschedule: (id: number) => http.delete<Job>(`/api/jobs/${id}/schedule`),
  start: (id: number) => http.post<Job>(`/api/jobs/${id}/start`),
  pause: (id: number) => http.post<Job>(`/api/jobs/${id}/pause`),
  complete: (id: number) => http.post<Job>(`/api/jobs/${id}/complete`),
  reopen: (id: number) => http.post<Job>(`/api/jobs/${id}/reopen`),
  stopTimer: () => http.post<Job | undefined>('/api/time/stop'),
  followUp: (id: number) => http.get<FollowUp | undefined>(`/api/jobs/${id}/follow-up`),
  applyFollowUp: (id: number, jobIds: number[]) =>
    http.post<{ moved: Job[]; job: Job }>(`/api/jobs/${id}/follow-up`, { jobIds }),
}

export const requestsApi = {
  mine: () => http.get<WorkRequest | null>('/api/work-requests/mine'),
  create: (neededAt: Date) =>
    http.post<WorkRequest>('/api/work-requests', { neededAt: neededAt.toISOString() }),
  withdraw: (id: number) => http.post<void>(`/api/work-requests/${id}/withdraw`),
  fulfil: (id: number) => http.post<void>(`/api/work-requests/${id}/fulfil`),
}

export const planningApi = {
  overview: () => http.get<Overview>('/api/overview'),
  report: (from: string, to: string) =>
    http.get<ActualTimeReport>(`/api/reports/ist-zeit?${query({ from, to })}`),
  workRequestReport: (from: string, to: string) =>
    http.get<WorkRequestReport>(`/api/reports/arbeitsanfragen?${query({ from, to })}`),
}

export const absencesApi = {
  inRange: (from: Date, to: Date) =>
    http.get<Absence[]>(`/api/absences?${query({ from: from.toISOString(), to: to.toISOString() })}`),
  types: () => http.get<AbsenceTypeOption[]>('/api/absences/types'),
  get: (id: number) => http.get<Absence>(`/api/absences/${id}`),
  create: (input: AbsenceInput) => http.post<Absence>('/api/absences', input),
  update: (id: number, input: AbsenceInput) => http.put<Absence>(`/api/absences/${id}`, input),
  remove: (id: number) => http.delete(`/api/absences/${id}`),
}

export const configApi = {
  workingHours: () => http.get<WorkingHoursConfig>('/api/working-hours'),
}

export const usersApi = {
  list: () => http.get<User[]>('/api/users'),
  create: (input: UserInput) => http.post<User>('/api/users', input),
  update: (id: number, input: UserInput) => http.put<User>(`/api/users/${id}`, input),
  deletionImpact: (id: number) => http.get<DeletionImpact>(`/api/users/${id}/deletion-impact`),
  /** Endgueltig loeschen – Auftraege und Ist-Zeit bleiben als Historie. */
  remove: (id: number) => http.delete(`/api/users/${id}`),
}
