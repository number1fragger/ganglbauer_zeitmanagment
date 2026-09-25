<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { errorMessage, http } from '@/api/client'
import type { CalendarData, WorkRequest } from '@/api/types'
import MonthGrid from '@/components/calendar/MonthGrid.vue'
import TimeGrid, { type GridColumn, type GridItem } from '@/components/calendar/TimeGrid.vue'
import { useJobDialogStore } from '@/stores/jobDialog'
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
import { formatHours, formatWeekdayTime, priorities } from '@/utils/format'

/**
 * Kalender fuer Chef und Vorarbeiter (Figma 01a/01b/01c).
 * Ansicht und Datum stehen in der URL (?ansicht=woche&datum=2026-09-28).
 */
const route = useRoute()
const router = useRouter()
const workshop = useWorkshopStore()
const dialog = useJobDialogStore()

const START_HOUR = 7
const END_HOUR = 17

const data = ref<CalendarData | null>(null)
const requests = ref<WorkRequest[]>([])
const loading = ref(false)
const error = ref('')
const hiddenWorkers = ref<number[]>([])
/** Anfrage, fuer die gerade eine Arbeit angelegt wird ("zuteilen"). */
const pendingRequest = ref<number | null>(null)

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
    const fmt = (d: Date, year = false) =>
      d.toLocaleDateString('de-AT', {
        day: 'numeric',
        month: 'short',
        ...(year ? { year: 'numeric' } : {}),
      })

    return `KW ${isoWeek(from)}  ·  ${fmt(from)} – ${fmt(to, true)}`
  }

  return a.toLocaleDateString('de-AT', { month: 'long', year: 'numeric' })
})

