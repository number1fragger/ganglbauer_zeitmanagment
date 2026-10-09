<script setup lang="ts">
import { computed } from 'vue'
import type { Job } from '@/api/types'
import { workState, workStates } from '@/utils/domain'
import AppIcon from './AppIcon.vue'

const props = defineProps<{ job: Job }>()
const state = computed(() => workState(props.job))
const icon = computed(
  () => (({ open: null, running: 'play', paused: 'pause', done: 'check' }) as const)[state.value],
)
</script>

<template>
  <span
    class="chip status-chip"
    :class="`status-chip--${state}`"
    :style="{ '--chip': workStates[state].color }"
  >
    <span v-if="state === 'running'" class="status-chip__pulse" aria-hidden="true" />
    <AppIcon v-else-if="icon" :name="icon" :size="11" />
    {{ workStates[state].label }}
  </span>
</template>

<style scoped>
.status-chip__pulse {
  width: 7px;
  height: 7px;
  border-radius: 50%;
  background: var(--chip);
  animation: pulse 1.6s ease-in-out infinite;
}

@keyframes pulse {
  50% {
    opacity: 0.35;
  }
}
</style>
