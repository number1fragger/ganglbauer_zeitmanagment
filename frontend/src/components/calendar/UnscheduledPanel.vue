<script setup lang="ts">
import type { Job } from '@/api/types'
import { beginDrag, drag } from '@/composables/useJobDrag'
import type { MoveEvent } from '@/utils/calendar'
import { priorityColor } from '@/utils/domain'
import { formatDuration } from '@/utils/time'
import AppIcon from '../AppIcon.vue'

/**
 * Aufgaben ohne Termin neben dem Kalender. Per Drag & Drop in den
 * Kalender ziehen plant sie ein; Klick oeffnet die Aufgabe.
 */
defineProps<{ jobs: Job[]; draggable?: boolean }>()
const emit = defineEmits<{ open: [job: Job]; move: [event: MoveEvent] }>()
</script>

<template>
  <section class="unscheduled">
    <header class="unscheduled__head">
      <h2 class="section-title">Nicht eingeplant</h2>
      <span class="unscheduled__count">{{ jobs.length }}</span>
    </header>
    <p class="section-sub">
      {{
        draggable ? 'In den Kalender ziehen, um einen Termin festzulegen.' : 'Aufgaben ohne Termin.'
      }}
    </p>
    <ul v-if="jobs.length" class="unscheduled__list">
      <li
        v-for="job in jobs"
        :key="job.id"
        class="unscheduled__item accent"
        :class="{
          'unscheduled__item--dragging': drag.active && drag.job?.id === job.id,
          'unscheduled__item--draggable': draggable,
        }"
        :style="{ '--accent': priorityColor(job.priority) }"
        @pointerdown="draggable && beginDrag($event, job, (event) => emit('move', event))"
      >
        <button class="unscheduled__open" @click="emit('open', job)">
          <strong>{{ job.title }}</strong>
          <small>
            {{ job.assignee?.shortName ?? 'Nicht zugeteilt' }}
            <template v-if="job.plannedMinutes">
              · {{ formatDuration(job.plannedMinutes * 60) }}</template
            >
          </small>
        </button>
        <AppIcon v-if="draggable" class="unscheduled__grip" name="grip" :size="14" />
      </li>
    </ul>
    <p v-else class="empty">Alle Aufgaben sind eingeplant.</p>
  </section>
</template>

<style scoped>
.unscheduled {
  display: flex;
  flex-direction: column;
  gap: 4px;
}

.unscheduled__head {
  display: flex;
  align-items: center;
  gap: 8px;
}

.unscheduled__count {
  padding: 1px 7px;
  border-radius: 10px;
  background: var(--surface-muted);
  color: var(--muted);
  font-size: 11px;
  font-weight: 600;
}

.unscheduled__list {
  display: grid;
  gap: 6px;
  max-height: 260px;
  margin: 10px 0 0;
  padding: 0;
  overflow-y: auto;
  list-style: none;
}

.unscheduled__item {
  position: relative;
  display: flex;
  align-items: center;
  border: 1px solid var(--border);
  border-radius: var(--radius);
  background: var(--surface);
}

.unscheduled__item--draggable {
  cursor: grab;
  touch-action: none;
  user-select: none;
}

.unscheduled__item--dragging {
  opacity: 0.35;
}

.unscheduled__open {
  display: flex;
  flex: 1;
  flex-direction: column;
  gap: 2px;
  min-width: 0;
  padding: 8px 10px 8px 13px;
  border: 0;
  background: none;
  text-align: left;
  cursor: inherit;
}

.unscheduled__open strong {
  overflow: hidden;
  font-size: 12px;
  font-weight: 600;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.unscheduled__open small {
  color: var(--muted);
  font-size: 11px;
}

.unscheduled__grip {
  margin-right: 8px;
  color: var(--faint);
}
</style>
