<script setup lang="ts">
import { computed, nextTick, onMounted, ref, watch } from 'vue'
import { jobsApi, planningApi } from '@/api'
import { errorMessage } from '@/api/http'
import type { Job, JobStatus, Priority, UserRef } from '@/api/types'
import AppIcon from '@/components/AppIcon.vue'
import FollowUpDialog from '@/components/FollowUpDialog.vue'
import JobDialog from '@/components/JobDialog.vue'
import PageHeader from '@/components/PageHeader.vue'
import TaskCard from '@/components/TaskCard.vue'
import { useBreakpoint } from '@/composables/useBreakpoint'
import { useJobActions } from '@/composables/useJobActions'
import { toast } from '@/composables/useToast'
import { useAuthStore } from '@/stores/auth'
import {
  canStart,
  findConflicts,
  isDone,
  isOverdue,
  priorities,
  priorityWeight,
  statuses,
} from '@/utils/domain'
import { addDays, startOfDay, startOfWeek } from '@/utils/time'

/**
 * Aufgabenverwaltung nach dem Vorbild eines Kanban-Boards: Offen,
 * In Bearbeitung, Abgeschlossen. Funktioniert vollstaendig ohne Termine.
 */
const auth = useAuthStore()
const { isMobile } = useBreakpoint()

type Period = 'alle' | 'ueberfaellig' | 'heute' | 'woche' | 'ohne' | 'mit'

const periods: { value: Period; label: string }[] = [
  { value: 'alle', label: 'Alle Zeiträume' },
  { value: 'ueberfaellig', label: 'Überfällig' },
  { value: 'heute', label: 'Heute' },
  { value: 'woche', label: 'Diese Woche' },
  { value: 'mit', label: 'Mit Termin' },
  { value: 'ohne', label: 'Ohne Termin' },
]

const jobs = ref<Job[]>([])
const workers = ref<UserRef[]>([])
const loading = ref(true)
const error = ref('')

const search = ref('')
const assignee = ref<number | null>(null)
const priority = ref<Priority | ''>('')
const period = ref<Period>('alle')
const showDone = ref(true)
const mobileColumn = ref<JobStatus>('offen')

async function load(): Promise<void> {
  error.value = ''
  try {
    jobs.value = await jobsApi.board({
      assignee: assignee.value,
      priority: priority.value || null,
      q: search.value.trim(),
      doneDays: showDone.value ? 14 : 0,
    })
  } catch (e) {
    error.value = errorMessage(e)
  } finally {
    loading.value = false
  }
}

onMounted(async () => {
  if (auth.isPlanner) {
    planningApi
      .overview()
      .then((o) => (workers.value = o.workers.map((w) => w.user)))
      .catch(() => undefined)
  }
  await load()
})

let searchTimer: number | undefined
watch(search, () => {
  window.clearTimeout(searchTimer)
  searchTimer = window.setTimeout(load, 250)
})
watch([assignee, priority, showDone], load)

function matchesPeriod(job: Job): boolean {
  const today = startOfDay(new Date())
  const start = job.startsAt ? new Date(job.startsAt) : null
  const end = job.endsAt ? new Date(job.endsAt) : null
  switch (period.value) {
    case 'ueberfaellig':
      return isOverdue(job)
    case 'heute':
      return !!start && !!end && start < addDays(today, 1) && end > today
    case 'woche': {
      const monday = startOfWeek(today)
      return !!start && !!end && start < addDays(monday, 7) && end > monday
    }
    case 'ohne':
      return !job.scheduled
    case 'mit':
      return job.scheduled
    default:
      return true
  }
}

/** Wichtiges zuerst: Ueberfaellig, Prioritaet, Termin, zuletzt angelegt. */
function compare(a: Job, b: Job): number {
  if (isDone(a) && isDone(b)) return (b.completedAt ?? '').localeCompare(a.completedAt ?? '')
  return (
    Number(isOverdue(b)) - Number(isOverdue(a)) ||
    Number(b.running) - Number(a.running) ||
    priorityWeight(b.priority) - priorityWeight(a.priority) ||
    (a.startsAt ?? '9999').localeCompare(b.startsAt ?? '9999') ||
    b.createdAt.localeCompare(a.createdAt)
  )
}

const visible = computed(() => jobs.value.filter(matchesPeriod))
const conflicts = computed(() => findConflicts(jobs.value))

