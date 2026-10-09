<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { jobsApi, planningApi, requestsApi } from '@/api'
import { errorMessage } from '@/api/http'
import type { Job, Overview } from '@/api/types'
import DayCalendar from '@/components/calendar/DayCalendar.vue'
import DaySidebar from '@/components/calendar/DaySidebar.vue'
import MonthCalendar from '@/components/calendar/MonthCalendar.vue'
import WeekCalendar from '@/components/calendar/WeekCalendar.vue'
import WeekSidebar from '@/components/calendar/WeekSidebar.vue'
import JobDialog from '@/components/JobDialog.vue'
import UserMenu from '@/components/UserMenu.vue'
import {
  addDays,
  formatLongDate,
  formatMonth,
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

// Ansicht und Datum stehen in der URL, damit Neuladen und Teilen funktionieren.
const mode = computed<Mode>(() => {
  const value = route.query.ansicht
  return value === 'woche' || value === 'monat' ? value : 'tag'
})
const anchor = computed(() => fromDateKey(route.query.datum as string | undefined) ?? startOfDay(new Date()))

function show(nextMode: Mode, date: Date): void {
  void router.replace({ query: { ansicht: nextMode, datum: toDateKey(date) } })
}

function step(direction: -1 | 1): void {
  const date = anchor.value
  if (mode.value === 'tag') show('tag', addDays(date, direction))
  if (mode.value === 'woche') show('woche', addDays(date, 7 * direction))
  if (mode.value === 'monat') show('monat', new Date(date.getFullYear(), date.getMonth() + direction, 1))
}

const range = computed(() => {
  if (mode.value === 'tag') return { from: anchor.value, to: addDays(anchor.value, 1) }
  if (mode.value === 'woche') {
    const monday = startOfWeek(anchor.value)
    return { from: monday, to: addDays(monday, 7) }
  }
  const first = startOfMonth(anchor.value)
  return { from: startOfWeek(first), to: addDays(startOfWeek(new Date(first.getFullYear(), first.getMonth() + 1, 0)), 7) }
})

const title = computed(() => {
  if (mode.value === 'tag') return formatLongDate(anchor.value)
  if (mode.value === 'monat') return formatMonth(anchor.value)
  const { from } = range.value
  const friday = addDays(from, 4)
  const day = (d: Date) => d.toLocaleDateString('de-AT', { day: 'numeric', month: 'short' }).replace(/\.$/, '')
  return `KW ${isoWeek(from)} · ${day(from)} – ${day(friday)} ${friday.getFullYear()}`
})

// ---- Daten ---------------------------------------------------------------

const overview = ref<Overview | null>(null)
const jobs = ref<Job[]>([])
const error = ref('')
const visibleWorkers = ref<number[]>([])

const workers = computed(() => overview.value?.workers ?? [])
const workerRefs = computed(() => workers.value.map((w) => w.user))

async function load(): Promise<void> {
  error.value = ''
  try {
    const [nextOverview, nextJobs] = await Promise.all([
      planningApi.overview(),
      jobsApi.inRange(range.value.from, range.value.to),
    ])
    if (!overview.value) visibleWorkers.value = nextOverview.workers.map((w) => w.user.id)
    overview.value = nextOverview
    jobs.value = nextJobs
  } catch (e) {
    error.value = errorMessage(e)
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
</script>

<template>
  <div class="planning">
    <header class="planning__header">
      <div class="planning__brand">
        <h1 class="page-title">Werkstatt-Planung</h1>
        <p class="page-sub">Kundentermine &amp; Arbeitseinteilung</p>
      </div>

      <nav class="planning__nav" aria-label="Zeitraum">
        <button class="btn btn--icon" aria-label="Zurück" @click="step(-1)">&lt;</button>
        <button class="btn btn--icon" aria-label="Weiter" @click="step(1)">&gt;</button>
        <button class="btn" @click="show(mode, startOfDay(new Date()))">Heute</button>
        <h2 class="planning__title">{{ title }}</h2>
      </nav>

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

      <div class="planning__actions">
        <UserMenu />
        <RouterLink class="btn planning__report" :to="{ name: 'report' }">Auswertung</RouterLink>
        <button class="btn btn--primary planning__new" @click="createJob()">+ Neue Arbeit</button>
      </div>
    </header>

    <p v-if="error" class="form-error planning__error" role="alert">
      {{ error }} <button class="link" @click="load">Erneut laden</button>
    </p>

    <div class="planning__body" :class="`planning__body--${mode}`">
      <section class="planning__calendar" aria-label="Kalender">
        <DayCalendar
          v-if="mode === 'tag'"
          :day="anchor"
          :workers="workers"
          :jobs="jobs"
          @open="openJob"
          @create="createJob"
        />
        <WeekCalendar
          v-else-if="mode === 'woche'"
          :week-start="range.from"
          :workers="workers"
          :visible="visibleWorkers"
          :jobs="jobs"
          @open="openJob"
          @create="createJob"
        />
        <MonthCalendar
          v-else
          :month="anchor"
          :workers="workers"
          :jobs="jobs"
          @open="openJob"
          @show-day="show('tag', $event)"
        />
      </section>

      <DaySidebar
        v-if="mode === 'tag' && overview"
        class="planning__sidebar"
        :overview="overview"
        @assign="assignRequest"
        @open-job="openJobById"
      />
      <WeekSidebar
        v-else-if="mode === 'woche'"
        v-model:visible="visibleWorkers"
        class="planning__sidebar"
        :week-start="range.from"
        :workers="workers"
        :jobs="jobs"
      />
    </div>

    <JobDialog
      v-if="dialog"
      :job="dialog.job"
      :defaults="dialog.defaults"
      :workers="workerRefs"
      @close="dialog = null"
      @saved="onSaved"
      @deleted="onDeleted"
    />
  </div>
</template>

<style scoped>
.planning {
  display: flex;
  flex-direction: column;
  height: 100vh;
}

.planning__header {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 16px 24px;
  padding: 16px 24px;
  border-bottom: 1px solid var(--border);
  background: var(--surface);
}

.planning__brand {
  min-width: 220px;
}

.planning__nav {
  display: flex;
  align-items: center;
  gap: 8px;
  min-width: 360px;
}

.planning__title {
  margin-left: 8px;
  font-size: 15px;
  font-weight: 600;
  white-space: nowrap;
}

.planning__actions {
  display: flex;
  gap: 14px;
  margin-left: auto;
}

.planning__report {
  min-width: 150px;
}

.planning__new {
  min-width: 152px;
}

.planning__error {
  margin: 12px 24px 0;
}

.planning__body {
  display: grid;
  flex: 1;
  grid-template-columns: minmax(0, 1fr) minmax(320px, 464px);
  min-height: 0;
}

.planning__body--woche {
  grid-template-columns: minmax(0, 1fr) minmax(300px, 406px);
}

.planning__body--monat {
  grid-template-columns: minmax(0, 1fr);
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

@media (max-width: 1100px) {
  .planning {
    height: auto;
  }

  .planning__body,
  .planning__body--woche {
    grid-template-columns: minmax(0, 1fr);
  }

  .planning__sidebar {
    border-top: 1px solid var(--border);
    border-left: 0;
  }

  .planning__nav {
    min-width: 0;
  }
}
</style>
