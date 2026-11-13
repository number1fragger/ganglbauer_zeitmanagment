<script setup lang="ts">
import { computed, onMounted, reactive, ref, watch } from 'vue'
import { absencesApi } from '@/api'
import { errorMessage } from '@/api/http'
import type { Absence, AbsenceInput, AbsenceType, AbsenceTypeOption, UserRef } from '@/api/types'
import { confirmAction } from '@/composables/useConfirm'
import { toast } from '@/composables/useToast'
import { formatDateTimeShort, toDateKey, toLocalInput } from '@/utils/time'
import AppIcon from './AppIcon.vue'
import BaseModal from './BaseModal.vue'

/**
 * Urlaub und persoenliche Termine eintragen bzw. aendern – nur Chef und
 * Sekretaerin (das Backend prueft das zusaetzlich). Ganztaegig: nur Tage,
 * keine Uhrzeiten. Bereits geplante Auftraege bleiben unveraendert; sind
 * welche betroffen, wird das nach dem Speichern gemeldet.
 */
const props = defineProps<{
  absence?: Absence | null
  workers: UserRef[]
  defaults?: { userId?: number | null; day?: Date }
}>()
const emit = defineEmits<{ close: []; saved: [absence: Absence]; deleted: [id: number] }>()

const types = ref<AbsenceTypeOption[]>([])
const editing = computed(() => !!props.absence)
const day = props.defaults?.day ?? new Date()

function at(date: Date, hours: number): Date {
  const result = new Date(date)
  result.setHours(hours, 0, 0, 0)
  return result
}

const form = reactive({
  userId: props.absence?.user.id ?? props.defaults?.userId ?? null,
  type: (props.absence?.type ?? 'urlaub') as AbsenceType,
  allDay: props.absence?.allDay ?? true,
  startDate: props.absence ? toDateKey(new Date(props.absence.startsAt)) : toDateKey(day),
  endDate: props.absence ? props.absence.lastDay : toDateKey(day),
  startsAt: toLocalInput(props.absence ? new Date(props.absence.startsAt) : at(day, 9)),
  endsAt: toLocalInput(props.absence ? new Date(props.absence.endsAt) : at(day, 10)),
  note: props.absence?.note ?? '',
})

const error = ref('')
const busy = ref(false)

onMounted(async () => {
  try {
    types.value = await absencesApi.types()
  } catch (e) {
    error.value = errorMessage(e)
  }
})

// Art gewaehlt: Urlaub/Berufsschule ganztaegig, Termine stundenweise (nur beim Anlegen).
watch(
  () => form.type,
  (type) => {
    if (editing.value) return
    const option = types.value.find((t) => t.value === type)
    if (option) form.allDay = option.allDay
  },
)

// Der letzte Tag kann nicht vor dem ersten liegen.
watch(
  () => form.startDate,
  (start) => {
    if (start && form.endDate < start) form.endDate = start
  },
)

/** Personen zur Auswahl; die eingetragene bleibt waehlbar, auch wenn sie nicht in der Liste steht. */
const people = computed(() => {
  const current = props.absence?.user
  return current && !props.workers.some((w) => w.id === current.id)
    ? [...props.workers, current]
    : props.workers
})

function payload(): AbsenceInput {
  return form.allDay
    ? {
        userId: form.userId,
        type: form.type,
        allDay: true,
        startDate: form.startDate,
        endDate: form.endDate,
        note: form.note || null,
      }
    : {
        userId: form.userId,
        type: form.type,
        allDay: false,
        startsAt: new Date(form.startsAt).toISOString(),
        endsAt: new Date(form.endsAt).toISOString(),
        note: form.note || null,
      }
}

async function save(): Promise<void> {
  error.value = ''
  busy.value = true
  try {
    const saved = props.absence
      ? await absencesApi.update(props.absence.id, payload())
      : await absencesApi.create(payload())
    const affected = saved.conflicts ?? []
    if (affected.length) {
      const list = affected
        .slice(0, 3)
        .map((job) => `„${job.title}“${job.startsAt ? ` (${formatDateTimeShort(new Date(job.startsAt))})` : ''}`)
        .join(', ')
      toast(
        `Gespeichert. Achtung: ${affected.length} Auftrag/Aufträge fallen in diese Abwesenheit und bleiben unverändert eingeplant: ${list}${affected.length > 3 ? ' …' : ''}`,
        'warning',
      )
    } else {
      toast(props.absence ? 'Abwesenheit geändert.' : 'Abwesenheit eingetragen.', 'success')
    }
    emit('saved', saved)
  } catch (e) {
    error.value = errorMessage(e)
  } finally {
    busy.value = false
  }
}

