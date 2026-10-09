<script setup lang="ts">
import type { Priority } from '@/api/types'
import { priorityColor, priorityLabel } from '@/utils/domain'
import AppIcon from './AppIcon.vue'

defineProps<{ priority: Priority }>()
</script>

<template>
  <span
    class="chip"
    :style="{ '--chip': priorityColor(priority) }"
    :title="`Priorität ${priorityLabel(priority)}`"
  >
    <AppIcon name="flag" :size="11" />
    {{ priorityLabel(priority) }}
  </span>
</template>
