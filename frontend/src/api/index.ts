import { http } from './http'
import type {
  FollowUp,
  Job,
  JobInput,
  Overview,
  SollIstReport,
  User,
  ThemePreference,
  UserInput,
  WorkerStatus,
  WorkRequest,
} from './types'

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
  extend: (id: number, minutes: number) => http.post<Job>(`/api/jobs/${id}/extend`, { minutes }),
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
  create: (neededAt: Date) => http.post<WorkRequest>('/api/work-requests', { neededAt: neededAt.toISOString() }),
  withdraw: (id: number) => http.post<void>(`/api/work-requests/${id}/withdraw`),
  fulfil: (id: number) => http.post<void>(`/api/work-requests/${id}/fulfil`),
}

export const planningApi = {
  overview: () => http.get<Overview>('/api/overview'),
  report: (from: string, to: string) => http.get<SollIstReport>(`/api/reports/soll-ist?${query({ from, to })}`),
}

export const usersApi = {
  list: () => http.get<User[]>('/api/users'),
  create: (input: UserInput) => http.post<User>('/api/users', input),
  update: (id: number, input: UserInput) => http.put<User>(`/api/users/${id}`, input),
}