const columns = computed(() =>
  statuses
    .filter((s) => showDone.value || s.value !== 'erledigt')
    .map((status) => ({
      ...status,
      jobs: visible.value.filter((job) => job.status === status.value).sort(compare),
    })),
)

const overdueCount = computed(() => jobs.value.filter((job) => isOverdue(job)).length)

// ---- Aktionen --------------------------------------------------------------

const actions = useJobActions(() => load())

const dialog = ref<{ job: Job | null } | null>(null)
const openJob = (job: Job) => (dialog.value = { job })

async function onFollowUpApplied(): Promise<void> {
  actions.followUp.value = null
  await load()
}

async function onDialogDone(): Promise<void> {
  dialog.value = null
  await load()
}

/** Schnell anlegen wie bei Trello: nur ein Titel, keine Zeitangaben. */
const quickTitle = ref('')
const quickOpen = ref(false)
const quickBusy = ref(false)

async function openQuickAdd(): Promise<void> {
  quickOpen.value = true
  await nextTick()
  document.querySelector<HTMLTextAreaElement>('.quick__input')?.focus()
}

async function quickAdd(): Promise<void> {
  const title = quickTitle.value.trim()
  if (!title || quickBusy.value) return
  quickBusy.value = true
  try {
    await jobsApi.create({
      title,
      description: null,
      customer: null,
      priority: 'mittel',
      startsAt: null,
      endsAt: null,
      assigneeId: assignee.value,
    })
    quickTitle.value = ''
    toast('Aufgabe angelegt.', 'success')
    await load()
  } catch (e) {
    toast(errorMessage(e), 'error')
  } finally {
    quickBusy.value = false
  }
}

// ---- Drag & Drop zwischen den Spalten -----------------------------------------

const dragged = ref<Job | null>(null)
const over = ref<JobStatus | null>(null)

function onDragStart(event: DragEvent, job: Job): void {
  dragged.value = job
  event.dataTransfer?.setData('text/plain', String(job.id))
  if (event.dataTransfer) event.dataTransfer.effectAllowed = 'move'
}

function onDragEnd(): void {
  dragged.value = null
  over.value = null
}

/** Statuswechsel per Ziehen – nur erlaubte Uebergaenge, jeweils ueber die echte Aktion. */
async function moveTo(target: JobStatus): Promise<void> {
  const job = dragged.value
  onDragEnd()
  if (!job || job.status === target) return

  if (target === 'erledigt') return actions.complete(job)
  if (job.status === 'erledigt') return actions.reopen(job)

  if (target === 'in_arbeit') {
    if (canStart(job, auth.user)) {
      await actions.start(job)
    } else {
      toast('In Bearbeitung kommt eine Arbeit, sobald der zuständige Arbeiter sie startet.', 'info')
    }
    return
  }

  toast('Begonnene Arbeiten bleiben in Bearbeitung – die erfasste Zeit bleibt erhalten.', 'info')
}

const canDrag = (job: Job) =>
  !isMobile.value && (auth.isPlanner || job.assignee?.id === auth.user?.id)

function resetFilters(): void {
  search.value = ''
  assignee.value = null
  priority.value = ''
  period.value = 'alle'
}

const filtered = computed(
  () => !!(search.value || assignee.value || priority.value || period.value !== 'alle'),
)
</script>

