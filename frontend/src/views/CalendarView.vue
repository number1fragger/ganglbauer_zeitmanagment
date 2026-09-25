<script setup>
import { computed, onBeforeUnmount, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { api } from '@/api/client'
import MonthGrid from '@/components/calendar/MonthGrid.vue'
import TimeGrid from '@/components/calendar/TimeGrid.vue'
import { useJobDialogStore } from '@/stores/jobDialog'
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
} from '@/utils/calendar'
import { formatDateTime, formatHours, formatWeekdayTime, priorities } from '@/utils/format'

/**
 * Kalender fuer Chef und Vorarbeiter – Tag, Woche und Monat.
 * Ansicht und Datum stehen in der URL (?ansicht=woche&datum=2026-09-28).
 */
const route = useRoute()
const router = useRouter()
const dialog = useJobDialogStore()

const START_HOUR = 7
const END_HOUR = 17
const isPhone = window.matchMedia('(max-width: 768px)').matches

const data = ref(null)
const overview = ref(null)
const requests = ref([])
const loading = ref(false)
const error = ref('')
const hiddenWorkers = ref([])
const pendingRequest = ref(null)
const toast = ref(null)

// --- Ansicht und Datum aus der URL -------------------------------------------

const mode = computed(() => {
  const value = route.query.ansicht
  if (['tag', 'woche', 'monat'].includes(value)) return value
  return isPhone ? 'tag' : 'woche'
})

const anchor = computed(() => {
  const value = route.query.datum
  return /^\d{4}-\d{2}-\d{2}$/.test(value ?? '') ? parseDay(value) : startOfDay(new Date())
})

const range = computed(() => visibleRange(mode.value, anchor.value))

const title = computed(() => {
  const a = anchor.value
  if (mode.value === 'tag') {
    return a.toLocaleDateString('de-AT', {
      weekday: isPhone ? 'short' : 'long',
      day: 'numeric',
      month: isPhone ? 'short' : 'long',
      year: isPhone ? undefined : 'numeric',
    })
  }
  if (mode.value === 'woche') {
    const { from, to } = range.value
    const fmt = (d, year) =>
      d.toLocaleDateString('de-AT', {
        day: 'numeric',
        month: 'short',
        year: year ? 'numeric' : undefined,
      })
    return isPhone
      ? `KW ${isoWeek(from)}`
      : `KW ${isoWeek(from)}  ·  ${fmt(from)} – ${fmt(to, true)}`
  }
  return a.toLocaleDateString('de-AT', { month: 'long', year: 'numeric' })
})

function go({ ansicht = mode.value, datum = anchor.value } = {}) {
  router.replace({ query: { ansicht, datum: dayKey(datum) } })
}

function step(direction) {
  const a = anchor.value
  if (mode.value === 'tag') go({ datum: shiftWorkday(a, direction) })
  else if (mode.value === 'woche') go({ datum: addDays(a, 7 * direction) })
  else go({ datum: new Date(a.getFullYear(), a.getMonth() + direction, 1) })
}

function today() {
  const now = startOfDay(new Date())
  go({ datum: mode.value === 'tag' && isWeekend(now) ? shiftWorkday(now, 1) : now })
}

function openDay(date) {
  go({ ansicht: 'tag', datum: isWeekend(date) ? shiftWorkday(date, 1) : date })
}

// --- Daten ---------------------------------------------------------------------

async function load() {
  loading.value = true
  error.value = ''
  try {
    ;[data.value, overview.value, requests.value] = await Promise.all([
      api.get('/api/calendar', { from: dayKey(range.value.from), to: dayKey(range.value.to) }),
      api.get('/api/overview'),
      api.get('/api/work-requests', { all: 1 }),
    ])
  } catch (e) {
    error.value = e.message
  } finally {
    loading.value = false
  }
}

watch(() => [mode.value, dayKey(range.value.from), dayKey(range.value.to)], load, {
  immediate: true,
})

