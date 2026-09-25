<script setup>
import { computed, onBeforeUnmount, ref } from 'vue'
import { useDrag } from '@/composables/useDrag'
import { layoutLanes, minutesOfDay } from '@/utils/calendar'
import { formatHours, formatTime, priorityColor, priorityLabel } from '@/utils/format'

/**
 * Zeitraster fuer Tag (Spalte = Arbeiter) und Woche (Spalte = Tag).
 * Karten lassen sich per Drag & Drop verschieben, Klick oeffnet sie,
 * Klick auf eine freie Stelle legt dort eine neue Arbeit an.
 */
const props = defineProps({
  variant: { type: String, required: true }, // 'day' | 'week'
  columns: { type: Array, required: true }, // { key, title, subtitle, badge, today, freeNote }
  items: { type: Array, required: true }, // { key, segment, color, soft }
  startHour: { type: Number, default: 7 },
  endHour: { type: Number, default: 17 },
  nowColumns: { type: Array, default: () => [] },
})

const emit = defineEmits(['open', 'slot', 'move'])

const HOUR_PX = 58
const SNAP_MINUTES = 15
const root = ref(null)

const hours = computed(() =>
  Array.from({ length: props.endHour - props.startHour + 1 }, (_, i) => props.startHour + i),
)
const gridHeight = computed(() => (props.endHour - props.startHour) * HOUR_PX)

const toPx = (minutes) =>
  ((Math.min(Math.max(minutes, props.startHour * 60), props.endHour * 60) - props.startHour * 60) /
    60) *
  HOUR_PX

const pad = (n) => String(n).padStart(2, '0')
const clock = (minutes) => `${pad(Math.floor(minutes / 60))}:${pad(minutes % 60)}`

/** Karten je Spalte, ueberlappende nebeneinander. */
const laidOut = computed(() => {
  const byColumn = {}
  for (const item of props.items) (byColumn[item.key] ??= []).push(item)

  const result = {}
  for (const [key, list] of Object.entries(byColumn)) {
    result[key] = layoutLanes(list, (i) => [
      minutesOfDay(new Date(i.segment.start)),
      minutesOfDay(new Date(i.segment.end)),
    ]).map(({ item, lane, lanes }) => {
      const y = toPx(minutesOfDay(new Date(item.segment.start)))
      const height = Math.max(24, toPx(minutesOfDay(new Date(item.segment.end))) - y - 6)
      const inset = props.variant === 'day' ? 8 : 3

      return {
        ...item,
        height,
        // Ab vier parallelen Arbeiten passt in der Woche nur noch das Kuerzel.
        tiny: props.variant === 'week' && lanes >= 4,
        style: {
          top: `${y + 3}px`,
          height: `${height}px`,
          left: `calc(${(lane / lanes) * 100}% + ${inset}px)`,
          width: `calc(${100 / lanes}% - ${inset * 2}px)`,
          ...(props.variant === 'week'
            ? { '--accent': item.color, '--fill': item.soft }
            : { '--accent': priorityColor(item.segment.priority) }),
        },
      }
    })
  }

  return result
})

// --- Drag & Drop -----------------------------------------------------------

const { drag, start } = useDrag({
  resolveTarget(x, y, state) {
    const column = document.elementFromPoint(x, y)?.closest('[data-column]')
    if (!column || !root.value?.contains(column)) return null

    const top = y - state.offsetY - column.getBoundingClientRect().top
    const raw = props.startHour * 60 + (top / HOUR_PX) * 60
    const snapped = Math.round(raw / SNAP_MINUTES) * SNAP_MINUTES
    const minutes = Math.min(
      Math.max(snapped, props.startHour * 60),
      props.endHour * 60 - SNAP_MINUTES,
    )

    return { columnKey: column.dataset.column, minutes }
  },
  onDrop: (item, target) => emit('move', item.segment, target),
  onClick: (item) => emit('open', item.segment.jobId),
})

const isDragged = (item) =>
  drag.active &&
  drag.item?.segment.jobId === item.segment.jobId &&
  drag.item?.segment.part === item.segment.part

// --- Jetzt-Linie ------------------------------------------------------------

const now = ref(new Date())
const timer = setInterval(() => (now.value = new Date()), 60_000)
onBeforeUnmount(() => clearInterval(timer))

const nowTop = computed(() => {
  const minutes = minutesOfDay(now.value)
  return minutes < props.startHour * 60 || minutes > props.endHour * 60 ? null : toPx(minutes)
})

