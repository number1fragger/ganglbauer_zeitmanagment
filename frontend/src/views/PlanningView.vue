<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { jobsApi, planningApi, requestsApi } from '@/api'
import { errorMessage } from '@/api/http'
import type { Job, JobInput, Overview, OverviewWorker } from '@/api/types'
import { useAuthStore } from '@/stores/auth'
import AppIcon from '@/components/AppIcon.vue'
import AgendaList from '@/components/calendar/AgendaList.vue'
import DayCalendar from '@/components/calendar/DayCalendar.vue'
import DragGhost from '@/components/calendar/DragGhost.vue'
import DaySidebar from '@/components/calendar/DaySidebar.vue'
import MonthCalendar from '@/components/calendar/MonthCalendar.vue'
import UnscheduledPanel from '@/components/calendar/UnscheduledPanel.vue'
import WeekCalendar from '@/components/calendar/WeekCalendar.vue'
import WeekSidebar from '@/components/calendar/WeekSidebar.vue'
import JobDialog from '@/components/JobDialog.vue'
import { useBreakpoint } from '@/composables/useBreakpoint'
import { toast } from '@/composables/useToast'
import { jobDurationMs, type MoveEvent } from '@/utils/calendar'
import { findConflicts, isMovable } from '@/utils/domain'
import {
  addDays,
  formatLongDate,
  formatMonth,
  formatTime,
  fromDateKey,
  isoWeek,
  startOfDay,
  startOfMonth,
  startOfWeek,
  toDateKey,
} from '@/utils/time'

type Mode = 'tag' | 'woche' | 'monat'

const modes: { value: Mode; label: string }[] = [
  { value: 'tag', label: 'Tag' },
  { value: 'woche', label: 'Woche' },
  { value: 'monat', label: 'Monat' },
]

const route = useRoute()
const router = useRouter()
const auth = useAuthStore()
const { isMobile } = useBreakpoint()

// Ansicht und Datum stehen in der URL, damit Neuladen und Teilen funktionieren.
const mode = computed<Mode>(() => {
  const value = route.query.ansicht
  if (value === 'woche' || value === 'monat' || value === 'tag') return value
  return isMobile.value ? 'tag' : 'woche'
})
const anchor = computed(
  () => fromDateKey(route.query.datum as string | undefined) ?? startOfDay(new Date()),
)

function show(nextMode: Mode, date: Date): void {
  void router.replace({ query: { ansicht: nextMode, datum: toDateKey(date) } })
}

function step(direction: -1 | 1): void {
  const date = anchor.value
  if (mode.value === 'tag') show('tag', addDays(date, direction))
  if (mode.value === 'woche') show('woche', addDays(date, 7 * direction))
  if (mode.value === 'monat')
    show('monat', new Date(date.getFullYear(), date.getMonth() + direction, 1))
}

const range = computed(() => {
  if (mode.value === 'tag') return { from: anchor.value, to: addDays(anchor.value, 1) }
  if (mode.value === 'woche') {
    const monday = startOfWeek(anchor.value)
    return { from: monday, to: addDays(monday, 7) }
  }
  const first = startOfMonth(anchor.value)
  return {
    from: startOfWeek(first),
    to: addDays(startOfWeek(new Date(first.getFullYear(), first.getMonth() + 1, 0)), 7),
  }
})

const title = computed(() => {
  if (mode.value === 'tag') return formatLongDate(anchor.value)
  if (mode.value === 'monat') return formatMonth(anchor.value)
  const { from } = range.value
  const friday = addDays(from, 4)
  const day = (d: Date) =>
    d.toLocaleDateString('de-AT', { day: 'numeric', month: 'short' }).replace(/\.$/, '')
  return `KW ${isoWeek(from)} · ${day(from)} – ${day(friday)} ${friday.getFullYear()}`
})

// ---- Daten ---------------------------------------------------------------

const overview = ref<Overview | null>(null)
const jobs = ref<Job[]>([])
const unscheduled = ref<Job[]>([])
const error = ref('')
const loading = ref(false)
const visibleWorkers = ref<number[]>([])

const workers = computed(() => overview.value?.workers ?? [])
const workerRefs = computed(() => workers.value.map((w) => w.user))
const conflicts = computed(() => findConflicts(jobs.value))

