import { computed, ref } from 'vue'
import { defineStore } from 'pinia'
import { http } from '@/api/client'
import type { Job, Overview, TimeEntry, User, WorkRequest } from '@/api/types'
import { useAuthStore } from '@/stores/auth'

/**
 * Haelt den Zustand der Werkstatt: Arbeiten, Uebersicht, laufende
 * Zeiterfassung und die eigene Arbeitsanforderung.
 */
export const useWorkshopStore = defineStore('workshop', () => {
  const jobs = ref<Job[]>([])
  const workers = ref<User[]>([])
  const overview = ref<Overview | null>(null)
  const running = ref<TimeEntry | null>(null)
  const myRequest = ref<WorkRequest | null>(null)
  const now = ref(Date.now())

  let ticker: ReturnType<typeof setInterval> | null = null

  /** Sekunden seit Start des laufenden Eintrags – tickt jede Sekunde. */
  const runningSeconds = computed(() => {
    if (!running.value) return 0

    return Math.max(0, Math.floor((now.value - new Date(running.value.startedAt).getTime()) / 1000))
  })

  const openJobs = computed(() => jobs.value.filter((job) => job.status !== 'erledigt'))
  const overrunJobs = computed(() => openJobs.value.filter((job) => job.overrun))

  function startTicker(): void {
    if (ticker) return

    ticker = setInterval(() => {
      now.value = Date.now()
    }, 1000)
  }

  function stopTicker(): void {
    if (!ticker) return

    clearInterval(ticker)
    ticker = null
  }

  async function loadOverview(): Promise<void> {
    const { data } = await http.get<Overview>('/api/overview')
    overview.value = data
  }

  async function loadJobs(params: Record<string, unknown> = {}): Promise<void> {
    const { data } = await http.get<Job[]>('/api/jobs', { params })
    jobs.value = data
  }

  /** Aktive Arbeiter zum Zuteilen – nur fuer Chef und Vorarbeiter. */
  async function loadWorkers(): Promise<void> {
    if (!useAuthStore().isForeman) {
      workers.value = []
      return
    }

    const { data } = await http.get<User[]>('/api/workers')
    workers.value = data
  }

  /**
   * Nach einer Aenderung neu laden. Die Uebersicht gibt es nur fuer
   * Chef und Vorarbeiter – ein Arbeiter bekaeme dort 403.
   */
  async function refresh(params: Record<string, unknown> = {}): Promise<void> {
    const tasks: Promise<void>[] = [loadJobs(params)]

    if (useAuthStore().isForeman) {
      tasks.push(loadOverview())
    }

    await Promise.all(tasks)
  }

  async function loadRunning(): Promise<void> {
    const { data } = await http.get<TimeEntry | null>('/api/time-entries/running')
    running.value = data ?? null

    if (running.value) {
      now.value = Date.now()
      startTicker()
    } else {
      stopTicker()
    }
  }

  async function loadMyRequest(): Promise<void> {
    const { data } = await http.get<WorkRequest | null>('/api/work-requests/mine')
    myRequest.value = data ?? null
  }

  async function createJob(payload: Record<string, unknown>): Promise<void> {
    await http.post('/api/jobs', payload)
  }

  async function updateJob(id: number, payload: Record<string, unknown>): Promise<void> {
    await http.patch(`/api/jobs/${id}`, payload)
  }

  /** F5 – geplante Zeit erhoehen. */
  async function extendJob(id: number, minutes: number): Promise<void> {
    await http.post(`/api/jobs/${id}/extend`, { minutes })
  }

  /** F4 – Arbeit abhaken bzw. wieder oeffnen. */
  async function completeJob(id: number, done = true): Promise<void> {
    await http.post(`/api/jobs/${id}/complete`, { done })
  }

  async function deleteJob(id: number): Promise<void> {
    await http.delete(`/api/jobs/${id}`)
    jobs.value = jobs.value.filter((job) => job.id !== id)
  }

  async function startWork(jobId: number): Promise<void> {
    const { data } = await http.post<TimeEntry>('/api/time-entries/start', { jobId })
    running.value = data
    now.value = Date.now()
    startTicker()
  }

  async function stopWork(): Promise<void> {
    await http.post('/api/time-entries/stop')
    running.value = null
    stopTicker()
  }

  /** F7 – "Brauche Arbeit" melden. */
  async function requestWork(neededAt: string, note: string | null): Promise<void> {
    const { data } = await http.post<WorkRequest>('/api/work-requests', { neededAt, note })
    myRequest.value = data
  }

  async function withdrawRequest(id: number): Promise<void> {
    await http.put(`/api/work-requests/${id}/status`, { status: 'zurueckgezogen' })
    myRequest.value = null
  }

  function reset(): void {
    stopTicker()
    jobs.value = []
    workers.value = []
    overview.value = null
    running.value = null
    myRequest.value = null
  }

  return {
    jobs,
    workers,
    overview,
    running,
    myRequest,
    runningSeconds,
    openJobs,
    overrunJobs,
    loadOverview,
    loadJobs,
    loadWorkers,
    refresh,
    loadRunning,
    loadMyRequest,
    createJob,
    updateJob,
    extendJob,
    completeJob,
    deleteJob,
    startWork,
    stopWork,
    requestWork,
    withdrawRequest,
    reset,
  }
})
