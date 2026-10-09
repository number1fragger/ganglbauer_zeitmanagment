<script setup lang="ts">
import { computed } from 'vue'
import type { Job, OverviewWorker } from '@/api/types'
import { segmentOn } from '@/utils/calendar'
import { workerColor } from '@/utils/domain'
import { addDays, describeShort, formatHours } from '@/utils/time'
import PriorityLegend from '../PriorityLegend.vue'

const props = defineProps<{ weekStart: Date; workers: OverviewWorker[]; jobs: Job[] }>()
const visible = defineModel<number[]>('visible', { required: true })

function toggle(id: number): void {
  visible.value = visible.value.includes(id) ? visible.value.filter((v) => v !== id) : [...visible.value, id]
}

/** Geplante Stunden in der Woche (Mo–Fr) gegenueber der Wochenarbeitszeit. */
const load = computed(() =>
  props.workers.map((worker, index) => {
    const planned = Array.from({ length: 5 }, (_, i) => addDays(props.weekStart, i)).reduce((sum, day) => {
      const hours = props.jobs
        .filter((job) => job.assignee?.id === worker.user.id)
        .map((job) => segmentOn(job, day))
        .reduce((daySum, s) => daySum + (s ? s.to - s.from : 0), 0)
      return sum + hours
    }, 0)

    return {
      worker,
      color: workerColor(index),
      plannedMinutes: planned * 60,
      percent: Math.min(100, (planned / worker.user.weeklyHours) * 100),
    }
  }),
)

/** Wer noch in dieser Woche frei wird, braucht bald neue Arbeit. */
const needWorkSoon = computed(() => {
  const weekEnd = addDays(props.weekStart, 5)
  return props.workers
    .filter((w) => new Date(w.availableFrom) < weekEnd)
    .sort((a, b) => a.availableFrom.localeCompare(b.availableFrom))
})
</script>

<template>
  <aside class="sidebar">
    <section>
      <h2 class="section-title">Arbeiter anzeigen</h2>
      <ul class="filter">
        <li v-for="(worker, index) in workers" :key="worker.user.id">
          <label :style="{ '--color': workerColor(index) }">
            <input type="checkbox" :checked="visible.includes(worker.user.id)" @change="toggle(worker.user.id)" />
            {{ worker.user.fullName }}
          </label>
        </li>
      </ul>
    </section>

    <section>
      <h2 class="section-title">Wochenauslastung</h2>
      <ul class="load">
        <li v-for="row in load" :key="row.worker.user.id">
          <span class="load__label">
            <span>{{ row.worker.user.fullName }}</span>
            <small>
              {{ formatHours(row.plannedMinutes).replace(' h', '') }} /
              {{ formatHours(row.worker.user.weeklyHours * 60) }}
            </small>
          </span>
          <span class="load__track">
            <span class="load__bar" :style="{ width: `${row.percent}%`, background: row.color }" />
          </span>
        </li>
      </ul>
    </section>

    <section v-if="needWorkSoon.length" class="card accent soon">
      <h2>Braucht bald neue Arbeit</h2>
      <p v-for="worker in needWorkSoon" :key="worker.user.id">
        {{ worker.user.fullName }} – ab {{ describeShort(new Date(worker.availableFrom)) }} frei
      </p>
    </section>

    <PriorityLegend />
  </aside>
</template>

<style scoped>
.sidebar {
  display: flex;
  flex-direction: column;
  gap: 32px;
  padding: 20px 20px 32px;
}

.filter,
.load {
  display: grid;
  margin: 14px 0 0;
  padding: 0;
  list-style: none;
}

.filter {
  gap: 12px;
}

.filter label {
  display: flex;
  align-items: center;
  gap: 10px;
  font-size: 12px;
  font-weight: 500;
  cursor: pointer;
}

.filter input {
  width: 16px;
  height: 16px;
  margin: 0;
  accent-color: var(--color);
}

.load {
  gap: 18px;
  max-width: 320px;
}

.load__label {
  display: flex;
  justify-content: space-between;
  font-size: 11px;
  font-weight: 500;
}

.load__label small {
  color: var(--muted);
  font-size: 10px;
  font-weight: 400;
}

.load__track {
  display: block;
  height: 8px;
  margin-top: 8px;
  border-radius: 4px;
  background: var(--grid);
}

.load__bar {
  display: block;
  height: 100%;
  border-radius: 4px;
}

.soon {
  --accent: var(--danger);
  display: grid;
  gap: 8px;
  padding: 14px 16px;
  background: var(--danger-soft);
}

.soon h2 {
  margin-bottom: 4px;
  color: var(--danger);
  font-size: 12px;
  font-weight: 600;
}

.soon p {
  font-size: 11px;
  font-weight: 500;
}
</style>
