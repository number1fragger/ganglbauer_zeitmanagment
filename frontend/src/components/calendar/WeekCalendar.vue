<script setup lang="ts">
import { computed } from 'vue'
import type { Job, OverviewWorker } from '@/api/types'
import { useNow } from '@/composables/useNow'
import { DAY_END_HOUR, hourToPx, layoutByWorker, segmentOn, type Segment } from '@/utils/calendar'
import { workerColor } from '@/utils/domain'
import type { MoveEvent } from '@/utils/calendar'
import { addDays, sameDay, startOfDay, toDateKey, weekdayName } from '@/utils/time'
import CalendarColumn from './CalendarColumn.vue'
import TimeGrid from './TimeGrid.vue'
import WeekJobCard from './WeekJobCard.vue'

const FREE_FROM_HOUR = DAY_END_HOUR - 1

const props = defineProps<{
  weekStart: Date
  workers: OverviewWorker[]
  visible: number[]
  jobs: Job[]
  draggable?: boolean
  canDrag?: (job: Job) => boolean
  conflicts?: Map<number, string[]>
}>()
defineEmits<{
  open: [job: Job]
  create: [defaults: { startsAt: Date; assigneeId: number | null }]
  move: [event: MoveEvent]
}>()

const now = useNow()

const byWorker = (segments: Segment[]) => layoutByWorker(segments, props.workers.map((w) => w.user.id))

const colorOf = (job: Job) => workerColor(props.workers.findIndex((w) => w.user.id === job.assignee?.id))

const days = computed(() =>
  Array.from({ length: 5 }, (_, i) => {
    const day = addDays(props.weekStart, i)
    const segments = props.jobs
      .filter((job) => job.assignee === null || props.visible.includes(job.assignee.id))
      .map((job) => segmentOn(job, day))
      .filter((s): s is Segment => s !== null)

    return { day, segments, free: freeWorkers(day, segments) }
  }),
)

/**
 * Wie viele (angezeigte) Arbeiter haben an dem Tag noch mindestens einen
 * halben Tag frei? Fuer vergangene Tage nicht mehr interessant.
 */
function freeWorkers(day: Date, segments: Segment[]): number {
  if (day < startOfDay(now.value)) return 0

  return props.workers.filter((w) => {
    if (!props.visible.includes(w.user.id)) return false
    const plannedHours = segments
      .filter((s) => s.job.assignee?.id === w.user.id)
      .reduce((sum, s) => sum + (s.to - s.from), 0)
    return plannedHours < w.user.weeklyHours / 10
  }).length
}
</script>

<template>
  <TimeGrid :columns="5" :min-column-width="120">
    <template #head>
      <div
        v-for="{ day } in days"
        :key="day.toISOString()"
        class="week-head"
        :class="{ 'week-head--today': sameDay(day, now) }"
      >
        <small>{{ weekdayName(day, 'short').toUpperCase() }}</small>
        <strong>{{ day.getDate() }}</strong>
      </div>
    </template>

    <CalendarColumn
      v-for="{ day, segments, free } in days"
      :key="day.toISOString()"
      :day="day"
      :segments="segments"
      :gap="2"
      :layout="byWorker"
      :draggable="draggable"
      :can-drag="canDrag"
      :drop-key="toDateKey(day)"
      drop-assignee="keep"
      @create="$emit('create', { startsAt: $event, assigneeId: null })"
      @move="$emit('move', $event)"
    >
      <template #default="{ segment }">
        <WeekJobCard
          :job="segment.job"
          :color="colorOf(segment.job)"
          :conflict="conflicts?.get(segment.job.id)"
          @open="$emit('open', $event)"
        />
      </template>
      <template #footer>
        <div
          v-if="free > 0"
          class="week-free"
          :style="{ top: `${hourToPx(FREE_FROM_HOUR) + 2}px` }"
          title="Arbeiter, die an diesem Tag noch mindestens einen halben Tag frei haben"
        >
          frei: {{ free }} Arbeiter
        </div>
      </template>
    </CalendarColumn>
  </TimeGrid>
</template>

<style scoped>
.week-head {
  display: flex;
  flex-direction: column;
  align-items: flex-start;
  gap: 4px;
  padding: 6px 16px 8px;
  border-left: 1px solid var(--border);
}

.week-head small {
  color: var(--muted);
  font-size: 10px;
  font-weight: 600;
}

.week-head strong {
  font-size: 18px;
  font-weight: 600;
}

.week-head--today small {
  color: var(--primary);
}

.week-head--today strong {
  min-width: 34px;
  padding: 3px 8px;
  border-radius: 15px;
  background: var(--primary);
  color: var(--on-primary);
  font-size: 15px;
  font-weight: 700;
  text-align: center;
}

.week-free {
  position: absolute;
  left: 4px;
  right: 4px;
  height: 52px;
  padding: 8px 10px;
  border-radius: var(--radius-sm);
  background: var(--danger-soft);
  color: var(--danger);
  font-size: 10px;
  font-weight: 600;
  pointer-events: none;
}
</style>
