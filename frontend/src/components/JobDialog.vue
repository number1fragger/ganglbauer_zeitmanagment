<script setup lang="ts">
import { computed, reactive, ref, watch } from 'vue'
import { errorMessage, http } from '@/api/client'
import type { Job, Priority } from '@/api/types'
import { useJobDialogStore } from '@/stores/jobDialog'
import { useWorkshopStore } from '@/stores/workshop'
import { formatHours, priorities, toDateTimeInput } from '@/utils/format'

/**
 * Figma-Screen 02 "Arbeit anlegen / bearbeiten" (F1–F5).
 */
const dialog = useJobDialogStore()
const workshop = useWorkshopStore()

const STEP_MINUTES = 30

const loading = ref(false)
const busy = ref(false)
const error = ref('')
const job = ref<Job | null>(null)
/** Ende wurde von Hand gesetzt – dann nicht mehr automatisch nachziehen. */
const endTouched = ref(false)

const form = reactive({
  title: '',
  customer: '',
  priority: 'normal' as Priority,
  plannedMinutes: 120,
  assigneeId: null as number | null,
  startsAt: '',
  dueAt: '',
  done: false,
})

const isEdit = computed(() => dialog.jobId !== null)

watch(
  () => dialog.open,
  async (open) => {
    if (!open) return

    error.value = ''
    endTouched.value = false
    job.value = null
    Object.assign(form, {
      title: '',
      customer: '',
      priority: 'normal',
      plannedMinutes: 120,
      assigneeId: dialog.prefill.assigneeId ?? null,
      startsAt: dialog.prefill.startsAt ?? toDateTimeInput(nextFullHour()),
      dueAt: '',
      done: false,
    })

    loading.value = true
    try {
      const tasks: Promise<unknown>[] = [workshop.loadWorkers()]
      if (dialog.jobId !== null) {
        tasks.push(
          http.get<Job>(`/api/jobs/${dialog.jobId}`).then(({ data }) => {
            job.value = data
            endTouched.value = true
            Object.assign(form, {
              title: data.title,
              customer: data.customer ?? '',
              priority: data.priority === 'dringend' ? 'hoch' : data.priority,
              plannedMinutes: data.plannedMinutes,
              assigneeId: data.assignee?.id ?? null,
              startsAt: data.startsAt ? toDateTimeInput(data.startsAt) : '',
              dueAt: data.dueAt ? toDateTimeInput(data.dueAt) : '',
              done: data.status === 'erledigt',
            })
          }),
        )
      }
      await Promise.all(tasks)
    } catch (e) {
      error.value = errorMessage(e)
    } finally {
      loading.value = false
      if (!endTouched.value) suggestEnd()
    }
  },
)

function nextFullHour(): Date {
  const date = new Date()
  date.setHours(date.getHours() + 1, 0, 0, 0)

  return date
}

/** Vorschlag fuer das Ende: Beginn + geplante Zeit (ohne Arbeitszeitgrenzen). */
function suggestEnd(): void {
  if (endTouched.value || !form.startsAt) return

  const end = new Date(new Date(form.startsAt).getTime() + form.plannedMinutes * 60_000)
  form.dueAt = toDateTimeInput(end)
}

watch(() => [form.startsAt, form.plannedMinutes], suggestEnd)

function stepPlanned(direction: 1 | -1): void {
  form.plannedMinutes = Math.max(STEP_MINUTES, form.plannedMinutes + direction * STEP_MINUTES)
}

/** F5 – sofort um eine Stunde verlaengern, unabhaengig vom Speichern. */
async function extendOneHour(): Promise<void> {
  if (!job.value) return

  busy.value = true
  error.value = ''
  try {
    const { data } = await http.post<Job>(`/api/jobs/${job.value.id}/extend`, { minutes: 60 })
    job.value = data
    form.plannedMinutes = data.plannedMinutes
  } catch (e) {
    error.value = errorMessage(e)
  } finally {
    busy.value = false
  }
}