// Nach Aenderungen im Dialog neu laden – und eine "zuteilen"-Anfrage abschliessen.
watch(
  () => dialog.version,
  async () => {
    if (pendingRequest.value) {
      await api
        .put(`/api/work-requests/${pendingRequest.value}/status`, { status: 'zugeteilt' })
        .catch(() => {})
      pendingRequest.value = null
    }
    load()
  },
)
watch(
  () => dialog.open,
  (open) => !open && setTimeout(() => (pendingRequest.value = null)),
)

// --- Arbeiter, Farben, Filter ------------------------------------------------

const workerOrder = computed(() => data.value?.workers.map((w) => w.id) ?? [])
const colors = (id) => workerColor(id, workerOrder.value)
const visibleWorkers = computed(() =>
  (data.value?.workers ?? []).filter((w) => !hiddenWorkers.value.includes(w.id)),
)
const visibleSegments = computed(() =>
  (data.value?.segments ?? []).filter((s) => !hiddenWorkers.value.includes(s.workerId)),
)

function toggleWorker(id) {
  hiddenWorkers.value = hiddenWorkers.value.includes(id)
    ? hiddenWorkers.value.filter((w) => w !== id)
    : [...hiddenWorkers.value, id]
}

const availability = computed(
  () => new Map((overview.value?.workers ?? []).map((w) => [w.user.id, w.availableFrom])),
)

const plannedMinutes = (segments) =>
  segments.reduce((sum, s) => sum + (new Date(s.end) - new Date(s.start)) / 60_000, 0)

// --- Tag: Spalte = Arbeiter ----------------------------------------------------

const dayColumns = computed(() =>
  visibleWorkers.value.map((w) => ({
    key: String(w.id),
    title: w.fullName,
    subtitle: `frei ab ${formatWeekdayTime(availability.value.get(w.id), true)}`,
    badge: w.initials,
  })),
)

const dayItems = computed(() =>
  visibleSegments.value
    .filter((s) => isSameDay(new Date(s.start), anchor.value))
    .map((s) => ({ key: String(s.workerId), segment: s, ...colors(s.workerId) })),
)

// --- Woche: Spalte = Tag -------------------------------------------------------

const weekColumns = computed(() =>
  Array.from({ length: 5 }, (_, i) => {
    const date = addDays(range.value.from, i)
    const key = dayKey(date)
    // Wer hat an diesem Tag noch mindestens 30 Minuten frei?
    const free = visibleWorkers.value.filter(
      (w) =>
        w.dailyMinutes -
          plannedMinutes(
            visibleSegments.value.filter(
              (s) => s.workerId === w.id && dayKey(new Date(s.start)) === key,
            ),
          ) >=
        30,
    ).length

    return {
      key,
      title: String(date.getDate()),
      subtitle: date.toLocaleDateString('de-AT', { weekday: 'short' }).replace('.', ''),
      today: isSameDay(date, new Date()),
      freeNote: key >= dayKey(new Date()) && free > 0 ? `frei: ${free} Arbeiter` : undefined,
    }
  }),
)

const weekItems = computed(() =>
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
  if (mode.value === 'tag')
    return isSameDay(anchor.value, new Date()) ? dayColumns.value.map((c) => c.key) : []
  return [dayKey(new Date())]
})

// --- Seitenleiste --------------------------------------------------------------

const weekLoad = computed(() =>
  (data.value?.workers ?? []).map((w) => {
    const planned = plannedMinutes((data.value?.segments ?? []).filter((s) => s.workerId === w.id))
    const capacity = w.dailyMinutes * 5
    return { ...w, planned, capacity, percent: Math.min(100, (planned / capacity) * 100) }
  }),
)

const capacity = computed(() =>
  (overview.value?.workers ?? []).map((w) => ({
    ...w,
    urgent: new Date(w.availableFrom) - Date.now() < 24 * 3_600_000,
  })),
)

const needsWorkSoon = computed(() => capacity.value.filter((w) => w.urgent))

/** Offene Arbeiten mit ueberschrittener Zeit (F6). */
const warnings = computed(() => {
  const seen = new Set()
  return (data.value?.segments ?? []).filter(
    (s) => s.overrun && !s.done && !seen.has(s.jobId) && seen.add(s.jobId),
  )
})

