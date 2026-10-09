<script setup lang="ts">
import { computed } from 'vue'
import { useNow } from '@/composables/useNow'
import { beginDrag, drag } from '@/composables/useJobDrag'
import {
  DAY_END_HOUR,
  DAY_START_HOUR,
  HOUR_PX,
  hourToPx,
  layoutLanes,
  pxToTime,
  type MoveEvent,
  type Placed,
  type Segment,
} from '@/utils/calendar'
import { hourOfDay, sameDay, toDateKey } from '@/utils/time'
import type { Job } from '@/api/types'

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
const emit = defineEmits<{ create: [startsAt: Date]; move: [event: MoveEvent] }>()
defineSlots<{ default(props: { segment: Placed }): unknown; footer?(): unknown }>()

const now = useNow()
const movable = (job: Job) => !!props.draggable && (props.canDrag?.(job) ?? true)
const placed = computed(() => (props.layout ?? layoutLanes)(props.segments))
const time = (date: Date) => date.toLocaleTimeString('de-AT', { hour: '2-digit', minute: '2-digit' })

const preview = computed(() => {
  const target = drag.target
  if (!drag.active || !target || target.key !== props.dropKey) return null
  const hour = target.startsAt.getHours() + target.startsAt.getMinutes() / 60
  const hours = (target.endsAt.getTime() - target.startsAt.getTime()) / 3_600_000
  return {
    top: hourToPx(hour) + 3,
    height: Math.max(22, hours * HOUR_PX - 8),
    label: `${time(target.startsAt)} – ${time(target.endsAt)}`,
  }
})

const nowTop = computed(() => {
  const hour = hourOfDay(now.value)
  if (!sameDay(now.value, props.day) || hour < DAY_START_HOUR || hour > DAY_END_HOUR) return null
  return hourToPx(hour)
})

function style(segment: Placed) {
  const gap = props.gap ?? 4
  return {
    top: `${hourToPx(segment.from) + 3}px`,
    height: `${Math.max(22, (segment.to - segment.from) * HOUR_PX - 8)}px`,
    left: `calc(${segment.left * 100}% + ${gap}px)`,
    width: `calc(${segment.width * 100}% - ${gap * 2}px)`,
  }
}

function onBackgroundClick(event: MouseEvent): void {
  if (event.target === event.currentTarget) emit('create', pxToTime(props.day, event.offsetY))
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
      v-for="segment in placed"
      :key="segment.job.id"
      class="column__item"
      :class="{
        'column__item--draggable': movable(segment.job),
        'column__item--dragging': drag.active && drag.job?.id === segment.job.id,
      }"
      :style="style(segment)"
      @pointerdown="movable(segment.job) && beginDrag($event, segment.job, (event) => emit('move', event))"
    >
      <slot :segment="segment" />
    </div>
    <div v-if="preview" class="column__preview" :style="{ top: `${preview.top}px`, height: `${preview.height}px` }">
      <span>{{ preview.label }}</span>
    </div>
    <slot name="footer" />
    <div v-if="nowTop !== null" class="column__now" :style="{ top: `${nowTop}px` }" aria-hidden="true" />
  </div>
</template>

<style scoped>
.column {
  position: relative;
  border-left: 1px solid var(--grid);
  cursor: copy;
}

.column__item {
  position: absolute;
  z-index: 1;
  display: flex;
  cursor: default;
  transition: top 0.2s ease, height 0.2s ease, left 0.2s ease, width 0.2s ease, opacity 0.15s;
}

.column__item--draggable {
  cursor: grab;
  user-select: none;
  touch-action: none;
}

.column__item--dragging {
  opacity: 0.3;
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
  transition: top 0.12s ease-out, height 0.12s ease-out;
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
