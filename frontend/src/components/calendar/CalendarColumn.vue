<script setup lang="ts">
import { computed } from 'vue'
import type { Job } from '@/api/types'
import { useNow } from '@/composables/useNow'
import { beginDrag, drag } from '@/composables/useJobDrag'
import { beginResize, resize } from '@/composables/useJobResize'
import { workDuration } from '@/composables/useWorkingHours'
import {
  blockedOn,
  gridEndHour,
  gridStartHour,
  HOUR_PX,
  hourToPx,
  layoutLanes,
  pxToTime,
  type MoveEvent,
  type Placed,
  type ResizeEvent,
  type Segment,
} from '@/utils/calendar'
import { formatDuration, hourOfDay, sameDay, toDateKey } from '@/utils/time'

const props = defineProps<{
  day: Date
  segments: Segment[]
  gap?: number
  layout?: (segments: Segment[]) => Placed[]
  draggable?: boolean
  /** Einzelne Arbeiten sperren, z. B. begonnene oder abgeschlossene. */
  canDrag?: (job: Job) => boolean
  dropKey?: string
  dropAssignee?: number | null | 'keep'
}>()
const emit = defineEmits<{
  create: [startsAt: Date]
  move: [event: MoveEvent]
  resize: [event: ResizeEvent]
}>()
defineSlots<{ default(props: { placed: Placed }): unknown; footer?(): unknown }>()

const now = useNow()
const movable = (segment: Segment) =>
  segment.kind === 'job' && !!props.draggable && (props.canDrag?.(segment.job) ?? true)
const placed = computed(() => (props.layout ?? layoutLanes)(props.segments))
const blocked = computed(() => blockedOn(props.day))
const time = (date: Date) =>
  date.toLocaleTimeString('de-AT', { hour: '2-digit', minute: '2-digit', hourCycle: 'h23' })

const preview = computed(() => {
  const target = drag.target
  if (!drag.active || !target || target.key !== props.dropKey) return null
  const hour = target.startsAt.getHours() + target.startsAt.getMinutes() / 60
  const hours = Math.min(
    (target.endsAt.getTime() - target.startsAt.getTime()) / 3_600_000,
    gridEndHour() - hour,
  )
  return {
    top: hourToPx(hour) + 3,
    height: Math.max(22, hours * HOUR_PX - 8),
    label: `${time(target.startsAt)} – ${time(target.endsAt)}`,
  }
})

const nowTop = computed(() => {
  const hour = hourOfDay(now.value)
  if (!sameDay(now.value, props.day) || hour < gridStartHour() || hour > gridEndHour()) return null
  return hourToPx(hour)
})

/** Waehrend am Rand gezogen wird: neue Zeiten statt der gespeicherten. */
function liveRange(item: Placed): { from: number; to: number } {
  const { segment } = item
  if (segment.kind !== 'job' || !resize.active || resize.job?.id !== segment.job.id) return segment
  const start = resize.startsAt!
  const end = resize.endsAt!
  return {
    from: sameDay(start, props.day) ? Math.max(gridStartHour(), hourOfDay(start)) : segment.from,
    to: sameDay(end, props.day) ? Math.min(gridEndHour(), hourOfDay(end)) : segment.to,
  }
}

function style(item: Placed) {
  const gap = props.gap ?? 4
  const { from, to } = liveRange(item)
  return {
    top: `${hourToPx(from) + 3}px`,
    height: `${Math.max(22, (to - from) * HOUR_PX - 8)}px`,
    left: `calc(${item.left * 100}% + ${gap}px)`,
    width: `calc(${item.width * 100}% - ${gap * 2}px)`,
  }
}

const resizeLabel = computed(() => {
  if (!resize.active || !resize.startsAt || !resize.endsAt) return ''
  const minutes = workDuration(resize.startsAt, resize.endsAt)
  return `${time(resize.startsAt)} – ${time(resize.endsAt)} · ${formatDuration(minutes * 60)}`
})

function startDrag(event: PointerEvent, item: Placed): void {
  const { segment } = item
  if (segment.kind !== 'job' || !movable(segment)) return
  beginDrag(event, segment.job, (move) => emit('move', move), {
    keepAssignee: segment.role === 'apprentice',
  })
}

function startResize(event: PointerEvent, item: Placed, edge: 'start' | 'end'): void {
  const { segment } = item
  if (segment.kind !== 'job') return
  beginResize(event, segment.job, edge, (change) => emit('resize', change))
}

function onBackgroundClick(event: MouseEvent): void {
  const target = event.target as HTMLElement
  const onBlocked = target.classList.contains('column__blocked')
  if (event.target !== event.currentTarget && !onBlocked) return
  emit('create', pxToTime(props.day, event.offsetY + (onBlocked ? target.offsetTop : 0)))
}
</script>