function requestLabel(r) {
  const d = new Date(r.neededAt)
  const days = Math.round((startOfDay(d) - startOfDay(new Date())) / 86_400_000)
  const part = d.getHours() < 12 ? 'früh' : 'Nachmittag'
  if (days === 1) return `morgen ${part}`
  if (days === 2) return `übermorgen ${part}`
  return formatWeekdayTime(r.neededAt)
}

// --- Anlegen, Zuteilen, Verschieben ------------------------------------------------

const clock = (minutes) =>
  `${String(Math.floor(minutes / 60)).padStart(2, '0')}:${String(minutes % 60).padStart(2, '0')}`

function onSlot(columnKey, minutes) {
  if (mode.value === 'tag') {
    dialog.create({
      assigneeId: Number(columnKey),
      startsAt: `${dayKey(anchor.value)}T${clock(minutes)}`,
    })
  } else {
    dialog.create({ startsAt: `${columnKey}T${clock(minutes)}` })
  }
}

function assign(request) {
  const needed = new Date(request.neededAt)
  dialog.create({
    assigneeId: request.user.id,
    startsAt: `${dayKey(needed)}T${needed.toTimeString().slice(0, 5)}`,
  })
  pendingRequest.value = request.id
}

/**
 * Drag & Drop: Der gezogene Abschnitt landet am Ziel. Die ganze Arbeit
 * verschiebt sich um denselben Betrag; in der Tagesansicht kann auch der
 * Arbeiter wechseln. Das Backend legt die Arbeit danach neu auf die Arbeitszeit.
 */
async function onMove(segment, target) {
  const from = new Date(segment.start)
  let to
  let assigneeId

  if (target.day) {
    to = parseDay(target.day)
    to.setHours(from.getHours(), from.getMinutes())
  } else {
    to = mode.value === 'tag' ? new Date(anchor.value) : parseDay(target.columnKey)
    to.setHours(0, target.minutes)
    if (mode.value === 'tag' && Number(target.columnKey) !== segment.workerId)
      assigneeId = Number(target.columnKey)
  }

  const shift = to - from
  if (shift === 0 && assigneeId === undefined) return

  const startsAt = new Date(new Date(segment.startsAt).getTime() + shift).toISOString()
  const undo = { startsAt: segment.startsAt, assigneeId: segment.workerId }

  try {
    await api.post(`/api/jobs/${segment.jobId}/move`, {
      startsAt,
      ...(assigneeId ? { assigneeId } : {}),
    })
    const who = assigneeId
      ? ` · ${data.value.workers.find((w) => w.id === assigneeId)?.fullName}`
      : ''
    showToast(`„${segment.title}“ → ${formatDateTime(to.toISOString())}${who}`, async () => {
      await api.post(`/api/jobs/${segment.jobId}/move`, undo)
      load()
    })
  } catch (e) {
    error.value = e.message
  }
  load()
}

let toastTimer
function showToast(text, undo) {
  clearTimeout(toastTimer)
  toast.value = { text, undo }
  toastTimer = setTimeout(() => (toast.value = null), 7000)
}

async function undoMove() {
  const { undo } = toast.value
  toast.value = null
  await undo().catch((e) => (error.value = e.message))
}

onBeforeUnmount(() => clearTimeout(toastTimer))
</script>

