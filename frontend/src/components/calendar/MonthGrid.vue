<script setup>
import { computed } from 'vue'
import { useDrag } from '@/composables/useDrag'
import { addDays, dayKey, isWeekend } from '@/utils/calendar'

/**
 * Monatsansicht: pro Tag bis zu drei Arbeiten ("AH · Kupplung"), freie
 * Werktage mit "noch frei". Arbeiten lassen sich auf einen anderen Tag ziehen.
 * Auf dem Handy werden die Eintraege zu farbigen Punkten.
 */
const props = defineProps({
  from: { type: Date, required: true },
  month: { type: Number, required: true },
  items: { type: Array, required: true }, // { segment, color, soft }
})

const emit = defineEmits(['open', 'day', 'move'])

const MAX_PER_DAY = 3
const weekdays = ['Mo', 'Di', 'Mi', 'Do', 'Fr', 'Sa', 'So']
const todayKey = dayKey(new Date())

const days = computed(() => {
  const byDay = {}
  for (const item of props.items) (byDay[dayKey(new Date(item.segment.start))] ??= []).push(item)

  return Array.from({ length: 42 }, (_, i) => addDays(props.from, i))
    .filter((date, i) => i < 35 || date.getMonth() === props.month)
    .map((date) => {
      const key = dayKey(date)
      const items = byDay[key] ?? []

      return {
        date,
        key,
        items,
        visible: items.slice(0, MAX_PER_DAY),
        more: Math.max(0, items.length - MAX_PER_DAY),
        weekend: isWeekend(date),
        muted: date.getMonth() !== props.month || isWeekend(date),
        today: key === todayKey,
        // Vergangene Tage sind nicht mehr "frei" – dort plant niemand mehr.
        free: items.length === 0 && !isWeekend(date) && key >= todayKey,
      }
    })
})

const { drag, start } = useDrag({
  resolveTarget: (x, y) => {
    const cell = document.elementFromPoint(x, y)?.closest('[data-day]')
    return cell ? { day: cell.dataset.day } : null
  },
  onDrop: (item, target) => emit('move', item.segment, target),
  onClick: (item) => emit('open', item.segment.jobId),
})
</script>

<template>
  <div class="month" :class="{ dragging: drag.active }">
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
      :class="{ weekend: day.weekend, target: drag.target?.day === day.key }"
      :data-day="day.key"
      @click.self="emit('day', day.date)"
    >
      <button
        type="button"
        class="num"
        :class="{ today: day.today, muted: day.muted }"
        @click="emit('day', day.date)"
      >
        {{ day.date.getDate() }}
      </button>

      <div class="entries" @click.self="emit('day', day.date)">
        <button
          v-for="item in day.visible"
          :key="`${item.segment.jobId}-${item.segment.part}`"
          type="button"
          class="entry"
          :class="{
            done: item.segment.done,
            warn:
              (item.segment.overrun || item.segment.late || item.segment.behind) &&
              !item.segment.done,
            lifted: drag.item?.segment === item.segment,
          }"
          :style="{ '--accent': item.color, '--fill': item.soft }"
          :title="`${item.segment.title} – ${item.segment.assignee?.fullName ?? ''}`"
          draggable="false"
          @pointerdown="start($event, item, { draggable: !item.segment.done })"
        >
          {{ item.segment.assignee?.initials }} · {{ item.segment.title }}
        </button>
      </div>

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
  min-width: 770px;
  background: var(--surface);
}

.month.dragging {
  cursor: grabbing;
  user-select: none;
}

.weekday {
  display: flex;
  align-items: center;
  height: 40px;
  padding: 0 0.875rem;
  border-bottom: 1px solid var(--border);
  font-size: 0.6875rem;
  font-weight: 600;
  color: var(--muted);
}

.weekday.weekend {
  color: var(--faint);
}

.day {
  display: flex;
  flex-direction: column;
  gap: 5px;
  min-height: 172px;
  padding: 0.5rem;
  border-left: 1px solid var(--grid);
  border-bottom: 1px solid var(--grid);
  cursor: pointer;
}

.day.weekend {
  background: #fafafb;
}

.day.target {
  background: var(--primary-soft);
  box-shadow: inset 0 0 0 2px var(--primary);
}

.num {
  align-self: flex-start;
  min-width: 26px;
  height: 24px;
  margin-bottom: 0.3rem;
  padding: 0 0.35rem;
  border-radius: 12px;
  background: none;
  color: var(--text);
  font-size: 0.75rem;
  font-weight: 600;
}

.num.muted {
  color: var(--faint);
}

.num.today {
  background: var(--primary);
  color: #fff;
  font-weight: 700;
}

.entries {
  display: flex;
  flex-direction: column;
  gap: 5px;
}

.entry {
  height: 21px;
  padding: 0 0.5rem;
  overflow: hidden;
  border-left: 3px solid var(--accent);
  border-radius: 5px;
  background: var(--fill);
  color: var(--text);
  font-size: 0.625rem;
  font-weight: 500;
  text-align: left;
  text-overflow: ellipsis;
  cursor: grab;
  touch-action: pan-x pan-y;
  user-select: none;
  -webkit-touch-callout: none;
}

.entry.done {
  opacity: 0.55;
  text-decoration: line-through;
  cursor: pointer;
}

.entry.warn {
  box-shadow: inset 0 0 0 1px var(--danger);
}

.entry.lifted {
  opacity: 0.35;
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

/* ---------- Handy: Punkte statt Texte ---------- */
@media (max-width: 768px) {
  .month {
    grid-template-columns: repeat(7, 1fr);
    min-width: 0;
  }

  .weekday {
    justify-content: center;
    padding: 0;
  }

  .day {
    align-items: center;
    min-height: 64px;
    padding: 0.35rem 0.15rem;
  }

  .num {
    align-self: center;
    margin: 0;
  }

  .entries {
    flex-direction: row;
    flex-wrap: wrap;
    justify-content: center;
    gap: 3px;
    pointer-events: none;
  }

  .entry {
    width: 7px;
    height: 7px;
    padding: 0;
    border: none;
    border-radius: 50%;
    background: var(--accent);
    font-size: 0;
  }

  .more {
    padding: 0;
    font-size: 0.5625rem;
  }

  .free {
    display: none;
  }
}
</style>
