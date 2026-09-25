<script setup lang="ts">
import { computed, onBeforeUnmount, ref } from 'vue'
import type { CalendarSegment } from '@/api/types'
import { layoutLanes, minutesOfDay } from '@/utils/calendar'
import { formatHours, formatTime, priorityColor, priorityLabel } from '@/utils/format'

/**
 * Zeitraster fuer Tag (Figma 01a) und Woche (Figma 01b).
 *  - variant "day":  Spalte = Arbeiter, Karte mit Prioritaetsleiste, Kunde und Soll/Ist
 *  - variant "week": Spalte = Tag, Karte in Arbeiterfarbe mit Kuerzel und Prioritaetspunkt
 */
export interface GridColumn {
  key: string
  /** Tag: Arbeitername · Woche: Tageszahl */
  title: string
  /** Tag: "frei ab Mi 14:00" · Woche: "MO" */
  subtitle?: string
  /** Tag: Kuerzel im Kreis */
  badge?: string
  today?: boolean
  /** Woche: "frei: 2 Arbeiter" am Tagesende */
  freeNote?: string
}

export interface GridItem {
  key: string
  segment: CalendarSegment
  /** Arbeiterfarbe (Woche) */
  color: string
  /** helle Arbeiterfarbe (Woche) */
  soft: string
}

const props = defineProps<{
  variant: 'day' | 'week'
  columns: GridColumn[]
  items: GridItem[]
  startHour: number
  endHour: number
  nowColumns: string[]
}>()

const emit = defineEmits<{
  open: [jobId: number]
  slot: [columnKey: string, minutes: number]
}>()

const HOUR_PX = 58
const hours = computed(() =>
  Array.from({ length: props.endHour - props.startHour + 1 }, (_, i) => props.startHour + i),
)
const gridHeight = computed(() => (props.endHour - props.startHour) * HOUR_PX)

function top(minutes: number): number {
  const clamped = Math.min(Math.max(minutes, props.startHour * 60), props.endHour * 60)

  return ((clamped - props.startHour * 60) / 60) * HOUR_PX
}

function place(entry: { item: GridItem; lane: number; lanes: number }) {
  const y = top(minutesOfDay(new Date(entry.item.segment.start)))
  const height = Math.max(24, top(minutesOfDay(new Date(entry.item.segment.end))) - y - 6)
  const inset = props.variant === 'day' ? 8 : 3

  return {
    ...entry.item,
    height,
    // Ab vier parallelen Arbeiten ist fuer Text kein Platz – nur das Kuerzel (Details im Tooltip).
    tiny: props.variant === 'week' && entry.lanes >= 4,
    style: {
      top: `${y + 3}px`,
      height: `${height}px`,
      left: `calc(${(entry.lane / entry.lanes) * 100}% + ${inset}px)`,
      width: `calc(${100 / entry.lanes}% - ${inset * 2}px)`,
    },
  }
}

const laidOut = computed(() => {
  const byColumn = new Map<string, GridItem[]>()
  for (const item of props.items) byColumn.set(item.key, [...(byColumn.get(item.key) ?? []), item])

  const result: Record<string, ReturnType<typeof place>[]> = {}
  for (const [key, list] of byColumn) {
    result[key] = layoutLanes(list, (i) => [
      minutesOfDay(new Date(i.segment.start)),
      minutesOfDay(new Date(i.segment.end)),
    ]).map(place)
  }

  return result
})

// Rote "Jetzt"-Linie, jede Minute aktualisiert.
const now = ref(new Date())
const timer = setInterval(() => (now.value = new Date()), 60_000)
onBeforeUnmount(() => clearInterval(timer))

const nowTop = computed(() => {
  const minutes = minutesOfDay(now.value)
  if (minutes < props.startHour * 60 || minutes > props.endHour * 60) return null

  return top(minutes)
})

/** Klick auf eine freie Stelle: Uhrzeit auf halbe Stunden runden. */
function onSlotClick(event: MouseEvent, columnKey: string): void {
  const y = event.clientY - (event.currentTarget as HTMLElement).getBoundingClientRect().top
  emit('slot', columnKey, props.startHour * 60 + Math.floor((y / HOUR_PX) * 2) * 30)
}

function state(s: CalendarSegment): 'done' | 'over' | 'open' {
  if (s.done) return 'done'
  if (s.overrun || s.behind || s.late) return 'over'

  return 'open'
}

/** "Soll 2,5 h · Ist 2,0 h" – Ist nur, wenn schon gearbeitet wurde. */
function timeLine(s: CalendarSegment): string {
  const soll = `Soll ${formatHours(s.plannedMinutes)}`

  return s.actualMinutes > 0 ? `${soll} · Ist ${formatHours(s.actualMinutes)}` : soll
}

