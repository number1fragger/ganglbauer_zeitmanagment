<script setup lang="ts">
import { computed } from 'vue'
import type { CalendarSegment } from '@/api/types'
import { addDays, dayKey, isWeekend } from '@/utils/calendar'

/**
 * Monatsansicht: Raster Mo–So, pro Tag die ersten Arbeiten mit Kuerzel.
 * Freie Werktage sind markiert – dort lassen sich Kunden einplanen.
 */
const props = defineProps<{
  from: Date
  month: number
  items: { segment: CalendarSegment; color: string }[]
  /** Anzahl sichtbarer Arbeiter – ist ein Werktag nicht voll, gibt es freie Kapazitaet. */
  workerCount: number
}>()

const emit = defineEmits<{
  open: [jobId: number]
  day: [date: Date]
}>()

const MAX_PER_DAY = 3
const weekdays = ['Mo', 'Di', 'Mi', 'Do', 'Fr', 'Sa', 'So']
const todayKey = dayKey(new Date())

const days = computed(() => {
  const byDay = new Map<string, typeof props.items>()
  for (const item of props.items) {
    const key = dayKey(new Date(item.segment.start))
    byDay.set(key, [...(byDay.get(key) ?? []), item])
  }

  return Array.from({ length: 42 }, (_, i) => addDays(props.from, i))
    .filter((date, i) => i < 35 || date.getMonth() === props.month)
    .map((date) => {
      const key = dayKey(date)
      const items = byDay.get(key) ?? []
      const busyWorkers = new Set(items.map((i) => i.segment.workerId)).size

      return {
        date,
        key,
        items: items.slice(0, MAX_PER_DAY),
        more: Math.max(0, items.length - MAX_PER_DAY),
        inMonth: date.getMonth() === props.month,
        weekend: isWeekend(date),
        today: key === todayKey,
        // Vergangene Tage sind nicht mehr "frei" – dort plant niemand mehr.
        freeWorkers:
          isWeekend(date) || key < todayKey ? 0 : Math.max(0, props.workerCount - busyWorkers),
      }
    })
})
</script>

<template>
  <div class="month">
    <div
      v-for="d in weekdays"
      :key="d"
      class="weekday"
      :class="{ weekend: d === 'Sa' || d === 'So' }"
    >
      {{ d }}
    </div>

    <div
      v-for="day in days"
      :key="day.key"
      class="day"
      :class="{ outside: !day.inMonth, weekend: day.weekend }"
    >
      <button
        type="button"
        class="day__num"
        :class="{ today: day.today }"
        @click="emit('day', day.date)"
      >
        {{ day.date.getDate() }}
      </button>

      <button
        v-for="item in day.items"
        :key="`${item.segment.jobId}-${item.segment.part}`"
        type="button"
        class="entry"
        :class="{
          done: item.segment.done,
          warn:
            (item.segment.overrun || item.segment.late || item.segment.behind) &&
            !item.segment.done,
        }"
        :style="{ '--accent': item.color }"
        :title="`${item.segment.title} – ${item.segment.assignee?.fullName ?? ''}`"
        @click="emit('open', item.segment.jobId)"
      >
        <strong>{{ item.segment.assignee?.initials }}</strong>
        <span>{{ item.segment.title }}</span>
      </button>

      <button v-if="day.more" type="button" class="more" @click="emit('day', day.date)">
        +{{ day.more }} weitere
      </button>

      <span v-if="day.inMonth && !day.weekend && day.freeWorkers > 0" class="free">
        {{ day.freeWorkers === workerCount ? 'noch frei' : `${day.freeWorkers} frei` }}
      </span>
    </div>
  </div>
</template>

<style scoped>
.month {
  display: grid;
  grid-template-columns: repeat(7, minmax(110px, 1fr));
  min-width: 770px;
}

.weekday {
  padding: 0.6rem 0.75rem;
  font-size: 0.75rem;
  font-weight: 600;
  color: var(--muted);
  border-bottom: 1px solid var(--border);
  background: var(--surface);
}

.weekday.weekend {
  color: color-mix(in srgb, var(--muted) 60%, transparent);
}

.day {
  min-height: 128px;
  padding: 0.4rem;
  display: flex;
  flex-direction: column;
  gap: 0.25rem;
  border-right: 1px solid var(--border);
  border-bottom: 1px solid var(--border);
  background: var(--surface);
}

.day.weekend {
  background: color-mix(in srgb, var(--bg) 70%, var(--surface));
}

.day.outside {
  background: var(--bg);
}

.day.outside .day__num {
  color: var(--muted);
  font-weight: 500;
}

.day__num {
  align-self: flex-start;
  background: transparent;
  color: var(--text);
  padding: 0.1rem 0.45rem;
  border-radius: 999px;
  font-size: 0.8125rem;
}

.day__num:hover {
  background: var(--bg);
}

.day__num.today {
  background: var(--primary);
  color: #fff;
}

.entry {
  display: flex;
  gap: 0.35rem;
  align-items: center;
  padding: 0.15rem 0.4rem;
  border: none;
  border-left: 3px solid var(--accent);
  border-radius: 4px;
  background: color-mix(in srgb, var(--accent) 12%, var(--surface));
  color: var(--text);
  font-size: 0.6875rem;
  font-weight: 400;
  text-align: left;
  white-space: nowrap;
  overflow: hidden;
}

.entry span {
  overflow: hidden;
  text-overflow: ellipsis;
}

.entry.done {
  opacity: 0.55;
  text-decoration: line-through;
}

.entry.warn {
  box-shadow: inset 0 0 0 1px var(--danger);
}

.more {
  align-self: flex-start;
  background: transparent;
  color: var(--primary);
  padding: 0 0.4rem;
  font-size: 0.6875rem;
}

.free {
  margin-top: auto;
  font-size: 0.6875rem;
  color: var(--success);
  font-weight: 600;
  padding: 0 0.4rem;
}
</style>