function onSlotClick(event, columnKey) {
  const y = event.clientY - event.currentTarget.getBoundingClientRect().top
  emit('slot', columnKey, props.startHour * 60 + Math.floor((y / HOUR_PX) * 2) * 30)
}

// --- Darstellung ------------------------------------------------------------

function state(s) {
  if (s.done) return 'done'
  return s.overrun || s.behind || s.late ? 'over' : 'open'
}

function timeLine(s) {
  const soll = `Soll ${formatHours(s.plannedMinutes)}`
  return s.actualMinutes > 0 ? `${soll} · Ist ${formatHours(s.actualMinutes)}` : soll
}

function tooltip(s) {
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
  if (!s.done) lines.push('Ziehen zum Verschieben')

  return lines.join('\n')
}
</script>

<template>
  <div
    ref="root"
    class="tgrid"
    :class="[variant, { dragging: drag.active }]"
    :style="{ '--cols': columns.length, '--hour': `${HOUR_PX}px` }"
  >
    <div class="head gutter"><span v-if="variant === 'day'">Zeit</span></div>
    <div v-for="col in columns" :key="col.key" class="head" :class="{ today: col.today }">
      <template v-if="variant === 'day'">
        <span class="avatar">{{ col.badge }}</span>
        <span class="head-text">
          <strong>{{ col.title }}</strong>
          <span>{{ col.subtitle }}</span>
        </span>
      </template>
      <template v-else>
        <span class="weekday">{{ col.subtitle }}</span>
        <span class="daynum">{{ col.title }}</span>
      </template>
    </div>

    <div class="gutter hours" :style="{ height: `${gridHeight}px` }">
      <span v-for="h in hours" :key="h" :style="{ top: `${(h - startHour) * HOUR_PX}px` }">
        {{ pad(h) }}:00
      </span>
    </div>

    <div
      v-for="col in columns"
      :key="col.key"
      class="column"
      :data-column="col.key"
      :style="{ height: `${gridHeight}px` }"
      @click.self="onSlotClick($event, col.key)"
    >
      <button
        v-for="item in laidOut[col.key] ?? []"
        :key="`${item.segment.jobId}-${item.segment.part}`"
        type="button"
        class="job"
        :class="[state(item.segment), { lifted: isDragged(item), locked: item.segment.done }]"
        :style="item.style"
        :title="tooltip(item.segment)"
        draggable="false"
        @pointerdown="start($event, item, { draggable: !item.segment.done })"
        @keydown.enter.prevent="emit('open', item.segment.jobId)"
      >
        <template v-if="variant === 'day'">
          <strong class="title">
            {{ item.segment.title }}
            <span v-if="item.segment.parts > 1" class="part"
              >{{ item.segment.part }}/{{ item.segment.parts }}</span
            >
          </strong>
          <span v-if="item.height > 40" class="customer"
            >Kunde: {{ item.segment.customer ?? '–' }}</span
          >
          <span v-if="item.height > 60" class="times">{{ timeLine(item.segment) }}</span>

          <span v-if="item.segment.done" class="mark mark-ok">OK</span>
          <span v-else-if="state(item.segment) === 'over'" class="mark mark-warn">!</span>
          <span v-else-if="item.segment.running" class="mark mark-run">läuft</span>
        </template>

        <template v-else-if="item.tiny">
          <span class="who who-top">{{ item.segment.assignee?.initials }}</span>
        </template>

        <template v-else>
          <strong class="title">{{ item.segment.title }}</strong>
          <span class="prio" :style="{ background: priorityColor(item.segment.priority) }"></span>
          <span v-if="item.height > 44" class="who">{{ item.segment.assignee?.initials }}</span>
        </template>
      </button>

      <div
        v-if="drag.active && drag.target?.columnKey === col.key"
        class="preview"
        :style="{ top: `${toPx(drag.target.minutes) + 3}px`, height: `${drag.height}px` }"
      >
        <span>{{ clock(drag.target.minutes) }}</span>
      </div>

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

.tgrid.dragging {
  cursor: grabbing;
  user-select: none;
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

.head-text {
  display: flex;
  flex-direction: column;
  min-width: 0;
  line-height: 1.35;
}

.head-text strong {
  font-size: 0.8125rem;
  font-weight: 600;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}

.head-text span {
  font-size: 0.6875rem;
  color: var(--muted);
}

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
  min-width: 34px;
  height: 30px;
  padding: 0 0.4rem;
  border-radius: 15px;
  background: var(--primary);
  color: #fff;
  font-size: 0.9375rem;
  font-weight: 700;
  text-align: center;
}

