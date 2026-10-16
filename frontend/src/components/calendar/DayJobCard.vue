<script setup lang="ts">
import { computed } from 'vue'
import type { Job } from '@/api/types'
import { workTimeWarnings } from '@/composables/useWorkingHours'
import { DONE_COLOR, isDone, isMovable, priorityColor, workState } from '@/utils/domain'
import { formatDuration } from '@/utils/time'
import AppIcon from '../AppIcon.vue'

const props = defineProps<{ job: Job; conflict?: string[]; asApprentice?: boolean }>()
defineEmits<{ open: [job: Job] }>()

const done = computed(() => isDone(props.job))

/** Beginn/Ende ausserhalb der Arbeitszeit oder in der Pause – nicht verhindert, aber markiert. */
const outside = computed(() =>
  !done.value && props.job.startsAt && props.job.endsAt
    ? workTimeWarnings(new Date(props.job.startsAt), new Date(props.job.endsAt))
    : [],
)
const state = computed(() => (done.value ? 'done' : workState(props.job)))

/** Ist-Zeit, sobald gearbeitet wurde – sonst geplante Dauer (Arbeitszeit) und Team. */
const effort = computed(() => {
  if (props.job.actualSeconds > 0) return `Ist ${formatDuration(props.job.actualSeconds)}`
  const duration = props.job.workMinutes ? `${formatDuration(props.job.workMinutes * 60)} · ` : ''
  if (props.asApprentice) return `${duration}mit ${props.job.assignee?.shortName ?? 'niemandem'}`
  const team = props.job.assignee?.shortName ?? 'Nicht zugeteilt'
  return `${duration}${team}${props.job.apprentice ? ` + ${props.job.apprentice.initials}` : ''}`
})
</script>

<template>
  <button
    class="day-job accent"
    :class="[
      `day-job--${state}`,
      { 'day-job--conflict': conflict?.length, 'day-job--apprentice': asApprentice },
    ]"
    :style="{ '--accent': done ? DONE_COLOR : priorityColor(job.priority) }"
    :title="
      (asApprentice ? `Als Lehrling eingeteilt – ${job.title}` : job.title) +
      (conflict?.length ? `\n${conflict.join('\n')}` : '') +
      (outside.length ? `\n${outside.join('\n')}` : '')
    "
    @click="$emit('open', job)"
  >
    <span class="day-job__top">
      <strong class="day-job__title">{{ job.title }}</strong>
      <span v-if="asApprentice" class="badge day-job__badge" style="--b: var(--worker-3)">Lehrling</span>
      <AppIcon v-if="!isMovable(job) && !done" class="day-job__lock" name="timer" :size="12" />
      <AppIcon
        v-if="outside.length"
        class="day-job__outside"
        name="clock"
        :size="12"
        :aria-label="outside.join(' ')"
      />
      <span
        v-if="done"
        class="badge day-job__badge"
        style="--b: var(--success)"
        title="Abgeschlossen"
        ><AppIcon name="check" :size="10"
      /></span>
      <span
        v-else-if="conflict?.length"
        class="badge day-job__badge"
        style="--b: var(--danger)"
        title="Terminkonflikt"
        ><AppIcon name="layers" :size="10"
      /></span>
      <span v-else-if="job.running" class="badge day-job__badge" style="--b: var(--primary)"
        >läuft</span
      >
      <span v-else-if="job.paused" class="badge day-job__badge" style="--b: var(--warning)"
        >pausiert</span
      >
    </span>
    <span v-if="job.customer" class="day-job__customer">{{ job.customer }}</span>
    <span class="day-job__effort">{{ effort }}</span>
  </button>
</template>

<style scoped>
.day-job {
  position: relative;
  display: flex;
  flex: 1;
  flex-direction: column;
  gap: 3px;
  min-width: 0;
  padding: 8px 10px 8px 13px;
  overflow: hidden;
  border: 0;
  border-radius: var(--radius);
  background: var(--job);
  color: var(--text);
  text-align: left;
  transition: filter 0.15s;
}

.day-job.accent::before {
  width: 4px;
}

.day-job:hover {
  filter: brightness(0.97);
}

.day-job--done {
  background: var(--surface-muted);
}

.day-job--running {
  box-shadow: inset 0 0 0 1.5px color-mix(in srgb, var(--primary) 50%, transparent);
}

.day-job--conflict {
  box-shadow: inset 0 0 0 1.5px var(--danger);
  background-image: repeating-linear-gradient(
    -45deg,
    transparent 0 6px,
    color-mix(in srgb, var(--danger) 8%, transparent) 6px 8px
  );
}

/* Kopie beim Lehrling: derselbe Auftrag, gestrichelt umrandet */
.day-job--apprentice {
  outline: 1.5px dashed color-mix(in srgb, var(--worker-3) 70%, transparent);
  outline-offset: -1.5px;
}

.day-job__top {
  display: flex;
  align-items: flex-start;
  gap: 6px;
}

.day-job__title {
  flex: 1;
  overflow: hidden;
  font-size: 12px;
  font-weight: 600;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.day-job--done .day-job__title {
  color: var(--muted);
}

.day-job__outside {
  margin-top: 1px;
  color: var(--warning);
}

.day-job__lock {
  margin-top: 1px;
  color: var(--muted);
}

.day-job__badge {
  height: 17px;
  background: var(--b);
  color: var(--on-accent);
  font-size: 9px;
}

.day-job__customer,
.day-job__effort {
  overflow: hidden;
  color: var(--muted);
  font-size: 11px;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.day-job__effort {
  margin-top: auto;
  font-weight: 500;
}
</style>