function go(next: { ansicht?: CalendarMode; datum?: Date }): void {
  void router.replace({
    query: { ansicht: next.ansicht ?? mode.value, datum: dayKey(next.datum ?? anchor.value) },
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

// Nach dem Speichern im Dialog neu laden – und eine "zuteilen"-Anfrage abschliessen.
watch(
  () => dialog.version,
  async () => {
    if (pendingRequest.value !== null) {
      try {
        await http.put(`/api/work-requests/${pendingRequest.value}/status`, { status: 'zugeteilt' })
      } catch (e) {
        error.value = errorMessage(e)
      }
      pendingRequest.value = null
    }
    await load()
  },
)
watch(
  () => dialog.open,
  (open) => {
    if (!open) setTimeout(() => (pendingRequest.value = null), 0)
  },
)

// --- Farben, Filter ------------------------------------------------------

const workerOrder = computed(() => data.value?.workers.map((w) => w.id) ?? [])
const colors = (id: number) => workerColor(id, workerOrder.value)

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

const availability = computed(() => {
  const map = new Map<number, string>()
  for (const w of workshop.overview?.workers ?? []) map.set(w.user.id, w.availableFrom)

  return map
})

// --- Tag -------------------------------------------------------------------

const dayColumns = computed<GridColumn[]>(() =>
  visibleWorkers.value.map((w) => ({
    key: String(w.id),
    title: w.fullName,
    subtitle: `frei ab ${formatWeekdayTime(availability.value.get(w.id) ?? null, true)}`,
    badge: w.initials,
  })),
)

const dayItems = computed<GridItem[]>(() =>
  visibleSegments.value
    .filter((s) => isSameDay(new Date(s.start), anchor.value))
    .map((s) => ({ key: String(s.workerId), segment: s, ...colors(s.workerId) })),
)

// --- Woche -----------------------------------------------------------------

const weekColumns = computed<GridColumn[]>(() =>
  Array.from({ length: 5 }, (_, i) => {
    const date = addDays(range.value.from, i)
    const key = dayKey(date)
    // Wer hat an diesem Tag noch mindestens 30 Minuten frei? -> "frei: 2 Arbeiter"
    const free = visibleWorkers.value.filter((w) => {
      const planned = visibleSegments.value
        .filter((s) => s.workerId === w.id && dayKey(new Date(s.start)) === key)
        .reduce(
          (sum, s) => sum + (new Date(s.end).getTime() - new Date(s.start).getTime()) / 60_000,
          0,
        )

      return w.dailyMinutes - planned >= 30
    }).length
    const future = key >= dayKey(new Date())

    return {
      key,
      title: String(date.getDate()),
      subtitle: date.toLocaleDateString('de-AT', { weekday: 'short' }).replace('.', ''),
      today: isSameDay(date, new Date()),
      freeNote:
        future && free > 0 ? `frei: ${free} ${free === 1 ? 'Arbeiter' : 'Arbeiter'}` : undefined,
    }
  }),
)

const weekItems = computed<GridItem[]>(() =>
  visibleSegments.value.map((s) => ({
    key: dayKey(new Date(s.start)),
    segment: s,
    ...colors(s.workerId),
  })),
)

const monthItems = computed(() =>
  visibleSegments.value.map((s) => ({ segment: s, ...colors(s.workerId) })),
)

const nowColumns = computed(() => {
  const now = new Date()

  if (mode.value === 'tag')
    return isSameDay(anchor.value, now) ? dayColumns.value.map((c) => c.key) : []

  return [dayKey(now)]
})

/** Wochenauslastung: eingeplante gegen verfuegbare Stunden. */
const weekLoad = computed(() =>
  (data.value?.workers ?? []).map((w) => {
    const planned = (data.value?.segments ?? [])
      .filter((s) => s.workerId === w.id)
      .reduce(
        (sum, s) => sum + (new Date(s.end).getTime() - new Date(s.start).getTime()) / 60_000,
        0,
      )
    const capacity = w.dailyMinutes * 5

    return { ...w, planned, capacity, percent: Math.min(100, (planned / capacity) * 100) }
  }),
)

/** Wer braucht innerhalb des naechsten Werktags neue Arbeit? */
const needsWorkSoon = computed(() => {
  const limit = shiftWorkday(startOfDay(new Date()), 1).getTime() + 24 * 3_600_000

  return (workshop.overview?.workers ?? []).filter(
    (w) => new Date(w.availableFrom).getTime() < limit,
  )
})

// --- Seitenleiste Tag ------------------------------------------------------

const capacity = computed(() =>
  (workshop.overview?.workers ?? []).map((w) => ({
    ...w,
    urgent: new Date(w.availableFrom).getTime() - Date.now() < 24 * 3_600_000,
  })),
)

/** Offene Arbeiten mit ueberschrittener Zeit (F6). */
const warnings = computed(() => {
  const seen = new Set<number>()

  return (data.value?.segments ?? []).filter((s) => {
    if (!s.overrun || s.done || seen.has(s.jobId)) return false
    seen.add(s.jobId)

    return true
  })
})

// --- Dialog ----------------------------------------------------------------

function onSlot(columnKey: string, minutes: number): void {
  const time = `${String(Math.floor(minutes / 60)).padStart(2, '0')}:${String(minutes % 60).padStart(2, '0')}`

  if (mode.value === 'tag') {
    dialog.create({ assigneeId: Number(columnKey), startsAt: `${dayKey(anchor.value)}T${time}` })
  } else {
    dialog.create({ startsAt: `${columnKey}T${time}` })
  }
}

function assign(request: WorkRequest): void {
  const needed = new Date(request.neededAt)
  dialog.create({
    assigneeId: request.user.id,
    startsAt: `${dayKey(needed)}T${needed.toTimeString().slice(0, 5)}`,
  })
  pendingRequest.value = request.id
}

function requestLabel(r: WorkRequest): string {
  const d = new Date(r.neededAt)
  const days = Math.round((startOfDay(d).getTime() - startOfDay(new Date()).getTime()) / 86_400_000)
  const part = d.getHours() < 12 ? 'früh' : 'Nachmittag'

  if (days === 1) return `morgen ${part}`
  if (days === 2) return `übermorgen ${part}`

  return formatWeekdayTime(r.neededAt)
}
</script>

<template>
  <Teleport defer to="#header-center">
    <div class="controls">
      <button type="button" class="nav-btn" aria-label="Zurück" @click="step(-1)">&lt;</button>
      <button type="button" class="nav-btn" aria-label="Weiter" @click="step(1)">&gt;</button>
      <button v-if="mode !== 'tag'" type="button" class="today-btn" @click="today">Heute</button>
      <h1 class="date" :class="{ loading }">{{ title }}</h1>

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
    </div>
  </Teleport>

  <p v-if="error" class="error banner">{{ error }}</p>

  <div class="layout" :class="{ full: mode === 'monat' }">
    <section class="board">
      <p v-if="data && data.workers.length === 0" class="empty muted">
        Es gibt noch keine aktiven Arbeiter. Lege sie unter „Benutzer &amp; Rechte“ an.
      </p>

      <TimeGrid
        v-else-if="mode === 'tag'"
        variant="day"
        :columns="dayColumns"
        :items="dayItems"
        :start-hour="START_HOUR"
        :end-hour="END_HOUR"
        :now-columns="nowColumns"
        @open="dialog.edit"
        @slot="onSlot"
      />

      <TimeGrid
        v-else-if="mode === 'woche'"
        variant="week"
        :columns="weekColumns"
        :items="weekItems"
        :start-hour="START_HOUR"
        :end-hour="END_HOUR"
        :now-columns="nowColumns"
        @open="dialog.edit"
        @slot="onSlot"
      />

      <MonthGrid
        v-else
        :from="range.from"
        :month="anchor.getMonth()"
        :items="monthItems"
        @open="dialog.edit"
        @day="(d) => go({ ansicht: 'tag', datum: isWeekend(d) ? shiftWorkday(d, 1) : d })"
      />
    </section>

    <!-- Seitenleiste Tag (Figma 01a) -->
    <aside v-if="mode === 'tag'" class="side">
      <h2>Kapazität</h2>
      <p class="hint">Wann ist welcher Arbeiter wieder frei?</p>
      <div v-for="w in capacity" :key="w.user.id" class="cap" :class="{ urgent: w.urgent }">
        <strong>{{ w.user.fullName }}</strong>
        <span class="cap__when">braucht Arbeit: {{ formatWeekdayTime(w.availableFrom) }}</span>
        <span class="cap__rest">
          {{
            w.remainingMinutes > 0
              ? `noch ${formatHours(w.remainingMinutes)} Arbeit`
              : 'keine offene Arbeit'
          }}
        </span>
      </div>

      <h2 class="gap">Offene Anfragen</h2>
      <p class="hint">Arbeiter haben Arbeit angefordert</p>
      <div v-for="r in requests" :key="r.id" class="req">
        <strong>{{ r.user.fullName }}</strong>
        <span>braucht Arbeit: {{ requestLabel(r) }}</span>
        <button type="button" class="small" @click="assign(r)">zuteilen</button>
      </div>
      <p v-if="requests.length === 0" class="none">Zurzeit hat niemand Arbeit angefordert.</p>

      <template v-if="warnings.length">
        <h2 class="gap">Warnungen</h2>
        <button
          v-for="s in warnings"
          :key="s.jobId"
          type="button"
          class="warn"
          @click="dialog.edit(s.jobId)"
        >
          <span class="warn__icon">!</span>
          <span>
            <strong>Zeit überschritten</strong>
            <span>
              {{ s.title }} · {{ s.assignee?.fullName }} · +{{
                formatHours(s.actualMinutes - s.plannedMinutes)
              }}
            </span>
          </span>
        </button>
      </template>

      <h2 class="gap">Priorität</h2>
      <div class="legend">
        <span v-for="p in priorities" :key="p.value"
          ><i :style="{ background: p.color }"></i>{{ p.label }}</span
        >
        <span><i style="background: var(--faint)"></i>erledigt</span>
      </div>
    </aside>

    <!-- Seitenleiste Woche (Figma 01b) -->
    <aside v-else-if="mode === 'woche'" class="side">
      <h2>Arbeiter anzeigen</h2>
      <label v-for="w in data?.workers ?? []" :key="w.id" class="filter">
        <input
          type="checkbox"
          :checked="!hiddenWorkers.includes(w.id)"
          @change="toggleWorker(w.id)"
        />
        <span
          class="box"
          :style="
            hiddenWorkers.includes(w.id)
              ? {}
              : { background: colors(w.id).color, borderColor: colors(w.id).color }
          "
          >✓</span
        >
        {{ w.fullName }}
      </label>

      <h2 class="gap">Wochenauslastung</h2>
      <div v-for="w in weekLoad" :key="w.id" class="load">
        <span class="load__row">
          {{ w.fullName }}
          <span
            >{{ formatHours(w.planned).replace(' h', '') }} / {{ formatHours(w.capacity) }}</span
          >
        </span>
        <span class="bar"
          ><span :style="{ width: `${w.percent}%`, background: colors(w.id).color }"></span
        ></span>
      </div>

      <div v-if="needsWorkSoon.length" class="soon">
        <strong>Braucht bald neue Arbeit</strong>
        <span v-for="w in needsWorkSoon" :key="w.user.id">
          {{ w.user.fullName }} – ab {{ formatWeekdayTime(w.availableFrom, true) }} frei
        </span>
      </div>

      <h2 class="gap">Priorität</h2>
      <div class="legend dots">
        <span v-for="p in priorities" :key="p.value"
          ><i :style="{ background: p.color }"></i>{{ p.label }}</span
        >
      </div>
    </aside>
  </div>
</template>

<style scoped>
/* ---------- Steuerung im Header ---------- */
.controls {
  display: flex;
  align-items: center;
  gap: 8px;
  width: 100%;
}

.nav-btn {
  width: 36px;
  padding: 0;
  background: var(--button);
  color: var(--text);
  font-size: 0.875rem;
}

.today-btn {
  width: 64px;
  padding: 0;
  background: var(--button);
  color: var(--text);
  font-weight: 500;
}

.date {
  margin: 0 0 0 0.6rem;
  font-size: 0.9375rem;
  font-weight: 600;
  white-space: nowrap;
  transition: opacity 0.2s;
}

.date.loading {
  opacity: 0.8;
}

.modes {
  display: flex;
  margin: 0 1.5rem 0 auto;
  padding: 3px;
  background: var(--button);
  border-radius: 8px;
}

.modes button {
  width: 67px;
  height: 30px;
  padding: 0;
  background: none;
  color: var(--muted);
  font-weight: 500;
  border-radius: 6px;
}

.modes button.active {
  background: var(--surface);
  color: var(--text);
  font-weight: 600;
}

/* ---------- Layout ---------- */
.banner {
  margin: 0.75rem 1.5rem 0;
}

.layout {
  display: grid;
  grid-template-columns: 1fr 400px;
  height: calc(100vh - 72px);
}

.layout.full {
  grid-template-columns: 1fr;
}

.board {
  overflow: auto;
  background: var(--surface);
}

.empty {
  padding: 2rem;
}

/* ---------- Seitenleiste ---------- */
.side {
  overflow-y: auto;
  padding: 20px 16px 2rem;
  background: var(--side);
  border-left: 1px solid var(--border);
}

.side h2 {
  margin: 0 0 0 4px;
  font-size: 0.8125rem;
}

.side h2.gap {
  margin-top: 28px;
}

.hint {
  margin: 4px 0 16px 4px;
  font-size: 0.6875rem;
  color: var(--muted);
}

.cap,
.req {
  position: relative;
  display: flex;
  flex-direction: column;
  gap: 4px;
  padding: 12px 16px;
  margin-bottom: 12px;
  border-radius: 10px;
  background: var(--surface);
  border-left: 3px solid var(--primary);
}

.cap.urgent {
  border-left-color: var(--danger);
}

.cap strong,
.req strong {
  font-size: 0.75rem;
  font-weight: 600;
}

.cap__when {
  font-size: 0.6875rem;
  font-weight: 500;
  color: var(--primary);
}

.cap.urgent .cap__when {
  color: var(--danger);
}

.cap__rest {
  font-size: 0.625rem;
  color: var(--muted);
}

.req {
  background: var(--warning-soft);
  border-left: none;
  padding-bottom: 14px;
}

.req span {
  font-size: 0.6875rem;
  font-weight: 500;
  color: var(--warning-text);
  padding-right: 100px;
}

.req button {
  position: absolute;
  right: 16px;
  bottom: 12px;
  height: 20px;
  width: 96px;
  font-size: 0.625rem;
}

.none {
  margin: 0 4px;
  font-size: 0.6875rem;
  color: var(--muted);
}

.warn {
  display: flex;
  gap: 10px;
  align-items: flex-start;
  width: 100%;
  height: auto;
  margin-top: 12px;
  padding: 12px 16px;
  border-radius: 10px;
  background: var(--danger-soft);
  color: var(--text);
  text-align: left;
  white-space: normal;
}

.warn__icon {
  flex: none;
  width: 18px;
  height: 18px;
  border-radius: 50%;
  background: var(--danger);
  color: #fff;
  display: grid;
  place-items: center;
  font-size: 0.6875rem;
  font-weight: 700;
}

.warn strong {
  display: block;
  color: var(--danger);
  font-size: 0.75rem;
}

.warn span span {
  font-size: 0.625rem;
  font-weight: 400;
  color: var(--muted);
}

.legend {
  display: flex;
  flex-direction: column;
  gap: 12px;
  margin: 14px 4px 0;
  font-size: 0.6875rem;
  color: var(--muted);
}

.legend span {
  display: flex;
  align-items: center;
  gap: 8px;
}

.legend i {
  width: 10px;
  height: 10px;
  border-radius: 3px;
}

.legend.dots i {
  width: 8px;
  height: 8px;
  border-radius: 50%;
}

.filter {
  display: flex;
  align-items: center;
  gap: 10px;
  margin: 14px 4px 0;
  font-size: 0.75rem;
  font-weight: 500;
  color: var(--text);
  cursor: pointer;
}

.filter input {
  position: absolute;
  opacity: 0;
  pointer-events: none;
}

.box {
  width: 16px;
  height: 16px;
  border-radius: 4px;
  border: 1.5px solid var(--border);
  display: grid;
  place-items: center;
  color: #fff;
  font-size: 0.625rem;
  font-weight: 700;
}

.load {
  margin: 16px 4px 0;
}

.load__row {
  display: flex;
  justify-content: space-between;
  font-size: 0.6875rem;
  font-weight: 500;
}

.load__row span {
  font-size: 0.625rem;
  font-weight: 400;
  color: var(--muted);
}

.bar {
  display: block;
  height: 8px;
  margin-top: 8px;
  border-radius: 4px;
  background: var(--grid);
  overflow: hidden;
}

.bar span {
  display: block;
  height: 100%;
  border-radius: 4px;
}

.soon {
  display: flex;
  flex-direction: column;
  gap: 8px;
  margin-top: 28px;
  padding: 14px 16px;
  border-radius: 10px;
  border-left: 3px solid var(--danger);
  background: var(--danger-soft);
  font-size: 0.6875rem;
  font-weight: 500;
}

.soon strong {
  color: var(--danger);
  font-size: 0.75rem;
  font-weight: 600;
}

@media (max-width: 1100px) {
  .layout {
    grid-template-columns: 1fr;
    height: auto;
  }

  .board {
    max-height: 75vh;
  }

  .modes {
    margin-right: 0;
  }
}
</style>
