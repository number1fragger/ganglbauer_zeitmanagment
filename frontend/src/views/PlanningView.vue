<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { jobsApi, planningApi, requestsApi } from '@/api'
import { errorMessage } from '@/api/http'
import type { Job, Overview, OverviewWorker } from '@/api/types'
import { useAuthStore } from '@/stores/auth'
import AppIcon from '@/components/AppIcon.vue'
import AgendaList from '@/components/calendar/AgendaList.vue'
import DayCalendar from '@/components/calendar/DayCalendar.vue'
import DragGhost from '@/components/calendar/DragGhost.vue'
import DaySidebar from '@/components/calendar/DaySidebar.vue'
import MonthCalendar from '@/components/calendar/MonthCalendar.vue'
import ScheduleDialog from '@/components/calendar/ScheduleDialog.vue'
import TodoSidebar from '@/components/calendar/TodoSidebar.vue'
import WeekCalendar from '@/components/calendar/WeekCalendar.vue'
import WeekSidebar from '@/components/calendar/WeekSidebar.vue'
import JobDialog from '@/components/JobDialog.vue'
import { useBreakpoint } from '@/composables/useBreakpoint'
import { drag } from '@/composables/useJobDrag'
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
const { isMobile, isDesktop } = useBreakpoint()

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

// ---- Daten ---------------------------------------------------------------

const overview = ref<Overview | null>(null)
const jobs = ref<Job[]>([])
/** To-do: offene Auftraege ohne Termin (Status und Einplanung sind getrennt). */
const todos = ref<Job[]>([])
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
/** Laedt Kalender, To-do-Liste und Uebersicht – nach jeder Aenderung aufgerufen. */
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
    todos.value = board.filter((job) => !job.scheduled && job.status !== 'erledigt')
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
      openJobs: 0,
      unscheduledJobs: 0,
      availableFrom: new Date().toISOString(),
    }
    return {
      generatedAt: new Date().toISOString(),
      workers: [worker],
      requests: [],
      conflicts: [],
      followUps: [],
    }
  }
}

watch(range, load, { immediate: true })

// ---- To-do-Seitenleiste ---------------------------------------------------

const COLLAPSE_KEY = 'werkstatt_todo_collapsed'
function readCollapsed(): boolean {
  try {
    return localStorage.getItem(COLLAPSE_KEY) === '1'
  } catch {
    return false
  }
}

/** Desktop: eingeklappt ja/nein (gemerkt). Tablet/Handy: als Schublade geoeffnet ja/nein. */
const collapsed = ref(readCollapsed())
const drawerOpen = ref(false)

function setCollapsed(value: boolean): void {
  collapsed.value = value
  try {
    localStorage.setItem(COLLAPSE_KEY, value ? '1' : '0')
  } catch {
    /* nur fuer diese Sitzung */
  }
}

function toggleTodo(): void {
  if (isDesktop.value) setCollapsed(!collapsed.value)
  else drawerOpen.value = !drawerOpen.value
}

const showSidebar = computed(() => (isDesktop.value ? !collapsed.value : drawerOpen.value))

// Beim Ziehen aus der Schublade den Kalender freigeben.
watch(
  () => drag.active,
  (active) => {
    if (active && !isDesktop.value && !drag.job?.scheduled) drawerOpen.value = false
  },
)

const dropHint = computed(() =>
  mode.value === 'monat' && auth.isPlanner
    ? 'Zum Einplanen in die Tages- oder Wochenansicht wechseln – oder „Einplanen“ verwenden.'
    : null,
)

// ---- Dialoge ----------------------------------------------------------------

interface DialogState {
  job: Job | null
  defaults?: { startsAt?: Date; assigneeId?: number | null }
  requestId?: number
}

const dialog = ref<DialogState | null>(null)
const planning = ref<Job | null>(null)

function openJob(job: Job): void {
  dialog.value = { job }
}

async function openJobById(id: number): Promise<void> {
  try {
    openJob(jobs.value.find((job) => job.id === id) ?? (await jobsApi.get(id)))
  } catch (e) {
    error.value = errorMessage(e)
  }
}

/** Neuer Auftrag: ohne Termin landet er in der To-do-Liste. Klick in den Kalender plant direkt ein. */
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

async function onSaved(job: Job): Promise<void> {
  const requestId = dialog.value?.requestId
  dialog.value = null
  if (requestId) await requestsApi.fulfil(requestId).catch(() => undefined)
  if (!job.scheduled && job.status !== 'erledigt') {
    if (isDesktop.value) setCollapsed(false)
    toast(`„${job.title}“ steht in der To-do-Liste – zum Einplanen in den Kalender ziehen.`, 'info')
  }
  await load()
}

async function closeAndReload(): Promise<void> {
  dialog.value = null
  planning.value = null
  await load()
}

// ---- Einplanen per Drag & Drop ------------------------------------------------

