<script setup lang="ts">
import { computed } from 'vue'
import type { Job, User } from '@/api/types'
import { canStart, canWorkOn, isDone, isOverdue, priorityColor, workState } from '@/utils/domain'
import {
  formatDayMonth,
  formatDuration,
  formatRange,
  formatTime,
  sameDay,
  spanDays,
} from '@/utils/time'
import AppIcon from './AppIcon.vue'
import LiveDuration from './LiveDuration.vue'
import PriorityChip from './PriorityChip.vue'

/** Karte in der Aufgabenverwaltung (Kanban) und in "Meine Arbeiten". */
const props = defineProps<{
  job: Job
  user: User | null
  busy?: boolean
  draggable?: boolean
  conflict?: string[]
}>()
const emit = defineEmits<{
  open: [job: Job]
  start: [job: Job]
  pause: [job: Job]
  complete: [job: Job]
}>()

const state = computed(() => workState(props.job))
const overdue = computed(() => isOverdue(props.job))

const when = computed(() => {
  const { startsAt, endsAt } = props.job
  if (!startsAt || !endsAt) return null
  const start = new Date(startsAt)
  const end = new Date(endsAt)
  const days = spanDays(start, end)
  if (days > 1)
    return {
      short: `${formatDayMonth(start)} – ${formatDayMonth(end)}`,
      long: formatRange(start, end),
    }
  const today = sameDay(start, new Date())
  return {
    short: `${today ? 'Heute' : formatDayMonth(start)}, ${formatTime(start)}–${formatTime(end)}`,
    long: formatRange(start, end),
  }
})
</script>

<template>
  <article
    class="task accent"
    :class="[`task--${state}`, { 'task--overdue': overdue, 'task--draggable': draggable }]"
    :style="{ '--accent': isDone(job) ? 'var(--faint)' : priorityColor(job.priority) }"
    :draggable="draggable"
    tabindex="0"
    :aria-label="`${job.title}, öffnen`"
    @click="emit('open', job)"
    @keydown.enter.self="emit('open', job)"
  >
    <header class="task__head">
      <h3 class="task__title">{{ job.title }}</h3>
      <span v-if="job.assignee" class="avatar avatar--small" :title="job.assignee.fullName">{{
        job.assignee.initials
      }}</span>
    </header>

    <p v-if="job.customer" class="task__customer">{{ job.customer }}</p>

    <div class="task__chips">
      <PriorityChip v-if="!isDone(job)" :priority="job.priority" />
      <span
        v-if="when"
        class="chip"
        :class="{ 'chip--plain': !overdue }"
        :style="overdue ? { '--chip': 'var(--danger)' } : undefined"
        :title="when.long"
      >
        <AppIcon :name="overdue ? 'alert' : 'calendar'" :size="11" /> {{ when.short }}
      </span>
      <span
        v-else-if="!isDone(job)"
        class="chip chip--plain"
        title="Noch nicht im Kalender eingeplant"
      >
        <AppIcon name="calendar-off" :size="11" /> Ohne Termin
      </span>
      <span
        v-if="conflict?.length"
        class="chip"
        style="--chip: var(--danger)"
        :title="`Überschneidung mit ${conflict.join(', ')}`"
      >
        <AppIcon name="layers" :size="11" /> Konflikt
      </span>
    </div>

    <footer v-if="job.started || job.plannedMinutes" class="task__foot">
      <span
        v-if="job.started"
        class="task__time"
        :class="{ 'task__time--over': job.overrun && !isDone(job) }"
        title="Ist-Zeit / geplante Zeit"
      >
        <span v-if="state === 'running'" class="task__live" aria-hidden="true" />
        <AppIcon v-else name="clock" :size="12" />
        <LiveDuration :job="job" />
        <template v-if="job.plannedMinutes">
          / {{ formatDuration(job.plannedMinutes * 60) }}</template
        >
      </span>
      <span v-else class="task__time" title="Geplante Arbeitszeit">
        <AppIcon name="timer" :size="12" />
        {{ formatDuration((job.plannedMinutes ?? 0) * 60) }} geplant
      </span>
      <span v-if="state === 'paused'" class="task__state">pausiert</span>
    </footer>

    <div v-if="!isDone(job) && canWorkOn(job, user)" class="task__actions" @click.stop>
      <button
        v-if="canStart(job, user)"
        class="task__action task__action--start"
        :disabled="busy"
        :aria-label="job.status === 'in_arbeit' ? 'Fortsetzen' : 'Arbeit starten'"
        :data-tip="job.status === 'in_arbeit' ? 'Fortsetzen' : 'Starten'"
        @click="emit('start', job)"
      >
        <AppIcon name="play" :size="13" />
      </button>
      <button
        v-if="job.running"
        class="task__action task__action--pause"
        :disabled="busy"
        aria-label="Pausieren"
        data-tip="Pausieren"
        @click="emit('pause', job)"
      >
        <AppIcon name="pause" :size="13" />
      </button>
      <button
        class="task__action task__action--done"
        :disabled="busy"
        aria-label="Abschließen"
        data-tip="Abschließen"
        @click="emit('complete', job)"
      >
        <AppIcon name="check" :size="14" />
      </button>
    </div>
  </article>
