<script setup lang="ts">
import { computed, onBeforeUnmount, ref } from 'vue'
import type { CalendarSegment } from '@/api/types'
import { layoutLanes, minutesOfDay } from '@/utils/calendar'
import { formatDuration, formatTime, priorityColor, priorityLabel } from '@/utils/format'

/**
 * Zeitraster fuer Tag und Woche: Spalten nebeneinander, Stunden untereinander.
 * In der Tagesansicht ist jede Spalte ein Arbeiter, in der Wochenansicht ein Tag.
 */
export interface GridColumn {
  key: string
  title: string
  subtitle?: string
  badge?: string
  today?: boolean
}

const props = defineProps<{
  columns: GridColumn[]
  items: { key: string; segment: CalendarSegment; color: string }[]
  startHour: number
  endHour: number
  /** Spalten, in denen die aktuelle Uhrzeit als rote Linie erscheint. */
  nowColumns: string[]
  /** true: Balken links in Arbeiterfarbe + Kuerzel (Woche). false: Prioritaetsfarbe (Tag). */
  showWorker: boolean
}>()

const emit = defineEmits<{
  open: [jobId: number]
  slot: [columnKey: string, minutes: number]
}>()

const HOUR_PX = 56
const hours = computed(() =>
  Array.from({ length: props.endHour - props.startHour + 1 }, (_, i) => props.startHour + i),
)
const gridHeight = computed(() => (props.endHour - props.startHour) * HOUR_PX)

function top(minutes: number): number {
  const clamped = Math.min(Math.max(minutes, props.startHour * 60), props.endHour * 60)

  return ((clamped - props.startHour * 60) / 60) * HOUR_PX
}

const laidOut = computed(() => {
  const byColumn = new Map<string, typeof props.items>()
  for (const item of props.items) {
    byColumn.set(item.key, [...(byColumn.get(item.key) ?? []), item])
  }

  const result: Record<string, ReturnType<typeof place>[]> = {}
  for (const [key, list] of byColumn) {
    result[key] = layoutLanes(list, (i) => [
      minutesOfDay(new Date(i.segment.start)),
      minutesOfDay(new Date(i.segment.end)),
    ]).map(place)
  }

  return result
})

function place(entry: { item: (typeof props.items)[number]; lane: number; lanes: number }) {
  const start = new Date(entry.item.segment.start)
  const end = new Date(entry.item.segment.end)
  const y = top(minutesOfDay(start))
  const height = Math.max(22, top(minutesOfDay(end)) - y - 3)

  return {
    ...entry.item,
    style: {
      top: `${y + 1}px`,
      height: `${height}px`,
      left: `calc(${(entry.lane / entry.lanes) * 100}% + 3px)`,
      width: `calc(${100 / entry.lanes}% - 6px)`,
      '--accent': entry.item.color,
    },
    compact: height < 44,
    // Bei drei und mehr parallelen Arbeiten ist die Karte schmal:
    // Titel darf umbrechen, Kunde und Hinweise entfallen.
    narrow: entry.lanes >= 3,
  }
}

// Rote "Jetzt"-Linie, jede Minute aktualisiert.
const now = ref(new Date())
const timer = setInterval(() => (now.value = new Date()), 60_000)
onBeforeUnmount(() => clearInterval(timer))

const nowTop = computed(() => {
  const minutes = minutesOfDay(now.value)
  if (minutes < props.startHour * 60 || minutes > props.endHour * 60) return null

  return top(minutes)
})

/** Klick auf eine freie Stelle: Uhrzeit auf halbe Stunden runden und melden. */
function onSlotClick(event: MouseEvent, columnKey: string): void {
  const target = event.currentTarget as HTMLElement
  const y = event.clientY - target.getBoundingClientRect().top
  const minutes = props.startHour * 60 + Math.floor((y / HOUR_PX) * 2) * 30
  emit('slot', columnKey, minutes)
}

