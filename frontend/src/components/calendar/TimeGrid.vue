<script setup lang="ts">
import { DAY_HEIGHT, HOUR_PX, hourToPx, hours } from '@/utils/calendar'

defineProps<{ columns: number; minColumnWidth: number }>()
</script>

<template>
  <div class="time-grid" :style="{ '--cols': columns, '--min-col': `${minColumnWidth}px` }">
    <div class="time-grid__head">
      <span class="time-grid__corner">Zeit</span>
      <slot name="head" />
    </div>
    <div class="time-grid__body">
      <div class="time-grid__axis" aria-hidden="true">
        <span v-for="hour in hours" :key="hour" :style="{ top: `${hourToPx(hour) - 6}px` }">
          {{ String(hour).padStart(2, '0') }}:00
        </span>
      </div>
      <div class="time-grid__columns" :style="{ height: `${DAY_HEIGHT + 1}px`, '--hour': `${HOUR_PX}px` }">
        <slot />
      </div>
    </div>
  </div>
</template>

<style scoped>
.time-grid {
  --axis: 64px;
  min-width: calc(var(--axis) + var(--cols) * var(--min-col));
}

.time-grid__head,
.time-grid__body {
  display: grid;
  grid-template-columns: var(--axis) repeat(var(--cols), minmax(var(--min-col), 1fr));
}

.time-grid__head {
  position: sticky;
  z-index: 3;
  top: 0;
  min-height: 68px;
  border-bottom: 1px solid var(--border);
  background: var(--surface);
}

.time-grid__corner {
  align-self: end;
  padding: 0 0 16px 16px;
  color: var(--muted);
  font-size: 10px;
  font-weight: 500;
}

.time-grid__body {
  padding: 10px 0 24px;
}

.time-grid__axis {
  position: relative;
}

.time-grid__axis span {
  position: absolute;
  left: 16px;
  color: var(--muted);
  font-size: 10px;
}

.time-grid__columns {
  display: grid;
  grid-column: 2 / -1;
  grid-template-columns: subgrid;
  background: repeating-linear-gradient(to bottom, var(--grid) 0 1px, transparent 1px var(--hour));
}
</style>
