<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { errorMessage, http } from '@/api/client'
import type { CalendarData, WorkRequest } from '@/api/types'
import MonthGrid from '@/components/calendar/MonthGrid.vue'
import TimeGrid, { type GridColumn } from '@/components/calendar/TimeGrid.vue'
import { useWorkshopStore } from '@/stores/workshop'
import {
  addDays,
  dayKey,
  isoWeek,
  isSameDay,
  isWeekend,
  parseDay,
  shiftWorkday,
  startOfDay,
  visibleRange,
  workerColor,
  type CalendarMode,
} from '@/utils/calendar'
import { formatDuration, formatWhen, priorities, priorityColor } from '@/utils/format'

/**
 * Kalender fuer Chef und Vorarbeiter – wie Google Kalender:
 *  - Tag:   Arbeiter nebeneinander, fuer die Feinplanung
 *  - Woche: Mo–Fr nebeneinander, Arbeiter farbig unterschieden
 *  - Monat: Ueberblick, wo noch Platz fuer Kundentermine ist
 *
 * Ansicht und Datum stehen in der URL (?ansicht=woche&datum=2026-09-28),
 * damit Zurueck-Button und Lesezeichen funktionieren.
 */
const route = useRoute()
const router = useRouter()
const workshop = useWorkshopStore()

const START_HOUR = 7
const END_HOUR = 17

const data = ref<CalendarData | null>(null)
const requests = ref<WorkRequest[]>([])
const loading = ref(false)
const error = ref('')
const hiddenWorkers = ref<number[]>([])

const mode = computed<CalendarMode>(() => {
  const value = route.query.ansicht

  return value === 'tag' || value === 'monat' ? value : 'woche'
})

const anchor = computed(() => {
  const value = route.query.datum

  return typeof value === 'string' && /^\d{4}-\d{2}-\d{2}$/.test(value)
    ? parseDay(value)
    : startOfDay(new Date())
})

const range = computed(() => visibleRange(mode.value, anchor.value))

const title = computed(() => {
  const a = anchor.value

  if (mode.value === 'tag') {
    return a.toLocaleDateString('de-AT', {
      weekday: 'long',
      day: 'numeric',
      month: 'long',
      year: 'numeric',
    })
  }

  if (mode.value === 'woche') {
    const { from, to } = range.value
    const short = (d: Date) => d.toLocaleDateString('de-AT', { day: 'numeric', month: 'short' })

    return `KW ${isoWeek(from)} · ${short(from)} – ${short(to)} ${to.getFullYear()}`
  }

  return a.toLocaleDateString('de-AT', { month: 'long', year: 'numeric' })
})

function go(next: { ansicht?: CalendarMode; datum?: Date }): void {
  void router.replace({
    query: {
      ...route.query,
      ansicht: next.ansicht ?? mode.value,
      datum: dayKey(next.datum ?? anchor.value),
    },
  })
}

function step(direction: 1 | -1): void {
  const a = anchor.value

  if (mode.value === 'tag') go({ datum: shiftWorkday(a, direction) })
  else if (mode.value === 'woche') go({ datum: addDays(a, 7 * direction) })
  else go({ datum: new Date(a.getFullYear(), a.getMonth() + direction, 1) })
}

function today(): void {
  const now = startOfDay(new Date())
  go({ datum: mode.value === 'tag' && isWeekend(now) ? shiftWorkday(now, 1) : now })
}

async function load(): Promise<void> {
  loading.value = true
  error.value = ''

  try {
    const [calendar, open] = await Promise.all([
      http.get<CalendarData>('/api/calendar', {
        params: { from: dayKey(range.value.from), to: dayKey(range.value.to) },
      }),
      http.get<WorkRequest[]>('/api/work-requests', { params: { all: true } }),
      workshop.loadOverview(),
    ])
    data.value = calendar.data
    requests.value = open.data
  } catch (e) {
    error.value = errorMessage(e)
  } finally {
    loading.value = false
  }
}

watch(() => [mode.value, dayKey(range.value.from), dayKey(range.value.to)], load, {
  immediate: true,
})

// --- Farben und Filter ---------------------------------------------------

const workerOrder = computed(() => data.value?.workers.map((w) => w.id) ?? [])
const colorOf = (id: number) => workerColor(id, workerOrder.value)

const visibleWorkers = computed(() =>
  (data.value?.workers ?? []).filter((w) => !hiddenWorkers.value.includes(w.id)),
)

const visibleSegments = computed(() =>
  (data.value?.segments ?? []).filter((s) => !hiddenWorkers.value.includes(s.workerId)),
)