<template>
  <div class="tasks">
    <PageHeader
      title="Aufgaben"
      :subtitle="
        auth.isPlanner
          ? 'Alle Arbeiten – mit und ohne Termin'
          : 'Deine Arbeiten – mit und ohne Termin'
      "
    >
      <template #actions>
        <button v-if="auth.isPlanner" class="btn btn--primary" @click="dialog = { job: null }">
          <AppIcon name="plus" :size="16" /> Neue Arbeit
        </button>
      </template>
    </PageHeader>

    <div class="filters" role="search">
      <label class="filters__search">
        <AppIcon name="search" :size="16" />
        <span class="visually-hidden">Aufgaben durchsuchen</span>
        <input
          v-model="search"
          class="input"
          type="search"
          placeholder="Suchen: Titel, Kunde, Beschreibung"
        />
      </label>
      <select
        v-if="auth.isPlanner"
        v-model="assignee"
        class="input"
        aria-label="Nach Arbeiter filtern"
      >
        <option :value="null">Alle Arbeiter</option>
        <option v-for="w in workers" :key="w.id" :value="w.id">{{ w.fullName }}</option>
      </select>
      <select v-model="priority" class="input" aria-label="Nach Priorität filtern">
        <option value="">Alle Prioritäten</option>
        <option v-for="p in priorities" :key="p.value" :value="p.value">{{ p.label }}</option>
      </select>
      <select v-model="period" class="input" aria-label="Nach Zeitraum filtern">
        <option v-for="p in periods" :key="p.value" :value="p.value">{{ p.label }}</option>
      </select>
      <label class="checkbox filters__done">
        <input v-model="showDone" type="checkbox" /> Abgeschlossene (14 Tage)
      </label>
      <button v-if="filtered" class="link" @click="resetFilters">Filter zurücksetzen</button>
      <span v-if="overdueCount" class="chip filters__overdue" style="--chip: var(--danger)">
        <AppIcon name="alert" :size="12" /> {{ overdueCount }} überfällig
      </span>
    </div>

    <p v-if="error" class="form-error tasks__error" role="alert">
      {{ error }} <button class="link" @click="load">Erneut laden</button>
    </p>

    <!-- Smartphone: eine Spalte nach der anderen -->
    <div v-if="isMobile" class="segmented tasks__tabs" role="tablist" aria-label="Status">
      <button
        v-for="column in columns"
        :key="column.value"
        role="tab"
        :aria-selected="mobileColumn === column.value"
        :aria-pressed="mobileColumn === column.value"
        @click="mobileColumn = column.value"
      >
        {{ column.label }} <span class="tasks__count">{{ column.jobs.length }}</span>
      </button>
    </div>

    <div class="board" :class="{ 'board--loading': loading }">
      <section
        v-for="column in columns"
        v-show="!isMobile || mobileColumn === column.value"
        :key="column.value"
        class="column"
        :class="{ 'column--over': over === column.value && dragged?.status !== column.value }"
        :aria-label="column.label"
        @dragover.prevent="over = column.value"
        @dragleave="over === column.value && (over = null)"
        @drop.prevent="moveTo(column.value)"
      >
        <header class="column__head">
          <span class="dot" :style="{ '--dot': column.color }" />
          <h2>{{ column.label }}</h2>
          <span class="column__count">{{ column.jobs.length }}</span>
        </header>

        <div class="column__list">
          <template v-if="loading">
            <div v-for="n in 3" :key="n" class="skeleton column__skeleton" />
          </template>
          <TaskCard
            v-for="job in column.jobs"
            v-else
            :key="job.id"
            :job="job"
            :user="auth.user"
            :busy="actions.busyId.value === job.id"
            :draggable="canDrag(job)"
            :conflict="conflicts.get(job.id)"
            @dragstart="onDragStart($event, job)"
            @dragend="onDragEnd"
            @open="openJob"
            @start="actions.start"
            @pause="actions.pause"
            @complete="actions.complete"
          />
          <p v-if="!loading && column.jobs.length === 0" class="column__empty">
            {{
              filtered
                ? 'Keine Treffer'
                : column.value === 'erledigt'
                  ? 'Noch nichts abgeschlossen'
                  : 'Keine Aufgaben'
            }}
          </p>
        </div>

        <footer v-if="column.value === 'offen' && auth.isPlanner" class="column__add">
          <form v-if="quickOpen" class="quick" @submit.prevent="quickAdd">
            <textarea
              v-model="quickTitle"
              class="input quick__input"
              rows="2"
              maxlength="150"
              placeholder="Titel der Aufgabe … (Enter speichert)"
              @keydown.enter.exact.prevent="quickAdd"
              @keydown.esc="quickOpen = false"
            />
            <div class="quick__actions">
              <button
                class="btn btn--primary btn--small"
                :disabled="quickBusy || !quickTitle.trim()"
              >
                Hinzufügen
              </button>
              <button type="button" class="btn btn--ghost btn--small" @click="quickOpen = false">
                Fertig
              </button>
            </div>
          </form>
          <button v-else class="column__add-button" @click="openQuickAdd">
            <AppIcon name="plus" :size="15" /> Aufgabe hinzufügen
          </button>
        </footer>
      </section>
    </div>

    <JobDialog
      v-if="dialog"
      :job="dialog.job"
      :workers="workers"
      @close="dialog = null"
      @saved="onDialogDone"
      @deleted="onDialogDone"
      @changed="load"
    />

    <FollowUpDialog
      v-if="actions.followUp.value"
      :follow-up="actions.followUp.value"
      :title="jobs.find((j) => j.id === actions.followUp.value?.jobId)?.title"
      @close="actions.followUp.value = null"
      @applied="onFollowUpApplied"
    />
  </div>
