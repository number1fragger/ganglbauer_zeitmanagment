<script setup lang="ts">
import { computed } from 'vue'
import type { Absence, Job, OverviewWorker, UserRef } from '@/api/types'
import { segmentsOn, type MoveEvent, type ResizeEvent, type Segment } from '@/utils/calendar'
import { describeShort, toDateKey } from '@/utils/time'
import AbsenceCard from './AbsenceCard.vue'
import CalendarColumn from './CalendarColumn.vue'
import DayJobCard from './DayJobCard.vue'
import TimeGrid from './TimeGrid.vue'

const props = defineProps<{
  day: Date
  workers: OverviewWorker[]
  jobs: Job[]
  absences?: Absence[]
  draggable?: boolean
  canDrag?: (job: Job) => boolean
  conflicts?: Map<number, string[]>
  canOpenAbsence?: boolean
}>()
defineEmits<{
  open: [job: Job]
  openAbsence: [absence: Absence]
  create: [defaults: { startsAt: Date; assigneeId: number | null }]
  move: [event: MoveEvent]
  resize: [event: ResizeEvent]
}>()

interface Column {
  worker: UserRef | null
  freeFrom: string | null
  segments: Segment[]
}

/**
 * Eine Spalte je Person; Auftraege ohne Zuteilung bekommen eine eigene Spalte.
 * Ein Auftrag mit Lehrling steht beim Zustaendigen und beim Lehrling.
 */
const columns = computed<Column[]>(() => {
  const segments = segmentsOn(props.jobs, props.absences ?? [], props.day)
  const forPerson = (id: number | null) => segments.filter((s) => s.personId === id)

  const result: Column[] = props.workers.map((w) => ({
    worker: w.user,
    freeFrom: `frei ab ${describeShort(new Date(w.availableFrom))}`,
    segments: forPerson(w.user.id),
  }))

  const unassigned = forPerson(null)
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
          <small v-if="column.worker?.role === 'lehrling'" class="day-head__role">Lehrling</small>
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
      @resize="$emit('resize', $event)"
    >
      <template #default="{ placed }">
        <AbsenceCard
          v-if="placed.segment.kind === 'absence'"
          :absence="placed.segment.absence"
          :clickable="canOpenAbsence"
          @open="$emit('openAbsence', $event)"
        />
        <DayJobCard
          v-else
          :job="placed.segment.job"
          :as-apprentice="placed.segment.role === 'apprentice'"
          :conflict="conflicts?.get(placed.segment.job.id)"
          @open="$emit('open', $event)"
        />
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

.day-head .day-head__role {
  color: var(--worker-3);
  font-weight: 600;
}
</style>