function toggleWorker(id: number): void {
  hiddenWorkers.value = hiddenWorkers.value.includes(id)
    ? hiddenWorkers.value.filter((w) => w !== id)
    : [...hiddenWorkers.value, id]
}

// --- Tag: Spalte = Arbeiter ---------------------------------------------

const capacityByWorker = computed(() => {
  const map = new Map<number, string>()
  for (const w of workshop.overview?.workers ?? []) {
    map.set(w.user.id, `frei ${formatWhen(w.availableFrom)}`)
  }

  return map
})

const dayColumns = computed<GridColumn[]>(() =>
  visibleWorkers.value.map((w) => ({
    key: String(w.id),
    title: w.fullName,
    subtitle: capacityByWorker.value.get(w.id),
    badge: w.initials,
  })),
)

const dayItems = computed(() =>
  visibleSegments.value
    .filter((s) => isSameDay(new Date(s.start), anchor.value))
    .map((s) => ({ key: String(s.workerId), segment: s, color: priorityColor(s.priority) })),
)

// --- Woche: Spalte = Tag -------------------------------------------------

const weekColumns = computed<GridColumn[]>(() =>
  Array.from({ length: 5 }, (_, i) => {
    const date = addDays(range.value.from, i)

    return {
      key: dayKey(date),
      title: date.toLocaleDateString('de-AT', { weekday: 'short', day: 'numeric' }),
      subtitle: date.toLocaleDateString('de-AT', { month: 'long' }),
      today: isSameDay(date, new Date()),
    }
  }),
)

const weekItems = computed(() =>
  visibleSegments.value.map((s) => ({
    key: dayKey(new Date(s.start)),
    segment: s,
    color: colorOf(s.workerId),
  })),
)

const monthItems = computed(() =>
  visibleSegments.value.map((s) => ({ segment: s, color: colorOf(s.workerId) })),
)

const nowColumns = computed(() => {
  const now = new Date()

  if (mode.value === 'tag')
    return isSameDay(anchor.value, now) ? dayColumns.value.map((c) => c.key) : []

  return [dayKey(now)]
})

/** Wochenauslastung je Arbeiter: eingeplante Minuten gegen die Arbeitszeit. */
const weekLoad = computed(() =>
  (data.value?.workers ?? []).map((w) => {
    const planned = (data.value?.segments ?? [])
      .filter((s) => s.workerId === w.id)
      .reduce(
        (sum, s) => sum + (new Date(s.end).getTime() - new Date(s.start).getTime()) / 60_000,
        0,
      )
    const capacity = w.dailyMinutes * 5

    return {
      ...w,
      planned,
      capacity,
      percent: Math.min(100, Math.round((planned / capacity) * 100)),
    }
  }),
)

// --- Navigation zu Arbeiten ----------------------------------------------

function openJob(jobId: number): void {
  void router.push({ path: '/arbeiten', query: { bearbeiten: jobId } })
}

function newJob(query: Record<string, string> = {}): void {
  void router.push({ path: '/arbeiten', query: { neu: '1', ...query } })
}

/** Klick auf freie Stelle im Raster: neue Arbeit mit Arbeiter und Uhrzeit vorbelegen. */
function onSlot(columnKey: string, minutes: number): void {
  const time = `${String(Math.floor(minutes / 60)).padStart(2, '0')}:${String(minutes % 60).padStart(2, '0')}`

  if (mode.value === 'tag') {
    newJob({ arbeiter: columnKey, beginn: `${dayKey(anchor.value)}T${time}` })
  } else {
    newJob({ beginn: `${columnKey}T${time}` })
  }
}
</script>

