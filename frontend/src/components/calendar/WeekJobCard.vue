<script setup lang="ts">
import type { Job } from '@/api/types'
import { isDone, priorityColor } from '@/utils/domain'

defineProps<{ job: Job; color: { color: string; soft: string } }>()
defineEmits<{ open: [job: Job] }>()
</script>

<template>
  <button
    class="week-job accent"
    :class="{ 'week-job--done': isDone(job), 'week-job--overrun': job.overrun && !isDone(job) }"
    :style="{ '--accent': color.color, background: color.soft }"
    :title="`${job.title}${job.customer ? ' – ' + job.customer : ''}`"
    @click="$emit('open', job)"
  >
    <span class="week-job__top">
      <strong>{{ job.title }}</strong>
      <span class="dot" :style="{ '--dot': priorityColor(job.priority) }" />
    </span>
    <span v-if="job.assignee" class="week-job__who" :style="{ background: color.color }">
      {{ job.assignee.initials }}
    </span>
  </button>
</template>

<style scoped>
.week-job {
  position: relative;
  display: flex;
  flex: 1;
  flex-direction: column;
  justify-content: space-between;
  min-width: 0;
  padding: 7px 6px 7px 9px;
  overflow: hidden;
  border: 0;
  border-radius: var(--radius-sm);
  text-align: left;
}

.week-job--done {
  opacity: 0.55;
}

.week-job--overrun {
  box-shadow: inset 0 0 0 1.5px var(--danger);
}

.week-job__top {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 4px;
}

.week-job__top strong {
  overflow: hidden;
  font-size: 10px;
  font-weight: 600;
  hyphens: auto;
  overflow-wrap: break-word;
}

.week-job__top .dot {
  width: 6px;
  height: 6px;
  margin-top: 1px;
}

.week-job__who {
  align-self: flex-start;
  padding: 2px 5px;
  border-radius: 7px;
  color: #fff;
  font-size: 8px;
  font-weight: 700;
}
</style>