/**
 * Drag & Drop: aus der To-do-Liste einplanen, im Kalender verschieben oder
 * zurueck in die To-do-Liste ziehen. Begonnene und abgeschlossene Arbeiten
 * lassen sich im Kalender nicht ziehen (isMovable). Einplanen startet keine
 * Zeiterfassung; Ausplanen loescht keine.
 */
async function moveJob(event: MoveEvent): Promise<void> {
  const { job } = event
  if (!auth.isPlanner || !isMovable(job)) return

  if (event.kind === 'unschedule') {
    jobs.value = jobs.value.filter((item) => item.id !== job.id)
    todos.value = [{ ...job, scheduled: false, startsAt: null, endsAt: null }, ...todos.value]
    try {
      await jobsApi.unschedule(job.id)
      toast(`„${job.title}“ ist wieder in der To-do-Liste.`, 'success')
    } catch (e) {
      toast(errorMessage(e), 'error')
    }
    await load()
    return
  }

  const { startsAt, assigneeId } = event
  const wasScheduled = job.scheduled
  const endsAt = new Date(startsAt.getTime() + jobDurationMs(job))
  const changeAssignee = assigneeId !== (job.assignee?.id ?? null)
  const moved: Job = {
    ...job,
    scheduled: true,
    startsAt: startsAt.toISOString(),
    endsAt: endsAt.toISOString(),
    assignee: changeAssignee
      ? (workerRefs.value.find((w) => w.id === assigneeId) ?? null)
      : job.assignee,
  }

  // Sofort anzeigen, danach mit dem Server abgleichen.
  jobs.value = [...jobs.value.filter((item) => item.id !== job.id), moved]
  todos.value = todos.value.filter((item) => item.id !== job.id)

  try {
    await jobsApi.schedule(job.id, {
      startsAt: moved.startsAt!,
      // Beim ersten Einplanen technischer Standardblock, beim Verschieben bleibt die Laenge.
      endsAt: wasScheduled ? moved.endsAt : null,
      assigneeId,
      changeAssignee,
    })
    const clash = findConflicts(jobs.value).get(job.id)
    if (clash?.length)
      toast(`Gespeichert – aber Überschneidung mit „${clash.join('“, „')}“.`, 'warning')
    else
      toast(
        `„${job.title}“ ${wasScheduled ? 'verschoben' : 'eingeplant'}: ${startsAt.toLocaleDateString('de-AT', { weekday: 'short', day: '2-digit', month: '2-digit' })}, ${formatTime(startsAt)} Uhr.`,
        'success',
      )
  } catch (e) {
    toast(errorMessage(e), 'error')
  }
  await load()
}

const showRightPanel = computed(() => isDesktop.value && mode.value !== 'monat' && auth.isPlanner)
</script>

<template>
  <div class="planning">
    <DragGhost />
    <header class="toolbar">
      <button
        class="btn btn--outline toolbar__todo"
        :aria-expanded="showSidebar"
        aria-controls="todo-sidebar"
        @click="toggleTodo"
      >
        <AppIcon name="list" :size="16" />
        <span class="toolbar__todo-label">To-do</span>
        <span class="toolbar__badge">{{ todos.length }}</span>
      </button>
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
        <h1 class="toolbar__title" aria-live="polite">{{ title }}</h1>
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
          <AppIcon name="plus" :size="16" /> <span class="toolbar__new-label">Neuer Auftrag</span>
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

    <div
      class="planning__body"
      :class="{
        'planning__body--todo': isDesktop && !collapsed,
        'planning__body--rail': isDesktop && collapsed,
      }"
    >
      <!-- Desktop eingeklappt: schmale Leiste mit Zaehler -->
      <button
        v-if="isDesktop && collapsed"
        class="todo-rail"
        aria-label="To-do-Liste ausklappen"
        data-tip="To-do-Liste"
        @click="setCollapsed(false)"
      >
        <AppIcon name="chevron-right" :size="16" />
        <span class="todo-rail__count">{{ todos.length }}</span>
        <span class="todo-rail__label">To-do</span>
      </button>

      <!-- Tablet/Handy: Schublade ueber dem Kalender -->
      <div v-if="!isDesktop && drawerOpen" class="todo-backdrop" @click="drawerOpen = false" />
      <div
        v-show="showSidebar"
        id="todo-sidebar"
        class="planning__todo"
        :class="{ 'planning__todo--drawer': !isDesktop }"
      >
        <TodoSidebar
          :jobs="todos"
          :workers="auth.isPlanner ? workerRefs : []"
          :can-plan="auth.isPlanner"
          :drop-hint="dropHint"
          @open="openJob"
          @plan="planning = $event"
          @move="moveJob"
          @create="createJob()"
          @collapse="isDesktop ? setCollapsed(true) : (drawerOpen = false)"
        />
        <details v-if="showRightPanel && overview" class="planning__more">
          <summary>{{ mode === 'tag' ? 'Kapazität & Anfragen' : 'Arbeiter & Auslastung' }}</summary>
          <DaySidebar
            v-if="mode === 'tag'"
            :overview="overview"
            @assign="assignRequest"
            @open-job="openJobById"
          />
          <WeekSidebar
            v-else
            v-model:visible="visibleWorkers"
            :week-start="range.from"
            :workers="workers"
            :jobs="jobs"
          />
        </details>
      </div>

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
    </div>

    <JobDialog
      v-if="dialog"
      :job="dialog.job"
      :defaults="dialog.defaults"
      :workers="workerRefs"
      @close="dialog = null"
      @saved="onSaved"
      @deleted="closeAndReload"
      @changed="load"
    />
    <ScheduleDialog
      v-if="planning"
      :job="planning"
      :workers="workerRefs"
      :date="mode === 'tag' ? anchor : undefined"
      @close="planning = null"
      @scheduled="closeAndReload"
    />
  </div>