</template>

<style scoped>
.task {
  position: relative;
  display: flex;
  flex-direction: column;
  gap: 8px;
  padding: 12px 12px 12px 15px;
  border: 1px solid var(--border);
  border-radius: var(--radius);
  background: var(--surface);
  box-shadow: var(--shadow-sm);
  cursor: pointer;
  transition:
    box-shadow 0.15s,
    border-color 0.15s,
    transform 0.15s;
}

.task:hover {
  border-color: var(--border-strong);
  box-shadow: var(--shadow);
}

.task--draggable {
  cursor: grab;
}

.task--done {
  background: var(--surface-alt);
}

.task--done .task__title {
  color: var(--muted);
  text-decoration: line-through;
  text-decoration-color: var(--faint);
}

.task--running {
  border-color: color-mix(in srgb, var(--primary) 45%, var(--border));
}

.task__head {
  display: flex;
  align-items: flex-start;
  gap: 8px;
}

.task__title {
  flex: 1;
  min-width: 0;
  font-size: 13px;
  font-weight: 600;
  line-height: 1.35;
  overflow-wrap: anywhere;
}

.task__customer {
  margin-top: -4px;
  color: var(--muted);
  font-size: 12px;
}

.task__chips {
  display: flex;
  flex-wrap: wrap;
  gap: 4px;
}

.task__foot {
  min-height: 28px;
  padding-right: 96px;
  display: flex;
  align-items: center;
  gap: 8px;
  color: var(--muted);
  font-size: 11px;
  font-weight: 500;
}

.task__time {
  display: inline-flex;
  align-items: center;
  gap: 5px;
}

.task__time--over {
  color: var(--danger);
}

.task__live {
  width: 7px;
  height: 7px;
  border-radius: 50%;
  background: var(--primary);
  animation: pulse 1.6s ease-in-out infinite;
}

.task__state {
  color: var(--warning-ink);
}

.task__actions {
  position: absolute;
  right: 8px;
  bottom: 8px;
  display: flex;
  gap: 4px;
  opacity: 0;
  transition: opacity 0.15s;
}

.task:hover .task__actions,
.task:focus-within .task__actions,
.task--running .task__actions {
  opacity: 1;
}

.task__action {
  display: grid;
  place-items: center;
  width: 28px;
  height: 28px;
  border: 1px solid var(--border);
  border-radius: 50%;
  background: var(--surface);
  color: var(--muted);
}

.task__action:hover:not(:disabled) {
  color: var(--text);
  background: var(--surface-muted);
}

.task__action--start {
  color: var(--primary);
}

.task__action--pause {
  color: var(--warning-ink);
}

.task__action--done:hover:not(:disabled) {
  border-color: var(--success);
  background: var(--success-soft);
  color: var(--success);
}

@keyframes pulse {
  50% {
    opacity: 0.3;
  }
}

/* Touch: Aktionen immer sichtbar und groesser */
@media (hover: none) {
  .task__foot {
    padding-right: 0;
  }

  .task__actions {
    position: static;
    justify-content: flex-end;
    opacity: 1;
  }

  .task__action {
    width: 40px;
    height: 40px;
  }
}
</style>
