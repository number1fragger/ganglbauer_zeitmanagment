<script setup lang="ts">
import { computed } from 'vue'
import { useNow } from '@/composables/useNow'
import {
  DAY_END_HOUR,
  DAY_START_HOUR,
  HOUR_PX,
  hourToPx,
  layoutLanes,
  pxToTime,
  type Placed,
  type Segment,
} from '@/utils/calendar'
import { hourOfDay, sameDay } from '@/utils/time'

const props = defineProps<{
  day: Date
  segments: Segment[]
  gap?: number
  /** Wie Arbeiten nebeneinander verteilt werden – Standard: nach Ueberschneidung. */
  layout?: (segments: Segment[]) => Placed[]
}>()
const emit = defineEmits<{ create: [startsAt: Date] }>()
defineSlots<{ default(props: { segment: Placed }): unknown; footer?(): unknown }>()

const now = useNow()
const placed = computed(() => (props.layout ?? layoutLanes)(props.segments))

/** Rote Linie fuer die aktuelle Uhrzeit, nur am heutigen Tag. */
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
  <div class="column" @click="onBackgroundClick">
    <div v-for="segment in placed" :key="segment.job.id" class="column__item" :style="style(segment)">
      <slot :segment="segment" />
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
</style>
