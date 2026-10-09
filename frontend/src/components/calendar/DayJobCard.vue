<script setup lang="ts">
import { computed } from 'vue'
import type { Job } from '@/api/types'
import { DONE_COLOR, isDone, priorityColor } from '@/utils/domain'
import { formatHours } from '@/utils/time'

const props = defineProps<{ job: Job }>()
defineEmits<{ open: [job: Job] }>()

const done = computed(() => isDone(props.job))
const state = computed(() => (done.value ? 'done' : props.job.overrun ? 'overrun' : 'open'))

const effort = computed(() => {
  const soll = `Soll ${formatHours(props.job.plannedMinutes)}`
  return props.job.actualMinutes > 0 ? `${soll} · Ist ${formatHours(props.job.actualMinutes)}` : soll
})
</script>

<template>
  <button
    class="day-job accent"
    :class="`day-job--${state}`"
    :style="{ '--accent': done ? DONE_COLOR : priorityColor(job.priority) }"
    @click="$emit('open', job)"
  >
    <span class="day-job__top">
      <strong class="day-job__title">{{ job.title }}</strong>
      <span v-if="done" class="badge" style="background: var(--success)" title="Erledigt">OK</span>
      <span v-else-if="job.overrun" class="badge day-job__alert" title="Zeit überschritten">!</span>
      <span v-else-if="job.running" class="badge" style="background: var(--primary)">läuft</span>
    </span>
    <span v-if="job.customer" class="day-job__customer">Kunde: {{ job.customer }}</span>
    <span class="day-job__effort">{{ effort }}</span>
  </button>
</template>

<style scoped>
.day-job {
  position: relative;
  display: flex;
  flex: 1;
  flex-direction: column;
  gap: 4px;
  min-width: 0;
  padding: 10px 12px 10px 14px;
  overflow: hidden;
  border: 0;
  border-radius: var(--radius);
  background: var(--job);
  text-align: left;
}

.day-job.accent::before {
  width: 4px;
}

.day-job:hover {
  filter: brightness(0.98);
}

.day-job--done {
  background: var(--surface-muted);
}

.day-job--overrun {
  background: var(--danger-soft);
}

.day-job__top {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 8px;
}

.day-job__title {
  overflow: hidden;
  font-size: 12px;
  font-weight: 600;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.day-job--done .day-job__title {
  color: var(--muted);
}

.day-job__alert {
  background: var(--danger);
  font-size: 11px;
}

.day-job__customer,
.day-job__effort {
  overflow: hidden;
  color: var(--muted);
  font-size: 10px;
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
