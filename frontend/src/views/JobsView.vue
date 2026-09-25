<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { errorMessage } from '@/api/client'
import type { Job } from '@/api/types'
import { useJobDialogStore } from '@/stores/jobDialog'
import { useWorkshopStore } from '@/stores/workshop'
import {
  formatDateTime,
  formatHours,
  priorityColor,
  priorityLabel,
  statusLabels,
} from '@/utils/format'

/**
 * Alle Arbeiten als Liste (fuer Chef und Vorarbeiter). Anlegen und
 * Bearbeiten laufen ueber den Figma-Dialog "Arbeit anlegen".
 */
const workshop = useWorkshopStore()
const dialog = useJobDialogStore()

const showDone = ref(false)
const worker = ref<number | 'all' | 'none'>('all')
const error = ref('')

const visibleJobs = computed(() =>
  workshop.jobs.filter((job) => {
    if (worker.value === 'none') return job.assignee === null
    if (worker.value !== 'all') return job.assignee?.id === worker.value

    return true
  }),
)

async function reload(): Promise<void> {
  error.value = ''
  try {
    await workshop.loadJobs({ includeDone: showDone.value })
  } catch (e) {
    error.value = errorMessage(e)
  }
}

onMounted(() => Promise.all([reload(), workshop.loadWorkers()]))
watch(showDone, reload)
watch(() => dialog.version, reload)

async function act(action: () => Promise<unknown>): Promise<void> {
  error.value = ''
  try {
    await action()
    await reload()
  } catch (e) {
    error.value = errorMessage(e)
  }
}

function state(job: Job): 'done' | 'over' | 'open' {
  if (job.status === 'erledigt') return 'done'

  return job.overrun ? 'over' : 'open'
}
</script>

<template>
  <div class="page-head">
    <div>
      <h1>Alle Arbeiten</h1>
      <p>Nach Priorität sortiert – oben steht, was zuerst dran ist</p>
    </div>
    <div class="filters">
      <select v-model="worker" aria-label="Arbeiter">
        <option value="all">Alle Arbeiter</option>
        <option value="none">Noch nicht zugeteilt</option>
        <option v-for="w in workshop.workers" :key="w.id" :value="w.id">{{ w.fullName }}</option>
      </select>
      <label class="check"><input v-model="showDone" type="checkbox" /> erledigte anzeigen</label>
    </div>
  </div>

  <div class="page-body">
    <p v-if="error" class="error">{{ error }}</p>

    <article
      v-for="job in visibleJobs"
      :key="job.id"
      class="job"
      :class="state(job)"
      :style="{
        '--accent': job.status === 'erledigt' ? 'var(--faint)' : priorityColor(job.priority),
      }"
    >
      <div class="main">
        <strong>{{ job.title }}</strong>
        <span>
          Kunde: {{ job.customer ?? '–' }} · {{ priorityLabel(job.priority) }} ·
          {{ statusLabels[job.status] }} · {{ job.assignee?.fullName ?? 'noch niemand zugeteilt' }}
        </span>
        <span v-if="job.startsAt"
          >Beginn {{ formatDateTime(job.startsAt)
          }}<template v-if="job.dueAt"> · Ende {{ formatDateTime(job.dueAt) }}</template></span
        >
      </div>

      <div class="times">
        <strong :class="{ red: job.overrun }">
          Soll {{ formatHours(job.plannedMinutes)
          }}<template v-if="job.actualMinutes">
            · Ist {{ formatHours(job.actualMinutes) }}</template
          >
        </strong>
        <span v-if="job.overrun" class="red"
          >Zeit überschritten um {{ formatHours(job.overrunMinutes) }}</span
        >
        <span v-else-if="job.running" class="blue">läuft gerade</span>
      </div>

      <div class="actions">
        <button
          v-if="job.status !== 'erledigt'"
          type="button"
          class="secondary small"
          @click="act(() => workshop.extendJob(job.id, 60))"
        >
          + 1 Stunde
        </button>
        <button
          type="button"
          class="secondary small"
          @click="act(() => workshop.completeJob(job.id, job.status !== 'erledigt'))"
        >
          {{ job.status === 'erledigt' ? 'Wieder öffnen' : 'Erledigt' }}
        </button>
        <button type="button" class="small" @click="dialog.edit(job.id)">Bearbeiten</button>
      </div>
    </article>

    <p v-if="visibleJobs.length === 0" class="empty">Keine Arbeiten in dieser Ansicht.</p>
  </div>
</template>

<style scoped>
.filters {
  display: flex;
  align-items: center;
  gap: 20px;
}

.filters select {
  width: 200px;
  height: 36px;
  background: var(--button);
  font-size: 0.75rem;
}

.check {
  display: flex;
  align-items: center;
  gap: 8px;
  margin: 0;
  font-size: 0.75rem;
  color: var(--text);
  cursor: pointer;
}

.job {
  display: flex;
  align-items: center;
  gap: 24px;
  padding: 14px 18px 14px 20px;
  margin-bottom: 10px;
  border-radius: 10px;
  background: var(--surface);
  border-left: 4px solid var(--accent);
}

.job.done {
  opacity: 0.65;
}

.job.over {
  background: var(--danger-soft);
}

.main {
  display: flex;
  flex-direction: column;
  gap: 3px;
  flex: 1;
  min-width: 0;
}

.main strong {
  font-size: 0.8125rem;
  font-weight: 600;
}

.main span {
  font-size: 0.6875rem;
  color: var(--muted);
}

.times {
  display: flex;
  flex-direction: column;
  align-items: flex-end;
  gap: 3px;
  font-size: 0.6875rem;
  white-space: nowrap;
}

.times strong {
  font-weight: 500;
}

.red {
  color: var(--danger);
}

.blue {
  color: var(--primary);
}

.actions {
  display: flex;
  gap: 8px;
}

.empty {
  color: var(--muted);
}

@media (max-width: 900px) {
  .job {
    flex-wrap: wrap;
  }

  .times {
    align-items: flex-start;
  }
}
</style>
