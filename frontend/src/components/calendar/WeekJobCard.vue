<script setup lang="ts">
import type { Job } from '@/api/types'
import { computed } from 'vue'
import { workTimeWarnings } from '@/composables/useWorkingHours'
import { isDone, priorityColor } from '@/utils/domain'
import AppIcon from '../AppIcon.vue'

const props = defineProps<{ job: Job; color: string; conflict?: string[]; asApprentice?: boolean }>()

const outside = computed(() =>
  !isDone(props.job) && props.job.startsAt && props.job.endsAt
    ? workTimeWarnings(new Date(props.job.startsAt), new Date(props.job.endsAt))
    : [],
)
defineEmits<{ open: [job: Job] }>()
</script>

<template>
  <button
    class="week-job accent tinted"
    :class="{
      'week-job--done': isDone(job),
      'week-job--conflict': conflict?.length,
      'week-job--running': job.running,
      'week-job--apprentice': asApprentice,
    }"
    :style="{ '--accent': color }"
    :title="`${job.title}${job.customer ? ' – ' + job.customer : ''}${job.assignee ? ' · ' + job.assignee.fullName : ''}${job.apprentice ? ' + Lehrling ' + job.apprentice.fullName : ''}${conflict?.length ? '\n' + conflict.join('\n') : ''}${outside.length ? '\n' + outside.join('\n') : ''}`"
    @click="$emit('open', job)"
  >
    <span class="week-job__top">
      <strong>{{ job.title }}</strong>
      <AppIcon v-if="conflict?.length" class="week-job__warn" name="layers" :size="11" />
      <AppIcon v-else-if="outside.length" class="week-job__outside" name="clock" :size="11" />
      <span v-else class="dot" :style="{ '--dot': priorityColor(job.priority) }" />
    </span>
    <span v-if="asApprentice && job.apprentice" class="week-job__who" title="Als Lehrling eingeteilt">
      {{ job.apprentice.initials }} · Lehrling
    </span>
    <span v-else-if="job.assignee" class="week-job__who">
      {{ job.assignee.initials }}<template v-if="job.apprentice"> + {{ job.apprentice.initials }}</template>
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
  padding: 6px 6px 6px 9px;
  overflow: hidden;
  border: 0;
  border-radius: var(--radius-sm);
  color: var(--text);
  text-align: left;
  transition: filter 0.15s;
}

.week-job:hover {
  filter: brightness(0.97);
}

.week-job--done {
  opacity: 0.55;
}


.week-job--conflict {
  box-shadow: inset 0 0 0 1.5px var(--danger);
  background-image: repeating-linear-gradient(
    -45deg,
    transparent 0 6px,
    color-mix(in srgb, var(--danger) 10%, transparent) 6px 8px
  );
}

.week-job--apprentice {
  outline: 1.5px dashed color-mix(in srgb, var(--accent) 70%, transparent);
  outline-offset: -1.5px;
}

.week-job__top {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 4px;
}

.week-job__top strong {
  overflow: hidden;
  font-size: 11px;
  font-weight: 600;
  line-height: 1.3;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.week-job__top .dot {
  width: 6px;
  height: 6px;
  margin-top: 3px;
}

.week-job__outside {
  flex: none;
  color: var(--warning);
}

.week-job__warn {
  color: var(--danger);
}

.week-job__who {
  align-self: flex-start;
  padding: 1px 5px;
  border-radius: 7px;
  background: var(--accent);
  color: var(--on-accent);
  font-size: 9px;
  font-weight: 700;
}

.week-job--running .week-job__who {
  box-shadow: 0 0 0 2px color-mix(in srgb, var(--accent) 35%, transparent);
}
</style>
