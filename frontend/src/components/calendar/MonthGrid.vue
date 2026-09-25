<script setup lang="ts">
import { computed } from 'vue'
import type { CalendarSegment } from '@/api/types'
import { addDays, dayKey, isWeekend } from '@/utils/calendar'

/**
 * Monatsansicht (Figma 01c): Raster Mo–So, pro Tag bis zu drei Arbeiten
 * als "AH · Kupplung", freie Werktage mit "noch frei".
 */
const props = defineProps<{
  from: Date
  month: number
  items: { segment: CalendarSegment; color: string; soft: string }[]
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

      return {
        date,
        key,
        items: items.slice(0, MAX_PER_DAY),
        more: Math.max(0, items.length - MAX_PER_DAY),
        inMonth: date.getMonth() === props.month,
        weekend: isWeekend(date),
        today: key === todayKey,
        // Vergangene Tage sind nicht mehr "frei" – dort plant niemand mehr.
        free: items.length === 0 && !isWeekend(date) && key >= todayKey,
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

    <div v-for="day in days" :key="day.key" class="day" :class="{ weekend: day.weekend }">
      <button
        type="button"
        class="num"
        :class="{ today: day.today, outside: !day.inMonth || day.weekend }"
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
        :style="{ '--accent': item.color, '--fill': item.soft }"
        :title="`${item.segment.title} – ${item.segment.assignee?.fullName ?? ''}`"
        @click="emit('open', item.segment.jobId)"
      >
        {{ item.segment.assignee?.initials }} · {{ item.segment.title }}
      </button>

      <button v-if="day.more" type="button" class="more" @click="emit('day', day.date)">
        +{{ day.more }} weitere
      </button>

      <span v-if="day.free" class="free">noch frei</span>
    </div>
  </div>
</template>

<style scoped>
.month {
  display: grid;
  grid-template-columns: repeat(7, minmax(110px, 1fr));
  grid-auto-rows: auto;
  min-width: 770px;
  background: var(--surface);
}

.weekday {
  height: 40px;
  padding: 0 0.875rem;
  display: flex;
  align-items: center;
  font-size: 0.6875rem;
  font-weight: 600;
  color: var(--muted);
  border-bottom: 1px solid var(--border);
}

.weekday.weekend {
  color: var(--faint);
}

.day {
  min-height: 172px;
  padding: 0.5rem;
  display: flex;
  flex-direction: column;
  gap: 5px;
  border-left: 1px solid var(--grid);
  border-bottom: 1px solid var(--grid);
}

.day.weekend {
  background: #fafafb;
}

.num {
  align-self: flex-start;
  height: 24px;
  min-width: 26px;
  padding: 0 0.35rem;
  margin-bottom: 0.3rem;
  border-radius: 12px;
  background: none;
  color: var(--text);
  font-size: 0.75rem;
  font-weight: 600;
}

.num.outside {
  color: var(--faint);
}

.num.today {
  background: var(--primary);
  color: #fff;
  font-weight: 700;
}

.entry {
  height: 21px;
  padding: 0 0.5rem;
  border-radius: 5px;
  border-left: 3px solid var(--accent);
  background: var(--fill);
  color: var(--text);
  font-size: 0.625rem;
  font-weight: 500;
  text-align: left;
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
  height: auto;
  padding: 0.1rem 0.35rem;
  background: none;
  color: var(--primary);
  font-size: 0.625rem;
}

.free {
  padding: 0.1rem 0.35rem;
  font-size: 0.625rem;
  color: var(--faint);
}
</style>
