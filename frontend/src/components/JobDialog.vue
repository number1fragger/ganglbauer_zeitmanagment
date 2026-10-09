<script setup lang="ts">
import { computed, reactive, ref } from 'vue'
import { jobsApi } from '@/api'
import { errorMessage } from '@/api/http'
import type { Job, JobInput, Priority, UserRef } from '@/api/types'
import { priorities } from '@/utils/domain'
import { addMinutes, formatHours, toLocalInput } from '@/utils/time'
import BaseModal from './BaseModal.vue'

const STEP_MINUTES = 30

const props = defineProps<{
  job?: Job | null
  /** Vorbelegung beim Anlegen, z. B. aus einem Klick in den Kalender. */
  defaults?: { startsAt?: Date; assigneeId?: number | null }
  workers: UserRef[]
}>()

const emit = defineEmits<{ close: []; saved: [job: Job]; deleted: [id: number] }>()

const editing = computed(() => !!props.job)
const start = props.job ? new Date(props.job.startsAt) : (props.defaults?.startsAt ?? defaultStart())

const form = reactive({
  title: props.job?.title ?? '',
  customer: props.job?.customer ?? '',
  priority: (props.job?.priority ?? 'mittel') as Priority,
  startsAt: toLocalInput(start),
  endsAt: toLocalInput(props.job ? new Date(props.job.endsAt) : addMinutes(start, 120)),
  plannedMinutes: props.job?.plannedMinutes ?? 120,
  assigneeId: props.job?.assignee?.id ?? props.defaults?.assigneeId ?? null,
  done: props.job?.status === 'erledigt',
})

const error = ref('')
const busy = ref(false)

function defaultStart(): Date {
  const now = new Date()
  now.setMinutes(now.getMinutes() < 30 ? 30 : 60, 0, 0)
  return now
}

/** Beginn oder Dauer geaendert: das Ende rueckt mit. */
function syncEnd(): void {
  if (form.startsAt) form.endsAt = toLocalInput(addMinutes(new Date(form.startsAt), form.plannedMinutes))
}

function changePlanned(delta: number): void {
  form.plannedMinutes = Math.max(STEP_MINUTES, form.plannedMinutes + delta)
  syncEnd()
}

function payload(): JobInput {
  return {
    title: form.title,
    customer: form.customer || null,
    priority: form.priority,
    startsAt: new Date(form.startsAt).toISOString(),
    endsAt: new Date(form.endsAt).toISOString(),
    plannedMinutes: form.plannedMinutes,
    assigneeId: form.assigneeId,
    done: form.done,
  }
}

async function run(action: () => Promise<void>): Promise<void> {
  error.value = ''
  busy.value = true
  try {
    await action()
  } catch (e) {
    error.value = errorMessage(e)
  } finally {
    busy.value = false
  }
}

const save = () =>
  run(async () => {
    const job = props.job ? await jobsApi.update(props.job.id, payload()) : await jobsApi.create(payload())
    emit('saved', job)
  })

/** F5 – sofort speichern, damit die Folgetermine im Backend nachruecken. */
const extendByHour = () =>
  run(async () => {
    if (!props.job) return
    const job = await jobsApi.extend(props.job.id, 60)
    form.plannedMinutes = job.plannedMinutes
    form.endsAt = toLocalInput(new Date(job.endsAt))
    emit('saved', job)
  })

const remove = () =>
  run(async () => {
    if (!props.job || !confirm(`„${props.job.title}“ wirklich löschen?`)) return
    await jobsApi.remove(props.job.id)
    emit('deleted', props.job.id)
  })
</script>