async function remove(): Promise<void> {
  if (!props.absence) return
  const ok = await confirmAction({
    title: 'Abwesenheit löschen?',
    message: `${props.absence.label} von ${props.absence.user.fullName} wird aus dem Kalender entfernt.`,
    confirmLabel: 'Löschen',
    tone: 'danger',
  })
  if (!ok) return
  try {
    await absencesApi.remove(props.absence.id)
    toast('Abwesenheit gelöscht.', 'success')
    emit('deleted', props.absence.id)
  } catch (e) {
    error.value = errorMessage(e)
  }
}
</script>

<template>
  <BaseModal
    :title="editing ? 'Abwesenheit bearbeiten' : 'Abwesenheit eintragen'"
    subtitle="Urlaub oder persönlicher Termin – im Kalender sichtbar, Aufträge werden nicht verschoben."
    :width="520"
    @close="emit('close')"
  >
    <form class="absence-form" @submit.prevent="save">
      <div class="absence-form__pair">
        <label class="field">
          <span class="field__label">Mitarbeiter</span>
          <select v-model="form.userId" class="input" required>
            <option :value="null" disabled>Bitte auswählen</option>
            <option v-for="person in people" :key="person.id" :value="person.id">
              {{ person.fullName }}
            </option>
          </select>
        </label>
        <label class="field">
          <span class="field__label">Art</span>
          <select v-model="form.type" class="input" required>
            <option v-for="type in types" :key="type.value" :value="type.value">
              {{ type.label }}
            </option>
          </select>
        </label>
      </div>

      <label class="checkbox">
        <input v-model="form.allDay" type="checkbox" />
        Ganztägig (ohne Uhrzeiten)
      </label>

      <div v-if="form.allDay" class="absence-form__pair">
        <label class="field">
          <span class="field__label">Erster Tag</span>
          <input v-model="form.startDate" class="input" type="date" required />
        </label>
        <label class="field">
          <span class="field__label">Letzter Tag</span>
          <input v-model="form.endDate" class="input" type="date" :min="form.startDate" required />
        </label>
      </div>
      <div v-else class="absence-form__pair">
        <label class="field">
          <span class="field__label">Beginn</span>
          <input v-model="form.startsAt" class="input" type="datetime-local" step="900" required />
        </label>
        <label class="field">
          <span class="field__label">Ende</span>
          <input
            v-model="form.endsAt"
            class="input"
            type="datetime-local"
            step="900"
            :min="form.startsAt"
            required
          />
        </label>
      </div>

      <label class="field">
        <span class="field__label">Interne Notiz <small>(optional, nur Chef und Sekretärin)</small></span>
        <textarea
          v-model="form.note"
          class="input"
          rows="2"
          maxlength="2000"
          placeholder="z. B. Vertretung geregelt"
        />
      </label>

      <p v-if="absence?.createdBy || absence?.createdAt" class="absence-form__meta">
        <AppIcon name="info" :size="12" />
        Eingetragen<template v-if="absence.createdBy"> von {{ absence.createdBy.fullName }}</template
        ><template v-if="absence.createdAt">
          am {{ formatDateTimeShort(new Date(absence.createdAt)) }}</template
        >
      </p>

      <p v-if="error" class="form-error" role="alert">{{ error }}</p>

      <footer class="absence-form__actions">
        <button
          v-if="editing"
          type="button"
          class="btn btn--ghost absence-form__delete"
          :disabled="busy"
          @click="remove"
        >
          <AppIcon name="trash" :size="15" /> Löschen
        </button>
        <button type="button" class="btn btn--outline" @click="emit('close')">Abbrechen</button>
        <button class="btn btn--primary" :disabled="busy">Speichern</button>
      </footer>
    </form>
  </BaseModal>
</template>

<style scoped>
.absence-form {
  display: grid;
  gap: 16px;
}

.absence-form__pair {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 14px;
}

.field__label small {
  color: var(--faint);
  font-weight: 500;
}

.absence-form__meta {
  display: flex;
  align-items: center;
  gap: 6px;
  color: var(--muted);
  font-size: 11px;
}

.absence-form__actions {
  display: flex;
  flex-wrap: wrap;
  justify-content: flex-end;
  gap: 10px;
  padding-top: 18px;
  border-top: 1px solid var(--border);
}

.absence-form__delete {
  margin-right: auto;
  color: var(--danger);
}

@media (max-width: 520px) {
  .absence-form__pair {
    grid-template-columns: 1fr;
  }

  .absence-form__actions .btn {
    flex: 1;
  }
}
</style>