const conflictPairs = computed(() => {
  const seen = new Set<string>()
  const result: { a: Job; b: Job }[] = []
  for (const a of jobs.value) {
    for (const title of conflicts.value.get(a.id) ?? []) {
      const b = jobs.value.find(
        (j) => j.title === title && j.assignee?.id === a.assignee?.id && j.id !== a.id,
      )
      if (!b) continue
      const key = [a.id, b.id].sort().join('-')
      if (!seen.has(key)) {
        seen.add(key)
        result.push({ a, b })
      }
    }
  }
  return result
})

let loadToken = 0
async function load(): Promise<void> {
  const token = ++loadToken
  error.value = ''
  loading.value = true
  try {
    const [nextJobs, nextOverview, board] = await Promise.all([
      jobsApi.inRange(range.value.from, range.value.to),
      auth.isPlanner ? planningApi.overview() : Promise.resolve(workerOverview()),
      jobsApi.board({ doneDays: 0 }),
    ])
    if (token !== loadToken) return // schon wieder weitergeblaettert
    if (!overview.value) visibleWorkers.value = nextOverview.workers.map((w) => w.user.id)
    overview.value = nextOverview
    jobs.value = nextJobs
    unscheduled.value = board.filter((job) => !job.scheduled)
  } catch (e) {
    error.value = errorMessage(e)
  } finally {
    if (token === loadToken) loading.value = false
  }

  function workerOverview(): Overview {
    const user = auth.user
    if (!user) throw new Error('Benutzer konnte nicht geladen werden.')
    const worker: OverviewWorker = {
      user,
      remainingMinutes: 0,
      openJobs: 0,
      availableFrom: new Date().toISOString(),
    }
    return {
      generatedAt: new Date().toISOString(),
      workers: [worker],
      requests: [],
      warnings: [],
      conflicts: [],
      followUps: [],
    }
  }
}

watch(range, load, { immediate: true })

// ---- Dialog "Arbeit anlegen / bearbeiten" ---------------------------------

interface DialogState {
  job: Job | null
  defaults?: { startsAt?: Date; assigneeId?: number | null }
  requestId?: number
}

const dialog = ref<DialogState | null>(null)

function openJob(job: Job): void {
  dialog.value = { job }
}

/** Warnungen koennen Arbeiten ausserhalb der aktuellen Ansicht betreffen. */
async function openJobById(id: number): Promise<void> {
  try {
    openJob(jobs.value.find((job) => job.id === id) ?? (await jobsApi.get(id)))
  } catch (e) {
    error.value = errorMessage(e)
  }
}

function createJob(defaults: DialogState['defaults'] = {}): void {
  if (!auth.isPlanner) return
  dialog.value = { job: null, defaults }
}

function assignRequest(request: Overview['requests'][number]): void {
  dialog.value = {
    job: null,
    defaults: { startsAt: new Date(request.neededAt), assigneeId: request.user.id },
    requestId: request.id,
  }
}

async function onSaved(): Promise<void> {
  const requestId = dialog.value?.requestId
  dialog.value = null
  if (requestId) await requestsApi.fulfil(requestId).catch(() => undefined)
  await load()
}

async function onDeleted(): Promise<void> {
  dialog.value = null
  await load()
}

/**
 * Drag & Drop: Termin verschieben bzw. Aufgabe einplanen. Begonnene und
 * abgeschlossene Arbeiten lassen sich gar nicht erst ziehen (isMovable).
 */