async function save(): Promise<void> {
  busy.value = true
  error.value = ''

  const payload = {
    title: form.title,
    customer: form.customer || null,
    priority: form.priority,
    plannedMinutes: form.plannedMinutes,
    assigneeId: form.assigneeId,
    startsAt: form.startsAt ? new Date(form.startsAt).toISOString() : null,
    dueAt: form.dueAt ? new Date(form.dueAt).toISOString() : null,
  }

  try {
    if (job.value) {
      await http.patch(`/api/jobs/${job.value.id}`, payload)
      const wasDone = job.value.status === 'erledigt'
      if (form.done !== wasDone) {
        await http.post(`/api/jobs/${job.value.id}/complete`, { done: form.done })
      }
    } else {
      await http.post('/api/jobs', payload)
    }
    dialog.saved()
  } catch (e) {
    error.value = errorMessage(e)
  } finally {
    busy.value = false
  }
}

async function remove(): Promise<void> {
  if (!job.value) return
  if (!window.confirm(`„${job.value.title}“ wirklich löschen? Erfasste Zeiten gehen verloren.`))
    return

  busy.value = true
  try {
    await http.delete(`/api/jobs/${job.value.id}`)
    dialog.saved()
  } catch (e) {
    error.value = errorMessage(e)
  } finally {
    busy.value = false
  }
}
</script>

<template>
  <div
    v-if="dialog.open"
    class="overlay"
    @click.self="dialog.close()"
    @keydown.esc="dialog.close()"
  >
    <form class="dialog" role="dialog" aria-modal="true" @submit.prevent="save">
      <h1>{{ isEdit ? 'Arbeit bearbeiten' : 'Arbeit anlegen' }}</h1>
      <p class="sub">Kundentermin planen und Zeit festlegen</p>
      <hr />

      <p v-if="error" class="error">{{ error }}</p>
      <p v-if="loading" class="muted">Wird geladen …</p>

      <template v-else>
        <div class="field">
          <label for="jd-title">Bezeichnung der Arbeit</label>
          <input id="jd-title" v-model="form.title" required autofocus />
        </div>

        <div class="field">
          <label for="jd-customer">Kunde</label>
          <input id="jd-customer" v-model="form.customer" />
        </div>

        <div class="field">
          <label>Priorität</label>
          <div class="prios">
            <button
              v-for="p in priorities"
              :key="p.value"
              type="button"
              class="prio"
              :class="{ active: form.priority === p.value }"
              :style="form.priority === p.value ? { background: p.soft } : {}"
              @click="form.priority = p.value"
            >
              <span class="dot" :style="{ background: p.color }"></span>
              {{ p.label }}
            </button>
          </div>
        </div>

        <div class="two">
          <div class="field">
            <label for="jd-start">Arbeitsbeginn</label>
            <input id="jd-start" v-model="form.startsAt" type="datetime-local" />
          </div>
          <div class="field">
            <label for="jd-end">Arbeitsende (geplant)</label>
            <input
              id="jd-end"
              v-model="form.dueAt"
              type="datetime-local"
              @input="endTouched = true"
            />
          </div>
        </div>

        <div class="two">
          <div class="field">
            <label>Geplante Arbeitszeit</label>
            <div class="stepper">
              <strong>{{ formatHours(form.plannedMinutes, 'Stunden') }}</strong>
              <button type="button" aria-label="30 Minuten weniger" @click="stepPlanned(-1)">
                –
              </button>
              <button type="button" aria-label="30 Minuten mehr" @click="stepPlanned(1)">+</button>
            </div>
          </div>
          <div class="field">
            <label for="jd-worker">Zugewiesener Arbeiter</label>
            <select id="jd-worker" v-model="form.assigneeId">
              <option :value="null">Noch niemand</option>
              <option v-for="w in workshop.workers" :key="w.id" :value="w.id">
                {{ w.fullName }}
              </option>
            </select>
          </div>
        </div>

        <div v-if="job" class="extend">
          <div>
            <strong>Arbeitszeit nachträglich erhöhen</strong>
            <p>
              Dauert die Arbeit länger, kann die Zeit hier erweitert werden. Alle Folgetermine
              verschieben sich automatisch.
            </p>
          </div>
          <button type="button" :disabled="busy" @click="extendOneHour">+ 1 Stunde</button>
        </div>

        <div v-if="job" class="field">
          <label>Status</label>
          <label class="status">
            <input v-model="form.done" type="checkbox" />
            Arbeit ist erledigt (abhaken)
          </label>
        </div>
      </template>

      <hr />
      <div class="actions">
        <button v-if="job" type="button" class="delete" :disabled="busy" @click="remove">
          Löschen
        </button>
        <button type="button" class="secondary cancel" @click="dialog.close()">Abbrechen</button>
        <button type="submit" class="save" :disabled="busy || loading">Speichern</button>
      </div>
    </form>
  </div>
