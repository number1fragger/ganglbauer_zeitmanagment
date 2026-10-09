<script setup lang="ts">
import { computed } from 'vue'
import type { Job } from '@/api/types'
import { isDone, priorityColor } from '@/utils/domain'
import {
  addDays,
  formatTime,
  isWeekend,
  relativeDay,
  sameDay,
  startOfDay,
  weekdayName,
} from '@/utils/time'
import AppIcon from '../AppIcon.vue'
import StatusChip from '../StatusChip.vue'

/**
 * Listenansicht fuer schmale Bildschirme: Tag fuer Tag untereinander statt
 * einer gequetschten Wochen- oder Monatsansicht. Mehrtaegige Arbeiten
 * erscheinen an jedem Tag mit Hinweis "Tag x von y".
 */
const props = defineProps<{
  from: Date
  to: Date
  jobs: Job[]
  conflicts?: Map<number, string[]>
}>()
defineEmits<{ open: [job: Job]; create: [startsAt: Date] }>()

const days = computed(() => {
  const result: { day: Date; items: { job: Job; label: string; part: string | null }[] }[] = []
  for (let day = startOfDay(props.from); day < props.to; day = addDays(day, 1)) {
    const next = addDays(day, 1)
    const items = props.jobs
      .filter(
        (job) =>
          job.startsAt && job.endsAt && new Date(job.startsAt) < next && new Date(job.endsAt) > day,
      )
      .sort((a, b) => a.startsAt!.localeCompare(b.startsAt!))
      .map((job) => {
        const start = new Date(job.startsAt!)
        const end = new Date(job.endsAt!)
        const total =
          Math.round((startOfDay(end).getTime() - startOfDay(start).getTime()) / 86_400_000) + 1
        const index = Math.round((day.getTime() - startOfDay(start).getTime()) / 86_400_000) + 1
        const from = start < day ? 'ganztags' : formatTime(start)
        const to = end > next ? '' : formatTime(end)
        return {
          job,
          label:
            start < day && end > next
              ? 'ganztags'
              : to
                ? `${from === 'ganztags' ? 'bis' : from + ' –'} ${to}`
                : `ab ${from}`,
          part: total > 1 ? `Tag ${index} von ${total}` : null,
        }
      })
    if (items.length || !isWeekend(day)) result.push({ day, items })
  }
  return result
})

const heading = (day: Date) => {
  const rel = relativeDay(day)
  const date = day.toLocaleDateString('de-AT', { day: 'numeric', month: 'long' })
  return rel
    ? `${rel.charAt(0).toUpperCase()}${rel.slice(1)} · ${weekdayName(day)}, ${date}`
    : `${weekdayName(day)}, ${date}`
}

function createAt(day: Date): Date {
  const start = new Date(day)
  start.setHours(7, 0, 0, 0)
  return start
}
</script>

<template>
  <div class="agenda">
    <section
      v-for="{ day, items } in days"
      :key="day.toISOString()"
      class="agenda__day"
      :class="{ 'agenda__day--today': sameDay(day, new Date()) }"
    >
      <header class="agenda__head">
        <h3>{{ heading(day) }}</h3>
        <button
          class="agenda__add"
          :aria-label="`Arbeit am ${day.toLocaleDateString('de-AT')} anlegen`"
          @click="$emit('create', createAt(day))"
        >
          <AppIcon name="plus" :size="16" />
        </button>
      </header>
      <ul v-if="items.length" class="agenda__list">
        <li v-for="item in items" :key="item.job.id">
          <button
            class="agenda__item accent"
            :class="{
              'agenda__item--done': isDone(item.job),
              'agenda__item--conflict': conflicts?.has(item.job.id),
            }"
            :style="{
              '--accent': isDone(item.job) ? 'var(--faint)' : priorityColor(item.job.priority),
            }"
            @click="$emit('open', item.job)"
          >
            <span class="agenda__time tabular">{{ item.label }}</span>
            <span class="agenda__main">
              <strong>{{ item.job.title }}</strong>
              <small>
                {{ item.job.assignee?.fullName ?? 'Nicht zugeteilt'
                }}<template v-if="item.job.customer"> · {{ item.job.customer }}</template>
                <template v-if="item.part"> · {{ item.part }}</template>
              </small>
              <small v-if="conflicts?.has(item.job.id)" class="agenda__conflict">
                <AppIcon name="layers" :size="11" /> Überschneidung mit
                {{ conflicts.get(item.job.id)!.join(', ') }}
              </small>
            </span>
            <StatusChip :job="item.job" />
          </button>
        </li>
      </ul>
      <p v-else class="agenda__free">Nichts geplant</p>
    </section>
  </div>
</template>

<style scoped>
.agenda {
  display: flex;
  flex-direction: column;
  gap: 18px;
  padding: 4px 16px 24px;
}

.agenda__head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin-bottom: 8px;
}

.agenda__head h3 {
  font-size: 13px;
  font-weight: 700;
}

.agenda__day--today .agenda__head h3 {
  color: var(--primary);
}

.agenda__add {
  display: grid;
  place-items: center;
  width: 36px;
  height: 36px;
  border: 0;
  border-radius: 50%;
  background: none;
  color: var(--muted);
}

.agenda__add:hover {
  background: var(--surface-muted);
}

.agenda__list {
  display: grid;
  gap: 8px;
  margin: 0;
  padding: 0;
  list-style: none;
}

.agenda__item {
  position: relative;
  display: flex;
  align-items: center;
  gap: 12px;
  width: 100%;
  min-height: 60px;
  padding: 10px 12px 10px 16px;
  border: 1px solid var(--border);
  border-radius: var(--radius);
  background: var(--surface);
  text-align: left;
}

.agenda__item--done {
  opacity: 0.65;
}

.agenda__item--conflict {
  border-color: var(--danger);
}

.agenda__time {
  width: 74px;
  flex: none;
  color: var(--muted);
  font-size: 12px;
  font-weight: 600;
}

.agenda__main {
  display: flex;
  flex: 1;
  flex-direction: column;
  gap: 2px;
  min-width: 0;
}

.agenda__main strong {
  font-size: 14px;
  font-weight: 600;
}

.agenda__main small {
  color: var(--muted);
  font-size: 12px;
}

.agenda__conflict {
  display: flex;
  align-items: center;
  gap: 4px;
  color: var(--danger) !important;
}

.agenda__free {
  padding: 10px 14px;
  border-radius: var(--radius);
  background: var(--surface-alt);
  color: var(--faint);
  font-size: 12px;
}
</style>