function tooltip(s: CalendarSegment): string {
  const lines = [
    s.title,
    `Kunde: ${s.customer ?? '–'}`,
    `${formatTime(s.start)} – ${formatTime(s.end)}`,
    `Priorität: ${priorityLabel(s.priority)}`,
    timeLine(s),
  ]
  if (s.parts > 1) lines.push(`Teil ${s.part} von ${s.parts}`)
  if (s.behind) lines.push('Überfällig: Plan ist vorbei, Arbeit noch nicht erledigt')
  else if (s.late) lines.push('Wird nicht bis zum geplanten Ende fertig')

  return lines.join('\n')
}
</script>

<template>
  <div
    class="tgrid"
    :class="variant"
    :style="{ '--cols': columns.length, '--hour': `${HOUR_PX}px` }"
  >
    <div class="head gutter"><span v-if="variant === 'day'">Zeit</span></div>
    <div v-for="col in columns" :key="col.key" class="head" :class="{ today: col.today }">
      <template v-if="variant === 'day'">
        <span class="avatar">{{ col.badge }}</span>
        <span class="head__text">
          <strong>{{ col.title }}</strong>
          <span>{{ col.subtitle }}</span>
        </span>
      </template>
      <template v-else>
        <span class="weekday">{{ col.subtitle }}</span>
        <span class="daynum">{{ col.title }}</span>
      </template>
    </div>

    <div class="gutter hours" :style="{ height: `${gridHeight + 12}px` }">
      <span v-for="h in hours" :key="h" :style="{ top: `${(h - startHour) * HOUR_PX}px` }">
        {{ String(h).padStart(2, '0') }}:00
      </span>
    </div>

    <div
      v-for="col in columns"
      :key="col.key"
      class="column"
      :style="{ height: `${gridHeight}px` }"
      title="Klicken, um hier eine Arbeit einzuplanen"
      @click.self="onSlotClick($event, col.key)"
    >
      <button
        v-for="item in laidOut[col.key] ?? []"
        :key="`${item.segment.jobId}-${item.segment.part}`"
        type="button"
        class="job"
        :class="state(item.segment)"
        :style="
          variant === 'week'
            ? { ...item.style, '--accent': item.color, '--fill': item.soft }
            : { ...item.style, '--accent': priorityColor(item.segment.priority) }
        "
        :title="tooltip(item.segment)"
        @click="emit('open', item.segment.jobId)"
      >
        <!-- Tagesansicht -->
        <template v-if="variant === 'day'">
          <strong class="title">
            {{ item.segment.title }}
            <span v-if="item.segment.parts > 1" class="part">
              {{ item.segment.part }}/{{ item.segment.parts }}
            </span>
          </strong>
          <span v-if="item.height > 40" class="customer"
            >Kunde: {{ item.segment.customer ?? '–' }}</span
          >
          <span v-if="item.height > 60" class="times">{{ timeLine(item.segment) }}</span>

          <span v-if="item.segment.done" class="mark mark--ok">OK</span>
          <span v-else-if="state(item.segment) === 'over'" class="mark mark--warn">!</span>
          <span v-else-if="item.segment.running" class="mark mark--run">läuft</span>
        </template>

        <!-- Wochenansicht -->
        <template v-else-if="item.tiny">
          <span v-if="item.segment.assignee" class="who who--top">{{
            item.segment.assignee.initials
          }}</span>
        </template>
        <template v-else>
          <strong class="title">{{ item.segment.title }}</strong>
          <span class="prio" :style="{ background: priorityColor(item.segment.priority) }"></span>
          <span v-if="item.height > 44 && item.segment.assignee" class="who">
            {{ item.segment.assignee.initials }}
          </span>
        </template>
      </button>

      <div v-if="col.freeNote" class="free" :style="{ top: `${gridHeight - HOUR_PX + 3}px` }">
        {{ col.freeNote }}
      </div>

      <div
        v-if="nowTop !== null && nowColumns.includes(col.key)"
        class="now"
        :style="{ top: `${nowTop}px` }"
      ></div>
    </div>
  </div>
</template>

<style scoped>
.tgrid {
  display: grid;
  grid-template-columns: 64px repeat(var(--cols), minmax(170px, 1fr));
  min-width: calc(64px + var(--cols) * 170px);
  background: var(--surface);
}

.head {
  position: sticky;
  top: 0;
  z-index: 3;
  display: flex;
  align-items: center;
  gap: 0.6rem;
  height: 68px;
  padding: 0 1rem;
  background: var(--surface);
  border-bottom: 1px solid var(--border);
  border-left: 1px solid var(--border);
}

.head.gutter {
  border-left: none;
  align-items: flex-end;
  padding: 0 0 0.6rem 1rem;
  font-size: 0.625rem;
  font-weight: 500;
  color: var(--muted);
}

.head__text {
  display: flex;
  flex-direction: column;
  min-width: 0;
  line-height: 1.35;
}

.head__text strong {
  font-size: 0.8125rem;
  font-weight: 600;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}

.head__text span {
  font-size: 0.6875rem;
  color: var(--muted);
}