<template>
  <div class="calendar">
    <header class="toolbar">
      <div class="nav">
        <button type="button" class="secondary icon" aria-label="Zurück" @click="step(-1)">
          ‹
        </button>
        <button type="button" class="secondary icon" aria-label="Weiter" @click="step(1)">›</button>
        <button type="button" class="secondary" @click="today">Heute</button>
        <h1>{{ title }}</h1>
        <span v-if="loading" class="muted small">lädt …</span>
      </div>

      <div class="modes" role="tablist" aria-label="Ansicht">
        <button
          v-for="m in ['tag', 'woche', 'monat'] as const"
          :key="m"
          type="button"
          role="tab"
          :aria-selected="mode === m"
          :class="{ active: mode === m }"
          @click="go({ ansicht: m })"
        >
          {{ m === 'tag' ? 'Tag' : m === 'woche' ? 'Woche' : 'Monat' }}
        </button>
      </div>

      <button type="button" @click="newJob()">+ Neue Arbeit</button>
    </header>

    <p v-if="error" class="error">{{ error }}</p>

    <div class="layout">
      <section class="board">
        <p v-if="data && data.workers.length === 0" class="empty muted">
          Es gibt noch keine aktiven Arbeiter. Lege sie unter „Benutzer“ an.
        </p>

        <TimeGrid
          v-else-if="mode === 'tag'"
          :columns="dayColumns"
          :items="dayItems"
          :start-hour="START_HOUR"
          :end-hour="END_HOUR"
          :now-columns="nowColumns"
          :show-worker="false"
          @open="openJob"
          @slot="onSlot"
        />

        <TimeGrid
          v-else-if="mode === 'woche'"
          :columns="weekColumns"
          :items="weekItems"
          :start-hour="START_HOUR"
          :end-hour="END_HOUR"
          :now-columns="nowColumns"
          :show-worker="true"
          @open="openJob"
          @slot="onSlot"
        />

        <MonthGrid
          v-else
          :from="range.from"
          :month="anchor.getMonth()"
          :items="monthItems"
          :worker-count="visibleWorkers.length"
          @open="openJob"
          @day="(d) => go({ ansicht: 'tag', datum: isWeekend(d) ? shiftWorkday(d, 1) : d })"
        />
      </section>

      <aside class="side">
        <section v-if="mode !== 'tag'">
          <h2>Arbeiter anzeigen</h2>
          <label v-for="w in data?.workers ?? []" :key="w.id" class="check">
            <input
              type="checkbox"
              :checked="!hiddenWorkers.includes(w.id)"
              @change="toggleWorker(w.id)"
            />
            <span class="swatch" :style="{ background: colorOf(w.id) }"></span>
            {{ w.fullName }}
          </label>
        </section>

        <section>
          <h2>Wer braucht wann Arbeit?</h2>
          <ul class="list">
            <li
              v-for="w in workshop.overview?.workers ?? []"
              :key="w.user.id"
              class="capacity"
              :class="{ soon: w.availableToday }"
            >
              <strong>{{ w.user.fullName }}</strong>
              <span>frei {{ formatWhen(w.availableFrom) }}</span>
              <span class="muted small">
                noch {{ formatDuration(w.remainingMinutes) }} Arbeit
                <template v-if="w.overrunJobs"> · {{ w.overrunJobs }}× Zeit überschritten</template>
              </span>
            </li>
          </ul>
        </section>

        <section v-if="mode === 'woche'">
          <h2>Wochenauslastung</h2>
          <div v-for="w in weekLoad" :key="w.id" class="load">
            <span class="load__label">
              {{ w.fullName }}
              <span class="muted"
                >{{ formatDuration(w.planned) }} / {{ formatDuration(w.capacity) }}</span
              >
            </span>
            <span class="bar"
              ><span :style="{ width: `${w.percent}%`, background: colorOf(w.id) }"></span
            ></span>
          </div>
        </section>

        <section>
          <h2>Offene Anfragen</h2>
          <ul v-if="requests.length" class="list">
            <li v-for="r in requests" :key="r.id" class="request">
              <strong>{{ r.user.fullName }}</strong>
              <span>braucht Arbeit {{ formatWhen(r.neededAt) }}</span>
              <span v-if="r.note" class="muted small">{{ r.note }}</span>
              <button
                type="button"
                class="small"
                @click="newJob({ arbeiter: String(r.user.id), beginn: r.neededAt.slice(0, 16) })"
              >
                Arbeit zuteilen
              </button>
            </li>
          </ul>
          <p v-else class="muted small">Zurzeit hat niemand Arbeit angefordert.</p>
        </section>

        <section v-if="data?.unscheduled.length">
          <h2>Noch nicht eingeplant</h2>
          <ul class="list">
            <li v-for="job in data.unscheduled" :key="job.jobId" class="unscheduled">
              <button type="button" class="link" @click="openJob(job.jobId)">
                <span class="dot" :style="{ background: priorityColor(job.priority) }"></span>
                {{ job.title }}
              </button>
              <span class="muted small">
                {{ job.customer ?? 'Ohne Kunde' }} · {{ formatDuration(job.plannedMinutes) }} ·
                {{ job.assignee ? 'ohne Beginn' : 'ohne Arbeiter' }}
              </span>
            </li>
          </ul>
        </section>

        <section>
          <h2>Legende</h2>
          <div class="legend">
            <span v-for="p in priorities" :key="p.value">
              <span class="dot" :style="{ background: p.color }"></span>{{ p.label }}
            </span>
          </div>
          <p class="muted small">
            Rot umrandet: Zeit überschritten, überfällig oder nicht bis zum geplanten Ende fertig.
            Klick auf eine freie Stelle plant dort eine neue Arbeit ein.
          </p>
        </section>
      </aside>
    </div>
  </div>
