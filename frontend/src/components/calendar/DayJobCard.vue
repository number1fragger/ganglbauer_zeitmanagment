<script setup lang="ts">
import { computed } from 'vue'
import type { Job } from '@/api/types'
import { DONE_COLOR, isDone, isMovable, priorityColor, workState } from '@/utils/domain'
import { formatDuration } from '@/utils/time'
import AppIcon from '../AppIcon.vue'

const props = defineProps<{ job: Job; conflict?: string[] }>()
defineEmits<{ open: [job: Job] }>()

const done = computed(() => isDone(props.job))
const state = computed(() =>
  done.value ? 'done' : props.job.overrun ? 'overrun' : workState(props.job),
)

/** Soll, Ist und Kalenderdauer sind verschiedene Dinge – hier kurz zusammengefasst. */
const effort = computed(() => {
  const soll = props.job.plannedMinutes
    ? `Soll ${formatDuration(props.job.plannedMinutes * 60)}`
    : 'ohne Soll'
  return props.job.actualSeconds > 0
    ? `${soll} · Ist ${formatDuration(props.job.actualSeconds)}`
    : soll
})
</script>

<template>
  <button
    class="day-job accent"
    :class="[`day-job--${state}`, { 'day-job--conflict': conflict?.length }]"
    :style="{ '--accent': done ? DONE_COLOR : priorityColor(job.priority) }"
    :title="conflict?.length ? `Überschneidung mit ${conflict.join(', ')}` : job.title"
    @click="$emit('open', job)"
  >
    <span class="day-job__top">
      <strong class="day-job__title">{{ job.title }}</strong>
      <AppIcon v-if="!isMovable(job) && !done" class="day-job__lock" name="timer" :size="12" />
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
      <span
        v-else-if="job.overrun"
        class="badge day-job__badge"
        style="--b: var(--danger)"
        title="Zeit überschritten"
        >!</span
      >
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

.day-job--overrun {
  background: var(--danger-soft);
}

.day-job--running {
  box-shadow: inset 0 0 0 1.5px color-mix(in srgb, var(--primary) 50%, transparent);
}

.day-job--conflict {
  box-shadow: inset 0 0 0 1.5px var(--danger);
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

.day-job--overrun .day-job__effort {
  color: var(--danger);
}
</style>