</template>

<style scoped>
.overlay {
  position: fixed;
  inset: 0;
  z-index: 50;
  background: rgba(31, 36, 48, 0.4);
  display: flex;
  justify-content: center;
  align-items: flex-start;
  overflow-y: auto;
  padding: 3rem 1rem;
}

.dialog {
  width: 560px;
  max-width: 100%;
  background: var(--surface);
  border-radius: 12px;
  padding: 2rem;
  box-shadow: 0 20px 50px rgba(16, 24, 40, 0.25);
}

h1 {
  margin: 0;
}

.sub {
  margin: 0.15rem 0 0;
  font-size: 0.75rem;
  color: var(--muted);
}

hr {
  border: none;
  border-top: 1px solid var(--border);
  margin: 1.5rem 0;
}

.field {
  margin-bottom: 1.25rem;
}

.two {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 1.25rem;
}

.prios {
  display: grid;
  grid-template-columns: repeat(3, 1fr);
  gap: 1rem;
}

.prio {
  display: flex;
  align-items: center;
  gap: 0.5rem;
  height: 40px;
  padding: 0 0.875rem;
  background: var(--surface-muted);
  color: var(--muted);
  font-weight: 500;
}

.prio.active {
  color: var(--text);
  font-weight: 600;
}

.stepper {
  display: flex;
  align-items: center;
  gap: 0.4rem;
  height: 40px;
  padding: 0 0.4rem 0 0.875rem;
  background: var(--surface-muted);
  border-radius: 8px;
}

.stepper strong {
  flex: 1;
  font-size: 0.8125rem;
}

.stepper button {
  width: 28px;
  height: 28px;
  padding: 0;
  border-radius: 6px;
  background: var(--surface);
  color: var(--text);
  font-weight: 700;
}

.extend {
  display: flex;
  align-items: center;
  gap: 1rem;
  padding: 0.9rem 1rem;
  margin-bottom: 1.25rem;
  background: var(--warning-soft);
  border-left: 3px solid var(--warning);
  border-radius: 10px;
}

.extend strong {
  font-size: 0.75rem;
  color: var(--warning-text);
}

.extend p {
  margin: 0.2rem 0 0;
  font-size: 0.625rem;
  color: var(--muted);
}

.extend button {
  flex: none;
  height: 28px;
  width: 108px;
  background: var(--surface);
  color: var(--warning-text);
  font-size: 0.625rem;
  border-radius: 6px;
}

.status {
  display: flex;
  align-items: center;
  gap: 0.75rem;
  height: 44px;
  padding: 0 0.875rem;
  margin: 0;
  background: var(--surface-muted);
  border-radius: 8px;
  font-size: 0.75rem;
  font-weight: 500;
  color: var(--text);
  cursor: pointer;
}

.status input {
  width: 20px;
  height: 20px;
}

.actions {
  display: flex;
  justify-content: flex-end;
  gap: 0.75rem;
}

.actions button {
  height: 44px;
}

.cancel {
  width: 104px;
}

.save {
  width: 112px;
}

.delete {
  margin-right: auto;
  background: none;
  color: var(--danger);
  padding: 0;
}

@media (max-width: 560px) {
  .two,
  .prios {
    grid-template-columns: 1fr 1fr;
  }
}
</style>