</template>

<style scoped>
.calendar {
  display: flex;
  flex-direction: column;
  height: calc(100vh - 57px);
}

.toolbar {
  display: flex;
  align-items: center;
  gap: 1rem;
  padding: 0.75rem 1.25rem;
  background: var(--surface);
  border-bottom: 1px solid var(--border);
  flex-wrap: wrap;
}

.nav {
  display: flex;
  align-items: center;
  gap: 0.4rem;
  flex: 1;
  min-width: 0;
}

.nav h1 {
  margin: 0 0 0 0.6rem;
  font-size: 1.125rem;
  white-space: nowrap;
}

.icon {
  width: 36px;
  padding: 0.4rem 0;
  font-size: 1.1rem;
  line-height: 1;
}

.modes {
  display: flex;
  background: var(--bg);
  border-radius: 8px;
  padding: 3px;
}

.modes button {
  background: transparent;
  color: var(--muted);
  padding: 0.35rem 0.9rem;
  border-radius: 6px;
}

.modes button.active {
  background: var(--surface);
  color: var(--text);
  box-shadow: var(--shadow);
}

.error {
  margin: 0.75rem 1.25rem 0;
}

.layout {
  flex: 1;
  display: grid;
  grid-template-columns: 1fr 320px;
  min-height: 0;
}

.board {
  overflow: auto;
  background: var(--surface);
}

.empty {
  padding: 2rem;
}

.side {
  border-left: 1px solid var(--border);
  overflow-y: auto;
  padding: 1rem;
  display: flex;
  flex-direction: column;
  gap: 1.4rem;
}

.side h2 {
  font-size: 0.875rem;
  margin-bottom: 0.6rem;
}

.check {
  display: flex;
  align-items: center;
  gap: 0.5rem;
  font-size: 0.8125rem;
  font-weight: 500;
  color: var(--text);
  margin-bottom: 0.35rem;
  cursor: pointer;
}

.check input {
  width: auto;
}

.swatch {
  width: 12px;
  height: 12px;
  border-radius: 3px;
}

.list {
  list-style: none;
  margin: 0;
  padding: 0;
  display: flex;
  flex-direction: column;
  gap: 0.5rem;
}

.list li {
  display: flex;
  flex-direction: column;
  gap: 0.1rem;
  padding: 0.6rem 0.75rem;
  border-radius: 8px;
  background: var(--surface);
  border: 1px solid var(--border);
  border-left: 3px solid var(--primary);
  font-size: 0.8125rem;
}

.capacity.soon {
  border-left-color: var(--danger);
}

.capacity.soon span:nth-child(2) {
  color: var(--danger);
  font-weight: 600;
}

.request {
  border-left-color: var(--warning) !important;
}

.request button {
  align-self: flex-start;
  margin-top: 0.35rem;
}

.unscheduled {
  border-left-color: var(--muted) !important;
}

.link {
  display: flex;
  align-items: center;
  gap: 0.4rem;
  background: none;
  color: var(--text);
  padding: 0;
  text-align: left;
}

.link:hover {
  color: var(--primary);
}

.load {
  margin-bottom: 0.6rem;
}

.load__label {
  display: flex;
  justify-content: space-between;
  font-size: 0.8125rem;
  margin-bottom: 0.25rem;
}

.load__label .muted {
  font-size: 0.75rem;
}

.bar {
  display: block;
  height: 6px;
  background: var(--bg);
  border-radius: 999px;
  overflow: hidden;
}

.bar span {
  display: block;
  height: 100%;
  border-radius: 999px;
}

.legend {
  display: flex;
  flex-wrap: wrap;
  gap: 0.4rem 0.9rem;
  font-size: 0.8125rem;
  margin-bottom: 0.5rem;
}

.legend .dot {
  margin-right: 0.35rem;
}

.small {
  font-size: 0.75rem;
}

button.small {
  padding: 0.25rem 0.6rem;
}

@media (max-width: 1000px) {
  .calendar {
    height: auto;
  }

  .layout {
    grid-template-columns: 1fr;
  }

  .board {
    max-height: 75vh;
  }

  .side {
    border-left: none;
    border-top: 1px solid var(--border);
  }
}
</style>