<template>
  <Teleport defer to="#header-center">
    <div class="controls">
      <button type="button" class="nav-btn" aria-label="Zurück" @click="step(-1)">‹</button>
      <button type="button" class="nav-btn" aria-label="Weiter" @click="step(1)">›</button>
      <button v-if="mode !== 'tag' || isPhone" type="button" class="today-btn" @click="today">
        Heute
      </button>
      <h1 class="date" :class="{ loading }">{{ title }}</h1>

      <div class="modes" role="tablist" aria-label="Ansicht">
        <button
          v-for="[value, label] in [
            ['tag', 'Tag'],
            ['woche', 'Woche'],
            ['monat', 'Monat'],
          ]"
          :key="value"
          type="button"
          role="tab"
          :aria-selected="mode === value"
          :class="{ active: mode === value }"
          @click="go({ ansicht: value })"
        >
          {{ label }}
        </button>
      </div>
    </div>
  </Teleport>

  <p v-if="error" class="error banner">{{ error }}</p>

  <div class="layout" :class="{ full: mode === 'monat' }">
    <section class="board" data-scroll :class="mode">
      <p v-if="data && !data.workers.length" class="empty">
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
        @move="onMove"
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
        @move="onMove"
      />

      <MonthGrid
        v-else
        :from="range.from"
        :month="anchor.getMonth()"
        :items="monthItems"
        @open="dialog.edit"
        @day="openDay"
        @move="onMove"
      />
    </section>

    <!-- Tag: Kapazitaet, Anfragen, Warnungen -->
    <aside v-if="mode === 'tag'" class="side">
      <h2>Kapazität</h2>
      <p class="hint">Wann ist welcher Arbeiter wieder frei?</p>
      <div v-for="w in capacity" :key="w.user.id" class="cap" :class="{ urgent: w.urgent }">
        <strong>{{ w.user.fullName }}</strong>
        <span class="cap-when">braucht Arbeit: {{ formatWeekdayTime(w.availableFrom) }}</span>
        <span class="cap-rest">
          {{
            w.remainingMinutes
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
      <p v-if="!requests.length" class="none">Zurzeit hat niemand Arbeit angefordert.</p>

      <template v-if="warnings.length">
        <h2 class="gap">Warnungen</h2>
        <button
          v-for="s in warnings"
          :key="s.jobId"
          type="button"
          class="warn"
          @click="dialog.edit(s.jobId)"
        >
          <span class="warn-icon">!</span>
          <span>
            <strong>Zeit überschritten</strong>
            <small
              >{{ s.title }} · {{ s.assignee?.fullName }} · +{{
                formatHours(s.actualMinutes - s.plannedMinutes)
              }}</small
            >
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
      <p class="tip">
        Tipp: Arbeiten lassen sich per Drag &amp; Drop verschieben – auch auf einen anderen
        Arbeiter.
      </p>
    </aside>

    <!-- Woche: Filter und Auslastung -->
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
        >
          ✓
        </span>
        {{ w.fullName }}
      </label>

      <h2 class="gap">Wochenauslastung</h2>
      <div v-for="w in weekLoad" :key="w.id" class="load">
        <span class="load-row">
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
      <p class="tip">Tipp: Arbeiten auf einen anderen Tag oder eine andere Uhrzeit ziehen.</p>
    </aside>
  </div>

  <Transition name="toast">
    <div v-if="toast" class="toast" role="status">
      <span>{{ toast.text }}</span>
      <button type="button" @click="undoMove">Rückgängig</button>
    </div>
  </Transition>
</template>

<style scoped>
.controls {
  display: flex;
  align-items: center;
  gap: 8px;
  width: 100%;
}

.nav-btn,
.today-btn {
  padding: 0;
  background: var(--button);
  color: var(--text);
}

.nav-btn {
  width: 36px;
  font-size: 1.125rem;
}

.today-btn {
  width: 64px;
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
  opacity: 0.7;
}

.modes {
  display: flex;
  margin: 0 1.5rem 0 auto;
  padding: 3px;
  border-radius: 8px;
  background: var(--button);
}

.modes button {
  width: 67px;
  height: 30px;
  padding: 0;
  border-radius: 6px;
  background: none;
  color: var(--muted);
  font-weight: 500;
}

.modes button.active {
  background: var(--surface);
  color: var(--text);
  font-weight: 600;
}

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
  color: var(--muted);
}

/* ---------- Seitenleiste ---------- */
.side {
  overflow-y: auto;
  padding: 20px 16px 2rem;
  border-left: 1px solid var(--border);
  background: var(--side);
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
  margin-bottom: 12px;
  padding: 12px 16px;
  border-left: 3px solid var(--primary);
  border-radius: 10px;
  background: var(--surface);
}

.cap.urgent {
  border-left-color: var(--danger);
}

.cap strong,
.req strong {
  font-size: 0.75rem;
  font-weight: 600;
}

.cap-when {
  font-size: 0.6875rem;
  font-weight: 500;
  color: var(--primary);
}

.cap.urgent .cap-when {
  color: var(--danger);
}

.cap-rest {
  font-size: 0.625rem;
  color: var(--muted);
}

.req {
  padding-bottom: 14px;
  border-left: none;
  background: var(--warning-soft);
}

.req span {
  padding-right: 100px;
  font-size: 0.6875rem;
  font-weight: 500;
  color: var(--warning-text);
}

.req button {
  position: absolute;
  right: 16px;
  bottom: 12px;
  width: 96px;
  height: 22px;
  font-size: 0.625rem;
}

.none,
.tip {
  margin: 0 4px;
  font-size: 0.6875rem;
  color: var(--muted);
}

.tip {
  margin-top: 24px;
  padding: 10px 12px;
  border-radius: 8px;
  background: var(--primary-soft);
  color: var(--primary);
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

.warn-icon {
  display: grid;
  flex: none;
  place-items: center;
  width: 18px;
  height: 18px;
  border-radius: 50%;
  background: var(--danger);
  color: #fff;
  font-size: 0.6875rem;
  font-weight: 700;
}

.warn strong {
  display: block;
  font-size: 0.75rem;
  color: var(--danger);
}

.warn small {
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
  display: grid;
  place-items: center;
  width: 16px;
  height: 16px;
  border: 1.5px solid var(--border);
  border-radius: 4px;
  color: #fff;
  font-size: 0.625rem;
  font-weight: 700;
}

.load {
  margin: 16px 4px 0;
}

.load-row {
  display: flex;
  justify-content: space-between;
  font-size: 0.6875rem;
  font-weight: 500;
}

.load-row span {
  font-size: 0.625rem;
  font-weight: 400;
  color: var(--muted);
}

.bar {
  display: block;
  height: 8px;
  margin-top: 8px;
  overflow: hidden;
  border-radius: 4px;
  background: var(--grid);
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
  border-left: 3px solid var(--danger);
  border-radius: 10px;
  background: var(--danger-soft);
  font-size: 0.6875rem;
  font-weight: 500;
}

.soon strong {
  font-size: 0.75rem;
  font-weight: 600;
  color: var(--danger);
}

/* ---------- Hinweis nach dem Verschieben ---------- */
.toast {
  position: fixed;
  bottom: 24px;
  left: 50%;
  z-index: 60;
  display: flex;
  align-items: center;
  gap: 16px;
  max-width: calc(100vw - 32px);
  padding: 10px 10px 10px 18px;
  border-radius: 10px;
  background: var(--text);
  color: #fff;
  font-size: 0.75rem;
  box-shadow: 0 10px 30px rgba(16, 24, 40, 0.25);
  transform: translateX(-50%);
}

.toast button {
  height: 30px;
  background: rgba(255, 255, 255, 0.14);
}

.toast-enter-active,
.toast-leave-active {
  transition:
    opacity 0.2s,
    transform 0.2s;
}

.toast-enter-from,
.toast-leave-to {
  opacity: 0;
  transform: translate(-50%, 12px);
}

/* ---------- Tablet und Handy ---------- */
@media (max-width: 1100px) {
  .layout {
    grid-template-columns: 1fr;
    height: auto;
  }

  .board {
    height: calc(100vh - 150px);
  }

  .board.monat {
    height: auto;
  }

  .side {
    border-top: 1px solid var(--border);
    border-left: none;
  }
}

@media (max-width: 768px) {
  .controls {
    flex-wrap: wrap;
    gap: 6px;
  }

  .date {
    flex: 1;
    margin-left: 0.25rem;
    font-size: 0.875rem;
  }

  .modes {
    order: 3;
    width: 100%;
    margin: 4px 0 0;
  }

  .modes button {
    flex: 1;
  }

  .banner {
    margin: 0.75rem 1rem 0;
  }

  .board {
    height: calc(100dvh - 170px);
    scroll-snap-type: x proximity;
  }

  .side {
    padding: 20px 12px 6rem;
  }

  .toast {
    bottom: 88px;
  }
}
</style>