async function moveJob({ job, startsAt, assigneeId }: MoveEvent): Promise<void> {
  if (!auth.isPlanner || !isMovable(job)) return
  const endsAt = new Date(startsAt.getTime() + jobDurationMs(job))
  const moved: Job = {
    ...job,
    scheduled: true,
    startsAt: startsAt.toISOString(),
    endsAt: endsAt.toISOString(),
    assignee: workerRefs.value.find((worker) => worker.id === assigneeId) ?? null,
  }

  // Sofort anzeigen, danach mit dem Server abgleichen.
  const index = jobs.value.findIndex((item) => item.id === job.id)
  if (index >= 0) jobs.value[index] = moved
  else {
    jobs.value.push(moved)
    unscheduled.value = unscheduled.value.filter((item) => item.id !== job.id)
  }

  const input: JobInput = {
    title: job.title,
    description: job.description,
    customer: job.customer,
    priority: job.priority,
    startsAt: moved.startsAt,
    endsAt: moved.endsAt,
    plannedMinutes: job.plannedMinutes,
    assigneeId,
  }
  error.value = ''
  try {
    await jobsApi.update(job.id, input)
    const clash = findConflicts(jobs.value).get(job.id)
    if (clash?.length)
      toast(`Gespeichert – aber Überschneidung mit „${clash.join('“, „')}“.`, 'warning')
    else
      toast(
        `„${job.title}“ auf ${formatTime(startsAt)} Uhr ${index >= 0 ? 'verschoben' : 'eingeplant'}.`,
        'success',
      )
  } catch (e) {
    toast(errorMessage(e), 'error')
  }
  await load()
}

const hasSidebar = computed(() => !isMobile.value && mode.value !== 'monat')
</script>

<template>
  <div class="planning">
    <DragGhost />
    <header class="toolbar">
      <h1 class="toolbar__heading">Kalender</h1>
      <div class="toolbar__nav">
        <button class="btn btn--outline" @click="show(mode, startOfDay(new Date()))">Heute</button>
        <button
          class="btn btn--icon btn--ghost"
          aria-label="Zurück"
          data-tip="Zurück"
          @click="step(-1)"
        >
          <AppIcon name="chevron-left" />
        </button>
        <button
          class="btn btn--icon btn--ghost"
          aria-label="Weiter"
          data-tip="Weiter"
          @click="step(1)"
        >
          <AppIcon name="chevron-right" />
        </button>
        <h2 class="toolbar__title" aria-live="polite">{{ title }}</h2>
        <span v-if="loading" class="spinner" aria-label="Lädt" />
      </div>

      <div class="toolbar__right">
        <div class="segmented" role="group" aria-label="Ansicht">
          <button
            v-for="m in modes"
            :key="m.value"
            :aria-pressed="mode === m.value"
            @click="show(m.value, anchor)"
          >
            {{ m.label }}
          </button>
        </div>
        <button v-if="auth.isPlanner" class="btn btn--primary" @click="createJob()">
          <AppIcon name="plus" :size="16" /> <span class="toolbar__new-label">Neue Arbeit</span>
        </button>
      </div>
    </header>

    <p v-if="error" class="form-error planning__error" role="alert">
      {{ error }} <button class="link" @click="load">Erneut laden</button>
    </p>

    <div v-if="conflictPairs.length" class="conflicts" role="status">
      <AppIcon name="layers" :size="16" />
      <span>
        <strong
          >{{ conflictPairs.length }} Terminkonflikt{{
            conflictPairs.length > 1 ? 'e' : ''
          }}:</strong
        >&nbsp;
        <template v-for="(pair, i) in conflictPairs.slice(0, 3)" :key="i">
          <button class="link" @click="openJob(pair.b)">
            {{ pair.a.title }} ↔ {{ pair.b.title }}</button
          ><template v-if="i < Math.min(conflictPairs.length, 3) - 1">, </template>
        </template>
        <template v-if="conflictPairs.length > 3"> …</template>
      </span>
    </div>

    <div class="planning__body" :class="{ 'planning__body--sidebar': hasSidebar }">
      <section class="planning__calendar" aria-label="Kalender">
        <AgendaList
          v-if="isMobile"
          :from="range.from"
          :to="range.to"
          :jobs="jobs"
          :conflicts="conflicts"
          @open="openJob"
          @create="createJob({ startsAt: $event, assigneeId: null })"
        />
        <DayCalendar
          v-else-if="mode === 'tag'"
          :day="anchor"
          :workers="workers"
          :jobs="jobs"
          :draggable="auth.isPlanner"
          :can-drag="isMovable"
          :conflicts="conflicts"
          @open="openJob"
          @create="createJob"
          @move="moveJob"
        />
        <WeekCalendar
          v-else-if="mode === 'woche'"
          :week-start="range.from"
          :workers="workers"
          :visible="visibleWorkers"
          :jobs="jobs"
          :draggable="auth.isPlanner"
          :can-drag="isMovable"
          :conflicts="conflicts"
          @open="openJob"
          @create="createJob"
          @move="moveJob"
        />
        <MonthCalendar
          v-else
          :month="anchor"
          :workers="workers"
          :jobs="jobs"
          :conflicts="conflicts"
          @open="openJob"
          @show-day="show('tag', $event)"
        />
      </section>

      <div v-if="hasSidebar" class="planning__sidebar">
        <UnscheduledPanel
          class="planning__unscheduled"
          :jobs="unscheduled"
          :draggable="auth.isPlanner"
          @open="openJob"
          @move="moveJob"
        />
        <DaySidebar
          v-if="mode === 'tag' && overview && auth.isPlanner"
          :overview="overview"
          @assign="assignRequest"
          @open-job="openJobById"
        />
        <WeekSidebar
          v-else-if="mode === 'woche' && auth.isPlanner"
          v-model:visible="visibleWorkers"
          :week-start="range.from"
          :workers="workers"
          :jobs="jobs"
        />
      </div>
    </div>

    <JobDialog
      v-if="dialog"
      :job="dialog.job"
      :defaults="dialog.defaults"
      :workers="workerRefs"
      @close="dialog = null"
      @saved="onSaved"
      @deleted="onDeleted"
      @changed="load"
    />
  </div>
