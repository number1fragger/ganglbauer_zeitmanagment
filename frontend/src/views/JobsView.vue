<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import { errorMessage } from '@/api/client'
import { useAuthStore } from '@/stores/auth'
import { useWorkshopStore } from '@/stores/workshop'
import type { Job, Priority } from '@/api/types'
import {
  formatDateTime,
  formatDuration,
  priorities,
  priorityColor,
  priorityLabel,
  statusLabels,
  toDateTimeInput,
} from '@/utils/format'

/**
 * F1–F6: Arbeiten anlegen, priorisieren, Beginn/Ende festlegen,
 * Zeit verlaengern und erledigte Arbeiten abhaken.
 */
const workshop = useWorkshopStore()
const auth = useAuthStore()

const showDone = ref(false)
const onlyMine = ref(false)
const error = ref('')
const busy = ref(false)
const formOpen = ref(false)

const emptyForm = () => ({
  id: null as number | null,
  title: '',
  customer: '',
  description: '',
  priority: 'normal' as Priority,
  plannedHours: 2,
  assigneeId: null as number | null,
  startsAt: toDateTimeInput(new Date()),
  dueAt: '',
})

const form = reactive(emptyForm())

const visibleJobs = computed(() =>
  onlyMine.value
    ? workshop.jobs.filter((job) => job.assignee?.id === auth.user?.id)
    : workshop.jobs,
)

async function reload(): Promise<void> {
  error.value = ''

  try {
    await workshop.loadJobs({ includeDone: showDone.value })
  } catch (e) {
    error.value = errorMessage(e)
  }
}

onMounted(async () => {
  await Promise.all([reload(), workshop.loadWorkers()])
})

function resetForm(): void {
  Object.assign(form, emptyForm())
  formOpen.value = false
}

function edit(job: Job): void {
  form.id = job.id
  form.title = job.title
  form.customer = job.customer ?? ''
  form.description = job.description ?? ''
  form.priority = job.priority
  form.plannedHours = Math.round((job.plannedMinutes / 60) * 100) / 100
  form.assigneeId = job.assignee?.id ?? null
  form.startsAt = job.startsAt ? toDateTimeInput(job.startsAt) : ''
  form.dueAt = job.dueAt ? toDateTimeInput(job.dueAt) : ''
  formOpen.value = true
}

async function submit(): Promise<void> {
  error.value = ''
  busy.value = true

  const payload = {
    title: form.title,
    customer: form.customer || null,
    description: form.description || null,
    priority: form.priority,
    plannedMinutes: Math.max(1, Math.round(form.plannedHours * 60)),
    assigneeId: form.assigneeId,
    startsAt: form.startsAt ? new Date(form.startsAt).toISOString() : null,
    dueAt: form.dueAt ? new Date(form.dueAt).toISOString() : null,
  }

  try {
    if (form.id === null) {
      await workshop.createJob(payload)
    } else {
      await workshop.updateJob(form.id, payload)
    }

    resetForm()
    await Promise.all([reload(), workshop.loadOverview()])
  } catch (e) {
    error.value = errorMessage(e)
  } finally {
    busy.value = false
  }
}

/** F5 – Zeit erhöhen ("dauert 2 Stunden länger"). */
async function extend(job: Job, minutes: number): Promise<void> {
  error.value = ''

  try {
    await workshop.extendJob(job.id, minutes)
    await Promise.all([reload(), workshop.loadOverview()])
  } catch (e) {
    error.value = errorMessage(e)
  }
}

/** F4 – erledigte Arbeiten abhaken. */
async function toggleDone(job: Job): Promise<void> {
  error.value = ''

  try {
    await workshop.completeJob(job.id, job.status !== 'erledigt')
    await Promise.all([reload(), workshop.loadOverview()])
  } catch (e) {
    error.value = errorMessage(e)
  }
}

async function remove(job: Job): Promise<void> {
  error.value = ''

  try {
    await workshop.deleteJob(job.id)
    await workshop.loadOverview()
  } catch (e) {
    error.value = errorMessage(e)
  }
}