.hours {
  position: relative;
  background: var(--surface);
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
  border-bottom: 1px solid var(--grid);
  background-image: repeating-linear-gradient(
    to bottom,
    var(--grid) 0,
    var(--grid) 1px,
    transparent 1px,
    transparent var(--hour)
  );
  cursor: copy;
}

/* ---------- Karten ---------- */
.job {
  position: absolute;
  z-index: 1;
  display: flex;
  flex-direction: column;
  align-items: flex-start;
  height: auto;
  padding: 0.6rem 0.75rem 0.6rem 0.875rem;
  border-radius: 8px;
  border-left: 4px solid var(--accent);
  background: #f0f4fe;
  color: var(--text);
  font-weight: 400;
  text-align: left;
  white-space: normal;
  overflow: hidden;
  cursor: grab;
  touch-action: pan-x pan-y;
  user-select: none;
  -webkit-user-select: none;
  -webkit-touch-callout: none;
  transition: opacity 0.15s;
}

.job:hover {
  z-index: 2;
  box-shadow: 0 4px 14px rgba(16, 24, 40, 0.14);
}

.job.locked {
  cursor: pointer;
}

.job.lifted {
  opacity: 0.35;
}

.job.over {
  background: var(--danger-soft);
}

.job.done {
  background: var(--button);
  border-left-color: var(--faint);
}

.title {
  padding-right: 1.9rem;
  font-size: 0.75rem;
  font-weight: 600;
  line-height: 1.3;
}

.job.done .title {
  color: var(--muted);
}

.part {
  font-weight: 500;
  color: var(--muted);
}

.customer {
  margin-top: 0.15rem;
  font-size: 0.625rem;
  color: var(--muted);
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
  min-width: 18px;
  height: 18px;
  display: grid;
  place-items: center;
  border-radius: 9px;
  color: #fff;
  font-size: 0.5rem;
  font-weight: 700;
}

.mark-ok {
  background: var(--success);
}

.mark-warn {
  background: var(--danger);
  font-size: 0.6875rem;
}

.mark-run {
  padding: 0 0.5rem;
  background: var(--primary);
}

.week .job {
  padding: 0.45rem 0.4rem 0.4rem 0.55rem;
  border-left-width: 3px;
  border-radius: 6px;
  background: var(--fill);
}

.week .job.done {
  background: var(--button);
  opacity: 0.75;
}

.week .job.over {
  box-shadow: inset 0 0 0 1px var(--danger);
}

.week .title {
  display: -webkit-box;
  padding-right: 0.7rem;
  overflow: hidden;
  font-size: 0.625rem;
  word-break: break-word;
  -webkit-line-clamp: 3;
  -webkit-box-orient: vertical;
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

.who-top {
  align-self: center;
  margin-top: 0;
}

.week .job:has(.who-top) {
  align-items: center;
  padding: 0.35rem 0 0;
}

/* ---------- Vorschau beim Ziehen ---------- */
.preview {
  position: absolute;
  left: 4px;
  right: 4px;
  z-index: 4;
  border: 2px dashed var(--primary);
  border-radius: 8px;
  background: rgba(37, 99, 235, 0.08);
  pointer-events: none;
}

.preview span {
  position: absolute;
  top: -11px;
  left: 8px;
  padding: 1px 8px;
  border-radius: 10px;
  background: var(--primary);
  color: #fff;
  font-size: 0.6875rem;
  font-weight: 600;
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
  z-index: 2;
  height: 2px;
  background: var(--danger);
  pointer-events: none;
}

.now::before {
  position: absolute;
  top: -3px;
  left: -4px;
  width: 8px;
  height: 8px;
  border-radius: 50%;
  background: var(--danger);
  content: '';
}

/* ---------- Handy: eine Spalte pro Bildschirm, seitlich wischen ---------- */
@media (max-width: 768px) {
  .tgrid {
    grid-template-columns: 44px repeat(var(--cols), calc(100vw - 60px));
    min-width: 0;
  }

  .head {
    padding: 0 0.75rem;
    scroll-snap-align: start;
    scroll-margin-left: 44px;
  }

  .gutter {
    position: sticky;
    left: 0;
    z-index: 4;
  }

  .head.gutter {
    z-index: 5;
    padding-left: 0.5rem;
  }

  .hours span {
    left: 0.5rem;
  }

  .job {
    padding: 0.55rem 0.7rem 0.55rem 0.8rem;
  }
}
</style>