<template>
  <BaseModal
    :title="editing ? 'Arbeit bearbeiten' : 'Arbeit anlegen'"
    subtitle="Kundentermin planen und Zeit festlegen"
    @close="emit('close')"
  >
    <form class="job-form" @submit.prevent="save">
      <label class="field">
        <span class="field__label">Bezeichnung der Arbeit</span>
        <input v-model="form.title" class="input" maxlength="150" required autofocus />
      </label>

      <label class="field">
        <span class="field__label">Kunde</span>
        <input v-model="form.customer" class="input" maxlength="150" />
      </label>

      <fieldset class="field job-form__plain">
        <legend class="field__label">Priorität</legend>
        <div class="job-form__priorities">
          <label
            v-for="p in priorities"
            :key="p.value"
            class="priority-option"
            :class="{ 'priority-option--active': form.priority === p.value }"
            :style="{ '--soft': p.soft }"
          >
            <input v-model="form.priority" type="radio" name="priority" :value="p.value" />
            <span class="priority-option__swatch" :style="{ background: p.color }" />
            {{ p.label }}
          </label>
        </div>
      </fieldset>

      <div class="job-form__pair">
        <label class="field">
          <span class="field__label">Arbeitsbeginn</span>
          <input v-model="form.startsAt" class="input" type="datetime-local" step="900" required @change="syncEnd" />
        </label>
        <label class="field">
          <span class="field__label">Arbeitsende (geplant)</span>
          <input v-model="form.endsAt" class="input" type="datetime-local" step="900" :min="form.startsAt" required />
        </label>
      </div>

      <div class="job-form__pair">
        <div class="field">
          <span class="field__label" id="planned-label">Geplante Arbeitszeit</span>
          <div class="stepper" role="group" aria-labelledby="planned-label">
            <strong>{{ formatHours(form.plannedMinutes).replace(' h', ' Stunden') }}</strong>
            <button type="button" aria-label="30 Minuten weniger" @click="changePlanned(-STEP_MINUTES)">–</button>
            <button type="button" aria-label="30 Minuten mehr" @click="changePlanned(STEP_MINUTES)">+</button>
          </div>
        </div>
        <label class="field">
          <span class="field__label">Zugewiesener Arbeiter</span>
          <select v-model="form.assigneeId" class="input">
            <option :value="null">Noch niemand</option>
            <option v-for="worker in workers" :key="worker.id" :value="worker.id">{{ worker.fullName }}</option>
          </select>
        </label>
      </div>

      <section v-if="editing && !form.done" class="extend accent">
        <div>
          <h3 class="extend__title">Arbeitszeit nachträglich erhöhen</h3>
          <p class="extend__text">
            Dauert die Arbeit länger, kann die Zeit hier erweitert werden. Alle Folgetermine verschieben sich
            automatisch.
          </p>
        </div>
        <button type="button" class="extend__button" :disabled="busy" @click="extendByHour">+ 1 Stunde</button>
      </section>

      <div v-if="editing" class="field">
        <span class="field__label">Status</span>
        <label class="done-toggle">
          <input v-model="form.done" type="checkbox" />
          Arbeit ist erledigt (abhaken)
        </label>
      </div>

      <p v-if="error" class="form-error" role="alert">{{ error }}</p>

      <footer class="job-form__actions">
        <button v-if="editing" type="button" class="link job-form__delete" :disabled="busy" @click="remove">
          Arbeit löschen
        </button>
        <button type="button" class="btn btn--large" @click="emit('close')">Abbrechen</button>
        <button class="btn btn--primary btn--large" :disabled="busy">Speichern</button>
      </footer>
    </form>
  </BaseModal>
</template>

<style scoped>
.job-form {
  display: grid;
  gap: 18px;
}

.job-form__plain {
  margin: 0;
  padding: 0;
  border: 0;
}

.job-form__plain legend {
  margin-bottom: 5px;
}

.job-form__priorities {
  display: flex;
  flex-wrap: wrap;
  gap: 16px;
}

.priority-option {
  display: flex;
  align-items: center;
  gap: 8px;
  width: 150px;
  height: 40px;
  padding: 0 14px;
  border-radius: var(--radius);
  background: var(--surface-alt);
  color: var(--muted);
  font-size: 12px;
  font-weight: 500;
  cursor: pointer;
}

.priority-option input {
  position: absolute;
  opacity: 0;
}

.priority-option:has(input:focus-visible) {
  outline: 2px solid var(--primary);
}

.priority-option--active {
  background: var(--soft);
  color: var(--text);
  font-weight: 600;
}

.priority-option__swatch {
  width: 10px;
  height: 10px;
  border-radius: 3px;
}

.job-form__pair {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 20px;
}

.job-form .input[type='datetime-local'],
.job-form select.input {
  font-size: 12px;
}

.stepper {
  display: flex;
  align-items: center;
  gap: 6px;
  height: 40px;
  padding: 0 6px 0 14px;
  border-radius: var(--radius);
  background: var(--surface-alt);
}

.stepper strong {
  flex: 1;
  font-weight: 600;
}

.stepper button {
  width: 28px;
  height: 28px;
  border: 0;
  border-radius: var(--radius-sm);
  background: var(--surface);
  font-weight: 700;
}

.extend {
  --accent: var(--warning);
  position: relative;
  display: flex;
  align-items: center;
  gap: 16px;
  padding: 14px 16px 14px 16px;
  border-radius: var(--radius-lg);
  background: var(--warning-soft);
}

.extend__title {
  color: var(--warning-ink);
  font-size: 12px;
  font-weight: 600;
}

.extend__text {
  margin-top: 4px;
  color: var(--muted);
  font-size: 10px;
}

.extend__button {
  flex: none;
  width: 108px;
  height: 28px;
  border: 0;
  border-radius: var(--radius-sm);
  background: var(--surface);
  color: var(--warning-ink);
  font-size: 10px;
  font-weight: 600;
}

.done-toggle {
  display: flex;
  align-items: center;
  gap: 10px;
  height: 44px;
  padding: 0 14px;
  border-radius: var(--radius);
  background: var(--surface-alt);
  font-size: 12px;
  font-weight: 500;
  cursor: pointer;
}

.done-toggle input {
  width: 20px;
  height: 20px;
  margin: 0;
  accent-color: var(--success);
}

.job-form__actions {
  display: flex;
  align-items: center;
  justify-content: flex-end;
  gap: 12px;
  padding-top: 24px;
  border-top: 1px solid var(--border);
}

.job-form__actions .btn {
  min-width: 104px;
}

.job-form__delete {
  margin-right: auto;
  color: var(--danger);
}

@media (max-width: 560px) {
  .job-form__pair {
    grid-template-columns: 1fr;
  }

  .priority-option {
    flex: 1;
    width: auto;
  }
}
</style>