async function start(job: Job): Promise<void> {
  error.value = ''

  try {
    await workshop.startWork(job.id)
    await Promise.all([reload(), workshop.loadOverview()])
  } catch (e) {
    error.value = errorMessage(e)
  }
}
</script>

<template>
  <div>
    <div class="head">
      <div>
        <h1>Arbeiten</h1>
        <p class="muted">Nach Priorität sortiert – oben steht, was zuerst dran ist.</p>
      </div>
      <button type="button" @click="formOpen = !formOpen">
        {{ formOpen ? 'Formular schließen' : 'Neue Arbeit' }}
      </button>
    </div>

    <p v-if="error" class="error">{{ error }}</p>

    <section v-if="formOpen" class="card">
      <h2>{{ form.id === null ? 'Neue Arbeit anlegen' : 'Arbeit bearbeiten' }}</h2>

      <form class="form" @submit.prevent="submit">
        <div class="wide">
          <label for="title">Was ist zu tun?</label>
          <input id="title" v-model="form.title" type="text" required />
        </div>

        <div>
          <label for="customer">Kunde</label>
          <input id="customer" v-model="form.customer" type="text" />
        </div>

        <div>
          <label for="priority">Priorität</label>
          <select id="priority" v-model="form.priority">
            <option v-for="p in priorities" :key="p.value" :value="p.value">{{ p.label }}</option>
          </select>
        </div>

        <div>
          <label for="hours">Geplante Zeit (Stunden)</label>
          <input
            id="hours"
            v-model.number="form.plannedHours"
            type="number"
            step="0.25"
            min="0.25"
            required
          />
        </div>

        <div>
          <label for="assignee">Arbeiter</label>
          <select id="assignee" v-model="form.assigneeId">
            <option :value="null">Noch niemand</option>
            <option v-for="worker in workshop.workers" :key="worker.id" :value="worker.id">
              {{ worker.fullName }}
            </option>
          </select>
        </div>

        <div>
          <label for="startsAt">Beginn</label>
          <input id="startsAt" v-model="form.startsAt" type="datetime-local" />
        </div>

        <div>
          <label for="dueAt">Ende</label>
          <input id="dueAt" v-model="form.dueAt" type="datetime-local" />
        </div>

        <div class="wide">
          <label for="description">Notiz</label>
          <input id="description" v-model="form.description" type="text" />
        </div>

        <div class="actions wide">
          <button type="submit" :disabled="busy">
            {{ form.id === null ? 'Anlegen' : 'Speichern' }}
          </button>
          <button type="button" class="secondary" @click="resetForm">Abbrechen</button>
        </div>
      </form>
    </section>

    <section class="card filters">
      <label class="check">
        <input v-model="onlyMine" type="checkbox" />
        nur meine Arbeiten
      </label>
      <label class="check">
        <input v-model="showDone" type="checkbox" @change="reload" />
        erledigte anzeigen
      </label>
    </section>

    <section
      v-for="job in visibleJobs"
      :key="job.id"
      class="card job"
      :class="{ done: job.status === 'erledigt' }"
    >
      <div class="job__head">
        <span class="dot" :style="{ background: priorityColor(job.priority) }"></span>
        <div class="job__title">
          <strong>{{ job.title }}</strong>
          <span class="muted">
            {{ job.customer ?? 'Ohne Kunde' }} · {{ priorityLabel(job.priority) }} ·
            {{ statusLabels[job.status] }}
            <template v-if="job.assignee"> · {{ job.assignee.fullName }}</template>
          </span>
        </div>

        <div class="job__time mono">
          <strong :class="{ over: job.overrun }">{{ formatDuration(job.actualMinutes) }}</strong>
          <span class="muted">von {{ formatDuration(job.plannedMinutes) }}</span>
        </div>
      </div>

      <div class="bar">
        <span :style="{ width: `${job.progressPercent}%` }" :class="{ over: job.overrun }"></span>
      </div>

      <p v-if="job.overrun" class="warn">
        Zeit überschritten um {{ formatDuration(job.overrunMinutes) }} – Zeit erhöhen oder abhaken.
      </p>

      <p v-if="job.extendedMinutes > 0" class="muted small">
        Ursprünglich {{ formatDuration(job.originalPlannedMinutes) }} geplant, um
        {{ formatDuration(job.extendedMinutes) }} verlängert.
      </p>

      <div class="job__meta muted small">
        <span v-if="job.startsAt">Beginn {{ formatDateTime(job.startsAt) }}</span>
        <span v-if="job.dueAt">Ende {{ formatDateTime(job.dueAt) }}</span>
        <span v-if="job.description">{{ job.description }}</span>
      </div>

      <div class="job__actions">
        <button
          v-if="job.status !== 'erledigt' && !job.running"
          type="button"
          class="secondary small"
          @click="start(job)"
        >
          Zeit starten
        </button>
        <button type="button" class="secondary small" @click="extend(job, 30)">+30 min</button>
        <button type="button" class="secondary small" @click="extend(job, 120)">+2 h</button>
        <button type="button" class="secondary small" @click="edit(job)">Bearbeiten</button>
        <button
          type="button"
          class="small"
          :class="job.status === 'erledigt' ? 'secondary' : ''"
          @click="toggleDone(job)"
        >
          {{ job.status === 'erledigt' ? 'Wieder öffnen' : 'Erledigt' }}
        </button>
        <button type="button" class="danger small" @click="remove(job)">Löschen</button>
      </div>
    </section>

    <p v-if="visibleJobs.length === 0" class="card muted">Keine Arbeiten in dieser Ansicht.</p>
  </div>