</template>

<style scoped>
.tasks {
  display: flex;
  flex-direction: column;
  min-height: 100dvh;
}

.filters {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 8px 10px;
  padding: 0 28px 16px;
}

.filters .input {
  width: auto;
  height: 36px;
  font-size: 12px;
}

.filters__search {
  position: relative;
  display: flex;
  align-items: center;
  flex: 1 1 260px;
  max-width: 380px;
}

.filters__search .icon {
  position: absolute;
  left: 11px;
  color: var(--faint);
  pointer-events: none;
}

.filters__search .input {
  width: 100%;
  padding-left: 34px;
}

.filters__done {
  color: var(--muted);
}

.filters__overdue {
  margin-left: auto;
}

.tasks__error {
  margin: 0 28px 12px;
}

.board {
  display: grid;
  flex: 1;
  grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
  align-items: start;
  gap: 16px;
  padding: 0 28px 28px;
}

.column {
  display: flex;
  flex-direction: column;
  max-height: calc(100dvh - 190px);
  border: 1px solid var(--border);
  border-radius: var(--radius-lg);
  background: var(--surface-alt);
  transition:
    border-color 0.15s,
    background-color 0.15s;
}

.column--over {
  border-color: var(--primary);
  border-style: dashed;
  background: var(--primary-soft);
}

.column__head {
  display: flex;
  align-items: center;
  gap: 8px;
  padding: 14px 14px 10px;
}

.column__head h2 {
  font-size: 13px;
  font-weight: 700;
}

.column__count {
  min-width: 22px;
  padding: 1px 7px;
  border-radius: 10px;
  background: var(--surface-muted);
  color: var(--muted);
  font-size: 11px;
  font-weight: 600;
  text-align: center;
}

.column__list {
  display: flex;
  flex-direction: column;
  gap: 8px;
  min-height: 60px;
  padding: 2px 10px 10px;
  overflow-y: auto;
}

.column__skeleton {
  height: 92px;
}

.column__empty {
  padding: 18px 8px;
  color: var(--faint);
  font-size: 12px;
  text-align: center;
}

.column__add {
  padding: 4px 10px 10px;
}

.column__add-button {
  display: flex;
  align-items: center;
  gap: 8px;
  width: 100%;
  height: 36px;
  padding: 0 10px;
  border: 0;
  border-radius: var(--radius);
  background: none;
  color: var(--muted);
  font-size: 12px;
  font-weight: 500;
}

.column__add-button:hover {
  background: var(--surface-muted);
  color: var(--text);
}

.quick {
  display: flex;
  flex-direction: column;
  gap: 8px;
}

.quick__input {
  min-height: 60px;
}

.quick__actions {
  display: flex;
  gap: 6px;
}

.tasks__tabs {
  display: flex;
  margin: 0 16px 12px;
}

.tasks__tabs button {
  flex: 1 1 auto;
  min-width: 0;
  height: 40px;
  padding: 0 6px;
  font-size: 12px;
  white-space: nowrap;
}

.tasks__count {
  margin-left: 4px;
  color: var(--faint);
  font-size: 11px;
}

@media (max-width: 699px) {
  .filters {
    padding: 0 16px 12px;
  }

  .filters__search {
    flex-basis: 100%;
    max-width: none;
  }

  .filters > .input {
    flex: 1 1 calc(50% - 5px);
    min-width: 0;
    height: 40px;
  }

  .filters__overdue {
    margin-left: 0;
  }

  .tasks__error {
    margin: 0 16px 12px;
  }

  .board {
    display: block;
    padding: 0 16px 20px;
  }

  .column {
    max-height: none;
    border: 0;
    background: none;
  }

  .column__head {
    display: none;
  }

  .column__list,
  .column__add {
    padding-right: 0;
    padding-left: 0;
  }
}
</style>
