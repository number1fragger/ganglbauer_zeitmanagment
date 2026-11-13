<script setup lang="ts">
import { computed } from 'vue'
import type { Absence } from '@/api/types'
import { formatDayMonth, formatTime, sameDay } from '@/utils/time'

/**
 * Abwesenheit im Kalender – bewusst anders als ein Auftrag (gestreift,
 * violett, "Abwesend: ..."). Zeigt nur die Art, keine Notiz.
 */
const props = defineProps<{ absence: Absence; compact?: boolean; clickable?: boolean }>()
defineEmits<{ open: [absence: Absence] }>()

const period = computed(() => {
  const start = new Date(props.absence.startsAt)
  const end = new Date(props.absence.endsAt)
  if (props.absence.allDay) {
    const last = new Date(props.absence.lastDay)
    return sameDay(start, last) ? 'ganztägig' : `${formatDayMonth(start)} – ${formatDayMonth(last)}`
  }
  return sameDay(start, end)
    ? `${formatTime(start)} – ${formatTime(end)}`
    : `${formatDayMonth(start)}, ${formatTime(start)} – ${formatDayMonth(end)}, ${formatTime(end)}`
})
</script>

<template>
  <component
    :is="clickable ? 'button' : 'div'"
    class="absence"
    :class="{ 'absence--compact': compact, 'absence--clickable': clickable }"
    :title="`${absence.user.fullName} – Abwesend: ${absence.label} (${period})`"
    @click="clickable && $emit('open', absence)"
  >
    <strong class="absence__title">Abwesend: {{ absence.label }}</strong>
    <span v-if="!compact" class="absence__who">{{ absence.user.fullName }}</span>
    <span v-else class="absence__who">{{ absence.user.initials }}</span>
    <span v-if="!compact" class="absence__period">{{ period }}</span>
  </component>
</template>

<style scoped>
.absence {
  display: flex;
  flex: 1;
  flex-direction: column;
  gap: 2px;
  min-width: 0;
  padding: 6px 8px;
  overflow: hidden;
  border: 1px dashed var(--absence);
  border-radius: var(--radius);
  background:
    repeating-linear-gradient(
      135deg,
      color-mix(in srgb, var(--absence) 14%, transparent) 0 6px,
      transparent 6px 12px
    ),
    var(--absence-soft);
  color: var(--absence-ink);
  font: inherit;
  text-align: left;
}

.absence--clickable {
  cursor: pointer;
}

.absence--clickable:hover {
  filter: brightness(0.97);
}

.absence--compact {
  padding: 4px 5px;
  border-radius: var(--radius-sm);
}

.absence__title {
  overflow: hidden;
  font-size: 11px;
  font-weight: 700;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.absence--compact .absence__title {
  font-size: 10px;
}

.absence__who,
.absence__period {
  overflow: hidden;
  font-size: 10px;
  text-overflow: ellipsis;
  white-space: nowrap;
}
</style>