<template>
  <div
    class="column"
    :data-drop-day="toDateKey(day)"
    :data-drop-key="dropKey"
    :data-drop-assignee="dropAssignee ?? ''"
    @click="onBackgroundClick"
  >
    <div
      v-for="range in blocked"
      :key="`${range.from}-${range.label}`"
      class="column__blocked"
      :class="{ 'column__blocked--break': range.label && range.label !== 'Feierabend' }"
      :style="{ top: `${hourToPx(range.from)}px`, height: `${(range.to - range.from) * HOUR_PX}px` }"
      :title="range.label ? `${range.label} – nicht verfügbar für Aufträge` : 'Außerhalb der Arbeitszeit'"
    >
      <span v-if="range.label && range.to - range.from >= 0.5">{{ range.label }}</span>
    </div>

    <div
      v-for="item in placed"
      :key="item.segment.key"
      class="column__item"
      :class="{
        'column__item--draggable': movable(item.segment),
        'column__item--absence': item.segment.kind === 'absence',
        'column__item--dragging':
          item.segment.kind === 'job' && drag.active && drag.job?.id === item.segment.job.id,
        'column__item--resizing':
          item.segment.kind === 'job' && resize.active && resize.job?.id === item.segment.job.id,
      }"
      :style="style(item)"
      @pointerdown="startDrag($event, item)"
    >
      <slot :placed="item" />
      <template v-if="movable(item.segment)">
        <span
          v-if="item.segment.startsHere"
          class="column__handle column__handle--top"
          role="separator"
          aria-label="Beginn ziehen"
          title="Ziehen, um den Beginn zu ändern"
          @pointerdown="startResize($event, item, 'start')"
        />
        <span
          v-if="item.segment.endsHere"
          class="column__handle column__handle--bottom"
          role="separator"
          aria-label="Ende ziehen"
          title="Ziehen, um das Ende zu ändern (verlängern/verkürzen)"
          @pointerdown="startResize($event, item, 'end')"
        />
      </template>
      <span
        v-if="item.segment.kind === 'job' && resize.active && resize.job?.id === item.segment.job.id"
        class="column__resize-label"
        >{{ resizeLabel }}</span
      >
    </div>
    <div
      v-if="preview"
      class="column__preview"
      :style="{ top: `${preview.top}px`, height: `${preview.height}px` }"
    >
      <span>{{ preview.label }}</span>
    </div>
    <slot name="footer" />
    <div
      v-if="nowTop !== null"
      class="column__now"
      :style="{ top: `${nowTop}px` }"
      aria-hidden="true"
    />
  </div>
</template>

<style scoped>
.column {
  position: relative;
  border-left: 1px solid var(--grid);
  cursor: copy;
}

/* Nicht verfuegbare Zeit: vor Arbeitsbeginn, Mittagspause, nach Feierabend */
.column__blocked {
  position: absolute;
  left: 0;
  right: 0;
  background: repeating-linear-gradient(
    -45deg,
    var(--blocked) 0 6px,
    transparent 6px 12px
  );
}

.column__blocked--break {
  background-color: color-mix(in srgb, var(--warning) 6%, transparent);
}

.column__blocked span {
  position: absolute;
  top: 4px;
  right: 6px;
  color: var(--faint);
  font-size: 9px;
  font-weight: 600;
  pointer-events: none;
}

.column__item {
  position: absolute;
  z-index: 1;
  display: flex;
  cursor: default;
  transition:
    top 0.2s ease,
    height 0.2s ease,
    left 0.2s ease,
    width 0.2s ease,
    opacity 0.15s;
}

.column__item--absence {
  z-index: 0;
}

.column__item--draggable {
  cursor: grab;
  user-select: none;
  touch-action: none;
}

.column__item--dragging {
  opacity: 0.3;
}

.column__item--resizing {
  z-index: 3;
  transition: none;
  box-shadow: 0 0 0 2px var(--primary);
  border-radius: var(--radius);
}

/* Ziehgriffe am oberen und unteren Rand */
.column__handle {
  position: absolute;
  z-index: 2;
  left: 0;
  right: 0;
  height: 8px;
  cursor: ns-resize;
  touch-action: none;
}

.column__handle--top {
  top: -3px;
}

.column__handle--bottom {
  bottom: -3px;
}

.column__handle::after {
  content: '';
  position: absolute;
  left: 50%;
  width: 22px;
  height: 3px;
  margin-left: -11px;
  border-radius: 2px;
  background: var(--primary);
  opacity: 0;
  transition: opacity 0.15s;
}

.column__handle--top::after {
  top: 3px;
}

.column__handle--bottom::after {
  bottom: 3px;
}

.column__item:hover .column__handle::after,
.column__item--resizing .column__handle::after {
  opacity: 0.8;
}

.column__resize-label {
  position: absolute;
  z-index: 3;
  left: 4px;
  bottom: -22px;
  padding: 2px 6px;
  border-radius: var(--radius-sm);
  background: var(--primary);
  color: var(--on-primary);
  font-size: 10px;
  font-weight: 700;
  white-space: nowrap;
  pointer-events: none;
}

.column__preview {
  position: absolute;
  z-index: 1;
  left: 4px;
  right: 4px;
  padding: 6px 10px;
  border: 2px dashed var(--primary);
  border-radius: var(--radius);
  background: color-mix(in srgb, var(--primary) 10%, transparent);
  color: var(--primary);
  font-size: 11px;
  font-weight: 700;
  pointer-events: none;
  transition:
    top 0.12s ease-out,
    height 0.12s ease-out;
  animation: preview-in 0.12s ease-out;
}

.column__now {
  position: absolute;
  z-index: 2;
  left: 0;
  right: 0;
  height: 2px;
  background: var(--danger);
  pointer-events: none;
}

.column:first-child .column__now::before {
  content: '';
  position: absolute;
  top: -3px;
  left: -4px;
  width: 8px;
  height: 8px;
  border-radius: 50%;
  background: var(--danger);
}

@keyframes preview-in {
  from {
    opacity: 0;
  }
}
</style>