</template>

<style scoped>
.head {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 1rem;
  margin-bottom: 1rem;
}

section {
  margin-bottom: 1rem;
}

.form {
  display: grid;
  grid-template-columns: repeat(2, 1fr);
  gap: 0.9rem;
  align-items: end;
}

.wide {
  grid-column: 1 / -1;
}

.actions {
  display: flex;
  gap: 0.6rem;
}

.filters {
  display: flex;
  gap: 1.25rem;
  padding: 0.75rem 1.25rem;
}

.check {
  display: flex;
  align-items: center;
  gap: 0.4rem;
  font-size: 0.8125rem;
  margin: 0;
}

.check input {
  width: auto;
}

.job.done {
  opacity: 0.6;
}

.job__head {
  display: flex;
  align-items: flex-start;
  gap: 0.6rem;
  margin-bottom: 0.6rem;
}

.job__title {
  display: flex;
  flex-direction: column;
  min-width: 0;
  flex: 1;
}

.job__title span {
  font-size: 0.8125rem;
}

.job__time {
  text-align: right;
  display: flex;
  flex-direction: column;
  white-space: nowrap;
}

.job__time .over {
  color: var(--danger);
}

.job__time span {
  font-size: 0.75rem;
}

.bar {
  background: var(--bg);
  border-radius: 999px;
  height: 6px;
  overflow: hidden;
}

.bar span {
  display: block;
  height: 100%;
  background: var(--primary);
  border-radius: 999px;
}

.bar span.over {
  background: var(--danger);
}

.warn {
  margin: 0.6rem 0 0;
  font-size: 0.8125rem;
  font-weight: 600;
  color: var(--danger);
}

.small {
  font-size: 0.8125rem;
}

button.small {
  padding: 0.3rem 0.6rem;
}

.job__meta {
  display: flex;
  gap: 1rem;
  flex-wrap: wrap;
  margin-top: 0.5rem;
}

.job__actions {
  display: flex;
  gap: 0.5rem;
  flex-wrap: wrap;
  margin-top: 0.8rem;
}

@media (max-width: 720px) {
  .form {
    grid-template-columns: 1fr;
  }

  .head {
    flex-direction: column;
  }
}
</style>