/* Wochenkopf: "MO" klein, darunter die Tageszahl – heute blau im Kreis */
.week .head {
  flex-direction: column;
  align-items: flex-start;
  justify-content: center;
  gap: 0.15rem;
}

.weekday {
  font-size: 0.625rem;
  font-weight: 600;
  color: var(--muted);
  text-transform: uppercase;
}

.daynum {
  font-size: 1.125rem;
  font-weight: 600;
  line-height: 30px;
}

.head.today .weekday {
  color: var(--primary);
}

.head.today .daynum {
  background: var(--primary);
  color: #fff;
  font-weight: 700;
  font-size: 0.9375rem;
  min-width: 34px;
  height: 30px;
  padding: 0 0.4rem;
  border-radius: 15px;
  text-align: center;
}

.hours {
  position: relative;
}

.hours span {
  position: absolute;
  left: 1rem;
  transform: translateY(-6px);
  font-size: 0.625rem;
  color: var(--muted);
  font-variant-numeric: tabular-nums;
}

.column {
  position: relative;
  border-left: 1px solid var(--grid);
  background-image: repeating-linear-gradient(
    to bottom,
    var(--grid) 0,
    var(--grid) 1px,
    transparent 1px,
    transparent var(--hour)
  );
  border-bottom: 1px solid var(--grid);
  cursor: copy;
}

/* ---------- Karten ---------- */
.job {
  position: absolute;
  z-index: 1;
  display: flex;
  flex-direction: column;
  align-items: flex-start;
  text-align: left;
  height: auto;
  padding: 0.6rem 0.75rem 0.6rem 0.875rem;
  border-radius: 8px;
  border-left: 4px solid var(--accent);
  background: #f0f4fe;
  color: var(--text);
  font-weight: 400;
  overflow: hidden;
  white-space: normal;
}

.job:hover {
  z-index: 2;
  box-shadow: 0 4px 14px rgba(16, 24, 40, 0.14);
}

.job.over {
  background: var(--danger-soft);
}

.job.done {
  background: var(--button);
  border-left-color: var(--faint);
}

.title {
  font-size: 0.75rem;
  font-weight: 600;
  line-height: 1.3;
  padding-right: 1.9rem;
}

.job.done .title {
  color: var(--muted);
}

.part {
  font-weight: 500;
  color: var(--muted);
}

.customer {
  font-size: 0.625rem;
  color: var(--muted);
  margin-top: 0.15rem;
}

.times {
  margin-top: auto;
  font-size: 0.625rem;
  font-weight: 500;
  color: var(--muted);
}

.job.over .times {
  color: var(--danger);
}

.mark {
  position: absolute;
  top: 10px;
  right: 10px;
  height: 18px;
  min-width: 18px;
  border-radius: 9px;
  display: grid;
  place-items: center;
  color: #fff;
  font-size: 0.5rem;
  font-weight: 700;
}

.mark--ok {
  background: var(--success);
}

.mark--warn {
  background: var(--danger);
  font-size: 0.6875rem;
}

.mark--run {
  background: var(--primary);
  padding: 0 0.5rem;
}

/* Wochenkarte: Arbeiterfarbe statt Prioritaet */
.week .job {
  background: var(--fill);
  border-left-width: 3px;
  border-radius: 6px;
  padding: 0.45rem 0.4rem 0.4rem 0.55rem;
}

.week .job.done {
  background: var(--button);
  opacity: 0.75;
}

.week .job.over {
  box-shadow: inset 0 0 0 1px var(--danger);
}

.week .title {
  font-size: 0.625rem;
  padding-right: 0.7rem;
  display: -webkit-box;
  -webkit-line-clamp: 3;
  -webkit-box-orient: vertical;
  overflow: hidden;
  word-break: break-word;
}

.prio {
  position: absolute;
  top: 8px;
  right: 6px;
  width: 6px;
  height: 6px;
  border-radius: 50%;
}

.who {
  margin-top: auto;
  height: 15px;
  padding: 0 0.35rem;
  border-radius: 7px;
  background: var(--accent);
  color: #fff;
  font-size: 0.5rem;
  font-weight: 700;
  line-height: 15px;
}

.who--top {
  margin-top: 0;
  align-self: center;
}

.week .job:has(.who--top) {
  padding: 0.35rem 0 0 0;
  align-items: center;
}

.free {
  position: absolute;
  left: 3px;
  right: 3px;
  height: calc(var(--hour) - 8px);
  padding: 0.5rem 0.6rem;
  border-radius: 6px;
  background: var(--danger-soft);
  color: var(--danger);
  font-size: 0.625rem;
  font-weight: 600;
  pointer-events: none;
}

.now {
  position: absolute;
  left: 0;
  right: 0;
  height: 2px;
  background: var(--danger);
  z-index: 2;
  pointer-events: none;
}

.now::before {
  content: '';
  position: absolute;
  left: -4px;
  top: -3px;
  width: 8px;
  height: 8px;
  border-radius: 50%;
  background: var(--danger);
}
</style>
