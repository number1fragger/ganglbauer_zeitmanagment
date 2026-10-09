<script setup lang="ts">
import { computed, onBeforeUnmount, ref } from 'vue'
import type { Job, UserRef } from '@/api/types'
import { beginDrag, drag } from '@/composables/useJobDrag'
import type { MoveEvent } from '@/utils/calendar'
import { priorityColor, priorityWeight } from '@/utils/domain'
import AppIcon from '../AppIcon.vue'
import StatusChip from '../StatusChip.vue'

/**
 * To-do-Liste neben dem Kalender: alle offenen Auftraege ohne Termin.
 *
 * - Desktop (Maus): Eintrag in den Kalender ziehen = einplanen.
 * - Touch oder Tastatur: Knopf "Einplanen" oeffnet Datum/Uhrzeit-Auswahl.
 * - Ein eingeplanter Auftrag, der hierher zurueckgezogen wird, verliert
 *   seinen Termin und steht wieder in der Liste (data-drop-unschedule).
 */
const props = defineProps<{
  jobs: Job[]
  workers: UserRef[]
  /** Darf einplanen (Vorarbeiter/Chef) */
  canPlan: boolean
  /** In der Monatsansicht gibt es keine Zeitachse zum Ablegen. */
  dropHint?: string | null
}>()
const emit = defineEmits<{
  open: [job: Job]
  plan: [job: Job]
  move: [event: MoveEvent]
  create: []
  collapse: []
}>()

const search = ref('')
const assignee = ref<number | 'none' | null>(null)

const visible = computed(() => {
  const q = search.value.trim().toLowerCase()
  return props.jobs
    .filter((job) => {
      if (assignee.value === 'none' && job.assignee) return false
      if (typeof assignee.value === 'number' && job.assignee?.id !== assignee.value) return false
      if (!q) return true
      return [job.title, job.customer, job.description, job.assignee?.fullName].some((text) =>
        text?.toLowerCase().includes(q),
      )
    })
    .sort(
      (a, b) =>
        priorityWeight(b.priority) - priorityWeight(a.priority) ||
        b.createdAt.localeCompare(a.createdAt),
    )
})

// Ziehen nur mit Maus/Stift – auf Touch wuerde es das Scrollen der Liste blockieren.
const finePointer = ref(window.matchMedia('(pointer: fine)').matches)
const media = window.matchMedia('(pointer: fine)')
const onMedia = (e: MediaQueryListEvent) => (finePointer.value = e.matches)
media.addEventListener('change', onMedia)
onBeforeUnmount(() => media.removeEventListener('change', onMedia))

const draggable = computed(() => props.canPlan && finePointer.value)
const dropActive = computed(() => drag.active && drag.target?.kind === 'unschedule')
const dragFromCalendar = computed(() => drag.active && !!drag.job?.scheduled && props.canPlan)

function startDrag(event: PointerEvent, job: Job): void {
  if (!draggable.value) return
  if ((event.target as HTMLElement).closest('button.todo__plan')) return
  beginDrag(event, job, (e) => emit('move', e))
}
</script>

<template>
  <aside
    class="todo"
    :class="{ 'todo--drop': dropActive, 'todo--accepting': dragFromCalendar }"
    data-drop-unschedule
    aria-label="To-do: noch nicht eingeplante Aufträge"
  >
    <header class="todo__head">
      <h2 class="todo__title">
        To-do <span class="todo__count">{{ jobs.length }}</span>
      </h2>
      <button v-if="canPlan" class="btn btn--primary btn--small" @click="emit('create')">
        <AppIcon name="plus" :size="14" /> Auftrag
      </button>
      <button
        class="btn btn--ghost btn--icon btn--small"
        aria-label="To-do-Liste einklappen"
        data-tip="Einklappen"
        @click="emit('collapse')"
      >
        <AppIcon name="chevron-left" :size="16" />
      </button>
    </header>

    <p class="todo__hint">
      <template v-if="dropHint">{{ dropHint }}</template>
      <template v-else-if="draggable"
        >Offene Aufträge ohne Termin – in den Kalender ziehen zum Einplanen.</template
      >
      <template v-else-if="canPlan"
        >Offene Aufträge ohne Termin – über „Einplanen“ Tag und Uhrzeit wählen.</template
      >
      <template v-else>Deine offenen Aufträge ohne Termin.</template>
    </p>

    <div class="todo__filters">
      <label class="todo__search">
        <AppIcon name="search" :size="15" />
        <span class="visually-hidden">To-do durchsuchen</span>
        <input v-model="search" class="input" type="search" placeholder="Suchen …" />
      </label>
      <select
        v-if="workers.length"
        v-model="assignee"
        class="input"
        aria-label="Nach Arbeiter filtern"
      >
        <option :value="null">Alle Arbeiter</option>
        <option value="none">Nicht zugeteilt</option>
        <option v-for="w in workers" :key="w.id" :value="w.id">{{ w.fullName }}</option>
      </select>
    </div>

    <div
      v-if="dragFromCalendar"
      class="todo__dropzone"
      :class="{ 'todo__dropzone--active': dropActive }"
    >
      <AppIcon name="calendar-off" :size="16" /> Hier ablegen, um den Termin zu entfernen
    </div>

    <ul v-if="visible.length" class="todo__list">
      <li
        v-for="job in visible"
        :key="job.id"
        class="todo__item accent"
        :class="{
          'todo__item--draggable': draggable,
          'todo__item--dragging': drag.active && drag.job?.id === job.id,
        }"
        :style="{ '--accent': priorityColor(job.priority) }"
        @pointerdown="startDrag($event, job)"
      >
        <button class="todo__open" :aria-label="`${job.title} öffnen`" @click="emit('open', job)">
          <strong>{{ job.title }}</strong>
          <small>
            <span>{{ job.assignee?.fullName ?? 'Nicht zugeteilt' }}</span>
            <template v-if="job.customer"> · {{ job.customer }}</template>
          </small>
          <StatusChip v-if="job.status !== 'offen'" class="todo__status" :job="job" />
        </button>
        <button
          v-if="canPlan"
          class="todo__plan"
          :aria-label="`${job.title} einplanen`"
          data-tip="Einplanen"
          @click="emit('plan', job)"
        >
          <AppIcon name="calendar-plus" :size="16" />
        </button>
      </li>
    </ul>
    <p v-else class="todo__empty">
      {{
        jobs.length ? 'Keine Treffer.' : 'Alles eingeplant – keine offenen Aufträge ohne Termin.'
      }}
    </p>
  </aside>