function tooltip(segment: CalendarSegment): string {
  const lines = [
    segment.title,
    segment.customer ?? 'Ohne Kunde',
    `${formatTime(segment.start)} – ${formatTime(segment.end)}`,
    `Priorität: ${priorityLabel(segment.priority)}`,
    `Soll ${formatDuration(segment.plannedMinutes)} · Ist ${formatDuration(segment.actualMinutes)}`,
  ]
  if (segment.parts > 1) lines.push(`Teil ${segment.part} von ${segment.parts}`)
  if (segment.behind) lines.push('Überfällig: Plan ist vorbei, Arbeit noch nicht erledigt')
  else if (segment.late) lines.push('Wird nicht bis zum geplanten Ende fertig')

  return lines.join('\n')
}
</script>

<template>
  <div class="grid" :style="{ '--cols': columns.length }">
    <div class="head gutter"></div>
    <div v-for="col in columns" :key="col.key" class="head" :class="{ today: col.today }">
      <span v-if="col.badge" class="badge-initials">{{ col.badge }}</span>
      <span class="head__text">
        <strong>{{ col.title }}</strong>
        <span v-if="col.subtitle" class="muted">{{ col.subtitle }}</span>
      </span>
    </div>

    <div class="gutter hours" :style="{ height: `${gridHeight}px` }">
      <span v-for="h in hours" :key="h" :style="{ top: `${(h - startHour) * HOUR_PX}px` }">
        {{ String(h).padStart(2, '0') }}:00
      </span>
    </div>

    <div
      v-for="col in columns"
      :key="col.key"
      class="column"
      :class="{ today: col.today }"
      :style="{ height: `${gridHeight}px`, '--hour': `${HOUR_PX}px` }"
      title="Klicken, um hier eine Arbeit einzuplanen"
      @click.self="onSlotClick($event, col.key)"
    >
      <button
        v-for="item in laidOut[col.key] ?? []"
        :key="`${item.segment.jobId}-${item.segment.part}`"
        type="button"
        class="block"
        :class="{
          done: item.segment.done,
          overrun: item.segment.overrun && !item.segment.done,
          late: (item.segment.late || item.segment.behind) && !item.segment.done,
          compact: item.compact,
          narrow: item.narrow,
        }"
        :style="item.style"
        :title="tooltip(item.segment)"
        @click="emit('open', item.segment.jobId)"
      >
        <span class="block__title">
          <span
            v-if="showWorker"
            class="prio"
            :style="{ background: priorityColor(item.segment.priority) }"
          ></span>
          {{ item.segment.title }}
          <span v-if="item.segment.parts > 1" class="muted part">
            {{ item.segment.part }}/{{ item.segment.parts }}
          </span>
        </span>
        <span v-if="!item.compact && !item.narrow" class="block__meta">
          {{ item.segment.customer ?? 'Ohne Kunde' }}
        </span>
        <span v-if="!item.compact" class="block__foot">
          <span v-if="showWorker && item.segment.assignee" class="who">
            {{ item.segment.assignee.initials }}
          </span>
          <template v-if="!item.narrow">
            <span v-if="item.segment.running" class="flag flag--run">läuft</span>
            <span v-if="item.segment.done" class="flag flag--done">erledigt</span>
            <span v-else-if="item.segment.overrun" class="flag flag--warn">Zeit überschritten</span>
            <span v-else-if="item.segment.behind" class="flag flag--warn">überfällig</span>
            <span v-else-if="item.segment.late" class="flag flag--warn">zu spät</span>
          </template>
          <span v-else-if="item.segment.overrun && !item.segment.done" class="flag flag--warn"
            >!</span
          >
        </span>
      </button>

      <div
        v-if="nowTop !== null && nowColumns.includes(col.key)"
        class="now"
        :style="{ top: `${nowTop}px` }"
      ></div>
    </div>
  </div>
</template>

<style scoped>
.grid {
  display: grid;
  grid-template-columns: 56px repeat(var(--cols), minmax(180px, 1fr));
  min-width: calc(56px + var(--cols) * 180px);
}

