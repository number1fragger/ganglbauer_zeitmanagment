<script setup lang="ts">
import { computed } from 'vue'
import type { Job, OverviewWorker } from '@/api/types'
import { useNow } from '@/composables/useNow'
import { workerColor } from '@/utils/domain'
import { addDays, isWeekend, sameDay, startOfDay, startOfMonth, startOfWeek } from '@/utils/time'

const MAX_CHIPS = 3
const WEEKDAYS = ['Mo', 'Di', 'Mi', 'Do', 'Fr', 'Sa', 'So']

const props = defineProps<{ month: Date; workers: OverviewWorker[]; jobs: Job[]; conflicts?: Map<number, string[]> }>()
defineEmits<{ open: [job: Job]; showDay: [day: Date] }>()

const now = useNow()

const colorOf = (job: Job) => workerColor(props.workers.findIndex((w) => w.user.id === job.assignee?.id))

/** Ganze Wochen von Montag vor dem Monatsersten bis Sonntag nach dem Letzten. */
const cells = computed(() => {
  const first = startOfMonth(props.month)
  const last = addDays(new Date(first.getFullYear(), first.getMonth() + 1, 1), -1)
  const start = startOfWeek(first)
  const count = Math.round((startOfWeek(last).getTime() - start.getTime()) / 86_400_000) + 7

  return Array.from({ length: count }, (_, i) => {
    const day = addDays(start, i)
    const next = addDays(day, 1)
    const jobs = props.jobs.filter((job) => !!job.startsAt && !!job.endsAt && new Date(job.startsAt) < next && new Date(job.endsAt) > day)

    return {
      day,
      jobs,
      inMonth: day.getMonth() === first.getMonth(),
      weekend: isWeekend(day),
      today: sameDay(day, now.value),
      free: jobs.length === 0 && !isWeekend(day) && day >= startOfDay(now.value),
    }
  })
})
</script>

<template>
  <div class="month">
    <div class="month__weekdays">
      <span v-for="(name, i) in WEEKDAYS" :key="name" :class="{ 'month__weekend-name': i >= 5 }">{{ name }}</span>
    </div>
    <div class="month__grid">
      <div
        v-for="cell in cells"
        :key="cell.day.toISOString()"
        class="month__cell"
        :class="{ 'month__cell--weekend': cell.weekend }"
        @click.self="$emit('showDay', cell.day)"
      >
        <button
          class="month__date"
          :class="{ 'month__date--today': cell.today, 'month__date--faded': !cell.inMonth || cell.weekend }"
          :aria-label="`Tagesansicht ${cell.day.toLocaleDateString('de-AT')}`"
          @click="$emit('showDay', cell.day)"
        >
          {{ cell.day.getDate() }}
        </button>

        <button
          v-for="job in cell.jobs.slice(0, MAX_CHIPS)"
          :key="job.id"
          class="month__chip accent tinted"
          :class="{ 'month__chip--done': job.status === 'erledigt', 'month__chip--conflict': conflicts?.has(job.id) }"
          :style="{ '--accent': colorOf(job) }"
          :title="`${job.title}${job.assignee ? ' · ' + job.assignee.fullName : ''}${conflicts?.has(job.id) ? ' – Terminkonflikt' : ''}`"
          @click="$emit('open', job)"
        >
          {{ job.assignee?.initials ?? '–' }} · {{ job.title }}
        </button>

        <button v-if="cell.jobs.length > MAX_CHIPS" class="link month__more" @click="$emit('showDay', cell.day)">
          +{{ cell.jobs.length - MAX_CHIPS }} weitere
        </button>
        <span v-else-if="cell.free" class="month__free">noch frei</span>
      </div>
    </div>
  </div>
</template>

<style scoped>
.month {
  min-width: 840px;
}

.month__weekdays,
.month__grid {
  display: grid;
  grid-template-columns: repeat(7, minmax(0, 1fr));
}

.month__weekdays {
  border-bottom: 1px solid var(--border);
}

.month__weekdays span {
  padding: 14px;
  color: var(--muted);
  font-size: 11px;
  font-weight: 600;
}

.month__weekdays .month__weekend-name {
  color: var(--faint);
}

.month__cell {
  display: flex;
  flex-direction: column;
  gap: 5px;
  min-height: 172px;
  padding: 8px;
  border-bottom: 1px solid var(--grid);
  border-left: 1px solid var(--grid);
  background: var(--surface);
  cursor: pointer;
}

.month__cell--weekend {
  background: var(--surface-alt);
}

.month__date {
  align-self: flex-start;
  min-width: 26px;
  height: 24px;
  margin-bottom: 6px;
  padding: 0 6px;
  border: 0;
  border-radius: 12px;
  background: none;
  font-size: 12px;
  font-weight: 600;
}

.month__date--faded {
  color: var(--faint);
}

.month__date--today {
  background: var(--primary);
  color: var(--on-primary);
  font-weight: 700;
}

.month__chip {
  position: relative;
  height: 21px;
  padding: 0 8px;
  overflow: hidden;
  border: 0;
  border-radius: 5px;
  font-size: 10px;
  font-weight: 500;
  text-align: left;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.month__chip--conflict {
  box-shadow: inset 0 0 0 1.5px var(--danger);
}

.month__chip--done {
  opacity: 0.55;
}

.month__more {
  align-self: flex-start;
  margin: 2px 0 0 6px;
  font-size: 10px;
  font-weight: 600;
}

.month__free {
  margin-left: 6px;
  color: var(--faint);
  font-size: 10px;
}
</style>