</template>

<style scoped>
.planning {
  --todo-width: clamp(260px, 21vw, 320px);
  display: flex;
  flex-direction: column;
  height: 100dvh;
}

.toolbar {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 12px 16px;
  padding: 12px 20px;
  border-bottom: 1px solid var(--border);
  background: var(--surface);
}

.toolbar__todo {
  gap: 8px;
}

.toolbar__badge {
  min-width: 20px;
  padding: 1px 6px;
  border-radius: 10px;
  background: var(--primary-soft);
  color: var(--primary);
  font-size: 11px;
  font-weight: 700;
}

.toolbar__nav {
  display: flex;
  flex: 1 1 320px;
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
  font-size: 17px;
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
  margin: 12px 20px 0;
}

.conflicts {
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 9px 20px;
  border-bottom: 1px solid var(--border);
  background: var(--danger-soft);
  color: var(--danger);
  font-size: 12px;
}

.conflicts .link {
  color: var(--danger);
  font-weight: 600;
}

/* ---- Layout: To-do links, Kalender rechts --------------------------------- */

.planning__body {
  position: relative;
  display: grid;
  flex: 1;
  grid-template-columns: minmax(0, 1fr);
  min-height: 0;
}

.planning__body--todo {
  grid-template-columns: var(--todo-width) minmax(0, 1fr);
}

.planning__body--rail {
  grid-template-columns: 44px minmax(0, 1fr);
}

.planning__todo {
  display: flex;
  flex-direction: column;
  min-height: 0;
  overflow: hidden;
  border-right: 1px solid var(--border);
  background: var(--sidebar);
}

.planning__todo > :first-child {
  flex: 1;
}

.planning__more {
  flex: none;
  max-height: 45%;
  overflow-y: auto;
  border-top: 1px solid var(--border);
}

.planning__more summary {
  position: sticky;
  z-index: 1;
  top: 0;
  padding: 12px 16px;
  background: var(--sidebar);
  color: var(--muted);
  font-size: 12px;
  font-weight: 600;
  cursor: pointer;
}

.planning__more summary:hover {
  color: var(--text);
}

.todo-rail {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 10px;
  padding: 14px 0;
  border: 0;
  border-right: 1px solid var(--border);
  background: var(--sidebar);
  color: var(--muted);
}

.todo-rail:hover {
  background: var(--surface-muted);
  color: var(--text);
}

.todo-rail__count {
  min-width: 22px;
  padding: 1px 6px;
  border-radius: 10px;
  background: var(--primary-soft);
  color: var(--primary);
  font-size: 11px;
  font-weight: 700;
}

.todo-rail__label {
  font-size: 12px;
  font-weight: 600;
  writing-mode: vertical-rl;
  transform: rotate(180deg);
}

.planning__calendar {
  min-width: 0;
  overflow: auto;
  background: var(--surface);
}

/* Tablet/Handy: To-do als Schublade ueber dem Kalender */
.planning__todo--drawer {
  position: fixed;
  z-index: 60;
  top: 0;
  bottom: 0;
  left: 0;
  width: min(360px, 88vw);
  border-right: 1px solid var(--border);
  box-shadow: var(--shadow-lg);
  animation: drawer-in 0.18s ease-out;
}

.todo-backdrop {
  position: fixed;
  z-index: 59;
  inset: 0;
  background: var(--overlay);
}

@keyframes drawer-in {
  from {
    transform: translateX(-24px);
    opacity: 0;
  }
}

@media (max-width: 899px) and (min-width: 700px) {
  .planning {
    height: auto;
    min-height: 100dvh;
  }

  .planning__calendar {
    max-height: calc(100dvh - 70px);
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

  .toolbar__nav {
    flex-basis: 100%;
    order: -1;
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

  .toolbar__new-label,
  .toolbar__todo-label {
    display: none;
  }

  .conflicts {
    padding: 9px 16px;
  }

  .planning__calendar {
    overflow: visible;
    background: none;
  }

  .planning__todo--drawer {
    top: auto;
    width: 100vw;
    height: 82dvh;
    border-right: 0;
    border-radius: 16px 16px 0 0;
    animation-name: sheet-in;
  }
}

@keyframes sheet-in {
  from {
    transform: translateY(24px);
    opacity: 0;
  }
}
</style>