</template>

<style scoped>
.todo {
  display: flex;
  flex-direction: column;
  gap: 10px;
  height: 100%;
  min-height: 0;
  padding: 16px 14px 14px;
  border: 2px solid transparent;
  transition:
    background-color 0.15s,
    border-color 0.15s;
}

.todo--accepting {
  border-color: color-mix(in srgb, var(--primary) 30%, transparent);
  border-style: dashed;
}

.todo--drop {
  border-color: var(--primary);
  background: var(--primary-soft);
}

.todo__head {
  display: flex;
  align-items: center;
  gap: 6px;
}

.todo__title {
  display: flex;
  flex: 1;
  align-items: center;
  gap: 8px;
  font-size: 15px;
  font-weight: 700;
}

.todo__count {
  padding: 1px 8px;
  border-radius: 10px;
  background: var(--surface-muted);
  color: var(--muted);
  font-size: 11px;
  font-weight: 600;
}

.todo__hint {
  color: var(--muted);
  font-size: 12px;
  line-height: 1.45;
}

.todo__filters {
  display: grid;
  gap: 6px;
}

.todo__filters .input {
  height: 34px;
  font-size: 12px;
}

.todo__search {
  position: relative;
  display: flex;
  align-items: center;
}

.todo__search .icon {
  position: absolute;
  left: 10px;
  color: var(--faint);
  pointer-events: none;
}

.todo__search .input {
  padding-left: 32px;
}

.todo__dropzone {
  display: flex;
  align-items: center;
  gap: 8px;
  padding: 12px;
  border: 1.5px dashed var(--border-strong);
  border-radius: var(--radius);
  color: var(--muted);
  font-size: 12px;
  font-weight: 500;
}

.todo__dropzone--active {
  border-color: var(--primary);
  color: var(--primary);
}

.todo__list {
  display: flex;
  flex: 1;
  flex-direction: column;
  gap: 6px;
  min-height: 0;
  margin: 0;
  padding: 2px 2px 4px 0;
  overflow-y: auto;
  list-style: none;
}

.todo__item {
  position: relative;
  display: flex;
  flex: none;
  align-items: stretch;
  border: 1px solid var(--border);
  border-radius: var(--radius);
  background: var(--surface);
  box-shadow: var(--shadow-sm);
  transition:
    box-shadow 0.15s,
    border-color 0.15s,
    opacity 0.15s;
}

.todo__item:hover {
  border-color: var(--border-strong);
  box-shadow: var(--shadow);
}

.todo__item--draggable {
  cursor: grab;
  touch-action: none;
  user-select: none;
}

.todo__item--dragging {
  opacity: 0.35;
}

.todo__open {
  display: flex;
  flex: 1;
  flex-direction: column;
  align-items: flex-start;
  gap: 3px;
  min-width: 0;
  padding: 9px 6px 9px 13px;
  border: 0;
  background: none;
  text-align: left;
  cursor: inherit;
}

.todo__open strong {
  max-width: 100%;
  overflow: hidden;
  font-size: 13px;
  font-weight: 600;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.todo__open small {
  max-width: 100%;
  overflow: hidden;
  color: var(--muted);
  font-size: 11px;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.todo__status {
  margin-top: 3px;
}

.todo__plan {
  display: grid;
  flex: none;
  place-items: center;
  width: 40px;
  border: 0;
  border-left: 1px solid var(--border);
  border-radius: 0 var(--radius) var(--radius) 0;
  background: none;
  color: var(--muted);
}

.todo__plan:hover {
  background: var(--primary-soft);
  color: var(--primary);
}

.todo__empty {
  padding: 16px 12px;
  border: 1px dashed var(--border-strong);
  border-radius: var(--radius);
  color: var(--muted);
  font-size: 12px;
  text-align: center;
}

@media (hover: none) {
  .todo__plan {
    width: 48px;
  }
}
</style>
