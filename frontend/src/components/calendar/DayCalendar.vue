<script setup lang="ts">
import { computed } from 'vue'
import type { Job, OverviewWorker, UserRef } from '@/api/types'
import { segmentOn, type MoveEvent, type Segment } from '@/utils/calendar'
import { describeShort, toDateKey } from '@/utils/time'
import CalendarColumn from './CalendarColumn.vue'
import DayJobCard from './DayJobCard.vue'
import TimeGrid from './TimeGrid.vue'

const props = defineProps<{
  day: Date
  workers: OverviewWorker[]
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

interface Column {
  worker: UserRef | null
  freeFrom: string | null
  segments: Segment[]
}

/** Eine Spalte je Arbeiter; Arbeiten ohne Zuteilung bekommen eine eigene Spalte. */
const columns = computed<Column[]>(() => {
  const segments = props.jobs.map((job) => segmentOn(job, props.day)).filter((s): s is Segment => s !== null)
  const forWorker = (id: number | null) => segments.filter((s) => (s.job.assignee?.id ?? null) === id)

  const result: Column[] = props.workers.map((w) => ({
    worker: w.user,
    freeFrom: `frei ab ${describeShort(new Date(w.availableFrom))}`,
    segments: forWorker(w.user.id),
  }))

  const unassigned = forWorker(null)
  if (unassigned.length) result.push({ worker: null, freeFrom: null, segments: unassigned })

  return result
})
</script>

<template>
  <TimeGrid :columns="columns.length" :min-column-width="170">
    <template #head>
      <div v-for="column in columns" :key="column.worker?.id ?? 'none'" class="day-head">
        <span class="avatar">{{ column.worker?.initials ?? '?' }}</span>
        <span>
          <strong>{{ column.worker?.fullName ?? 'Nicht zugeteilt' }}</strong>
          <small v-if="column.freeFrom">{{ column.freeFrom }}</small>
        </span>
      </div>
    </template>

    <CalendarColumn
      v-for="column in columns"
      :key="column.worker?.id ?? 'none'"
      :day="day"
      :segments="column.segments"
      :gap="8"
      :draggable="draggable"
      :can-drag="canDrag"
      :drop-key="`${toDateKey(day)}:${column.worker?.id ?? 'none'}`"
      :drop-assignee="column.worker?.id ?? null"
      @create="$emit('create', { startsAt: $event, assigneeId: column.worker?.id ?? null })"
      @move="$emit('move', $event)"
    >
      <template #default="{ segment }">
        <DayJobCard :job="segment.job" :conflict="conflicts?.get(segment.job.id)" @open="$emit('open', $event)" />
      </template>
    </CalendarColumn>
  </TimeGrid>
</template>

<style scoped>
.day-head {
  display: flex;
  align-items: center;
  gap: 8px;
  padding: 8px 16px;
  border-left: 1px solid var(--border);
}

.day-head strong {
  display: block;
  font-size: 13px;
  font-weight: 600;
}

.day-head small {
  display: block;
  margin-top: 2px;
  color: var(--muted);
  font-size: 11px;
}
</style>