.head {
  position: sticky;
  top: 0;
  z-index: 3;
  display: flex;
  align-items: center;
  gap: 0.6rem;
  padding: 0.7rem 0.75rem;
  background: var(--surface);
  border-bottom: 1px solid var(--border);
  border-left: 1px solid var(--border);
  min-height: 60px;
}

.head.gutter {
  border-left: none;
}

.head__text {
  display: flex;
  flex-direction: column;
  line-height: 1.25;
  min-width: 0;
}

.head__text strong {
  font-size: 0.875rem;
}

.head__text .muted {
  font-size: 0.75rem;
}

.head.today strong {
  color: var(--primary);
}

.badge-initials {
  flex: none;
  width: 30px;
  height: 30px;
  border-radius: 50%;
  display: grid;
  place-items: center;
  background: var(--primary-soft);
  color: var(--primary);
  font-weight: 700;
  font-size: 0.7rem;
}

.hours {
  position: relative;
}

.hours span {
  position: absolute;
  right: 8px;
  transform: translateY(-50%);
  font-size: 0.6875rem;
  color: var(--muted);
  font-variant-numeric: tabular-nums;
}

.hours span:first-child {
  transform: none;
}

.column {
  position: relative;
  border-left: 1px solid var(--border);
  background-image: repeating-linear-gradient(
    to bottom,
    var(--border) 0,
    var(--border) 1px,
    transparent 1px,
    transparent var(--hour)
  );
  cursor: copy;
}

.column.today {
  background-color: color-mix(in srgb, var(--primary) 4%, transparent);
}

.block {
  position: absolute;
  z-index: 1;
  display: flex;
  flex-direction: column;
  gap: 0.1rem;
  text-align: left;
  padding: 0.35rem 0.5rem 0.35rem 0.65rem;
  border-radius: 6px;
  border: none;
  border-left: 4px solid var(--accent);
  background: color-mix(in srgb, var(--accent) 12%, var(--surface));
  color: var(--text);
  font-weight: 400;
  overflow: hidden;
  cursor: pointer;
  box-shadow: 0 1px 2px rgba(16, 24, 40, 0.08);
}

.block:hover {
  z-index: 2;
  box-shadow: 0 4px 12px rgba(16, 24, 40, 0.18);
}

.block.compact {
  padding-top: 0.15rem;
  padding-bottom: 0.15rem;
}

.block.done {
  background: var(--bg);
  border-left-color: var(--muted);
  opacity: 0.75;
}

.block.done .block__title {
  text-decoration: line-through;
}

.block.narrow {
  padding: 0.3rem 0.25rem 0.3rem 0.4rem;
  border-left-width: 3px;
}

.block.narrow .block__title {
  white-space: normal;
  word-break: break-word;
  hyphens: auto;
  display: -webkit-box;
  -webkit-line-clamp: 4;
  -webkit-box-orient: vertical;
  font-size: 0.6875rem;
}

.block.narrow .prio {
  display: none;
}

.block.overrun,
.block.late {
  box-shadow: inset 0 0 0 1px var(--danger);
}

.block__title {
  display: flex;
  align-items: center;
  gap: 0.35rem;
  font-weight: 600;
  font-size: 0.75rem;
  line-height: 1.25;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}

.prio {
  width: 7px;
  height: 7px;
  border-radius: 50%;
  flex: none;
}

.part {
  font-weight: 500;
}

.block__meta {
  font-size: 0.6875rem;
  color: var(--muted);
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}

.block__foot {
  display: flex;
  flex-wrap: wrap;
  gap: 0.25rem;
  margin-top: auto;
}

.who,
.flag {
  font-size: 0.625rem;
  font-weight: 700;
  padding: 0.05rem 0.4rem;
  border-radius: 999px;
}

.who {
  background: var(--accent);
  color: #fff;
}

.flag--run {
  background: var(--primary);
  color: #fff;
}

.flag--done {
  background: var(--success);
  color: #fff;
}

.flag--warn {
  background: var(--danger);
  color: #fff;
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
  left: -5px;
  top: -4px;
  width: 10px;
  height: 10px;
  border-radius: 50%;
  background: var(--danger);
}
</style>