</template>

<style scoped>
.planning {
  display: flex;
  flex-direction: column;
  height: 100dvh;
}

.toolbar {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 12px 20px;
  padding: 14px 24px;
  border-bottom: 1px solid var(--border);
  background: var(--surface);
}

.toolbar__heading {
  font-size: 18px;
  font-weight: 700;
}

.toolbar__nav {
  display: flex;
  flex: 1 1 340px;
  align-items: center;
  gap: 4px;
  min-width: 0;
}

.toolbar__nav .btn--outline {
  margin-right: 6px;
}

.toolbar__title {
  margin: 0 10px 0 8px;
  overflow: hidden;
  font-size: 16px;
  font-weight: 600;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.toolbar__right {
  display: flex;
  align-items: center;
  gap: 10px;
}

.planning__error {
  margin: 12px 24px 0;
}

.conflicts {
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 9px 24px;
  border-bottom: 1px solid var(--border);
  background: var(--danger-soft);
  color: var(--danger);
  font-size: 12px;
}

.conflicts .link {
  color: var(--danger);
  font-weight: 600;
}

.planning__body {
  display: grid;
  flex: 1;
  grid-template-columns: minmax(0, 1fr);
  min-height: 0;
}

.planning__body--sidebar {
  grid-template-columns: minmax(0, 1fr) clamp(260px, 22vw, 340px);
}

.planning__calendar {
  overflow: auto;
  background: var(--surface);
}

.planning__sidebar {
  overflow-y: auto;
  border-left: 1px solid var(--border);
  background: var(--sidebar);
}

.planning__unscheduled {
  padding: 20px 20px 4px;
}

@media (max-width: 1099px) {
  .planning__body--sidebar {
    grid-template-columns: minmax(0, 1fr) 260px;
  }
}

@media (max-width: 899px) and (min-width: 700px) {
  .planning {
    height: auto;
    min-height: 100dvh;
  }

  .planning__body--sidebar {
    grid-template-columns: minmax(0, 1fr);
  }

  .planning__calendar {
    max-height: 75dvh;
  }

  .planning__sidebar {
    border-top: 1px solid var(--border);
    border-left: 0;
  }
}

@media (max-width: 699px) {
  .planning {
    height: auto;
  }

  .toolbar {
    position: sticky;
    z-index: 5;
    top: 56px;
    gap: 10px;
    padding: 10px 16px;
  }

  .toolbar__heading {
    display: none;
  }

  .toolbar__nav {
    flex-basis: 100%;
  }

  .toolbar__title {
    font-size: 14px;
  }

  .toolbar__right {
    flex: 1;
  }

  .toolbar__right .segmented {
    flex: 1;
  }

  .toolbar__right .segmented button {
    flex: 1;
    min-width: 0;
  }

  .toolbar__new-label {
    display: none;
  }

  .conflicts {
    padding: 9px 16px;
  }

  .planning__calendar {
    overflow: visible;
    background: none;
  }
}
</style>
