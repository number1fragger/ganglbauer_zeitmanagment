<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import { jobsApi } from '@/api'
import { errorMessage } from '@/api/http'
import type { Job, JobInput, Priority, UserRef } from '@/api/types'
import { confirmAction } from '@/composables/useConfirm'
import { useJobActions } from '@/composables/useJobActions'
import { toast } from '@/composables/useToast'
import { useAuthStore } from '@/stores/auth'
import { DEFAULT_SLOT_MINUTES } from '@/utils/calendar'
import { canStart, canWorkOn, isDone, priorities } from '@/utils/domain'
import {
  addMinutes,
  formatDateTimeShort,
  formatDuration,
  formatTime,
  sameDay,
  spanDays,
  toLocalInput,
} from '@/utils/time'
import AppIcon from './AppIcon.vue'
import BaseModal from './BaseModal.vue'
import FollowUpDialog from './FollowUpDialog.vue'
import LiveDuration from './LiveDuration.vue'
import StatusChip from './StatusChip.vue'

/**
 * Detail-, Anlege- und Bearbeitungsdialog einer Arbeit (wie eine
 * Trello-Karte). Nur der Titel ist Pflicht – ein Termin ist optional, eine
 * geplante Arbeitsdauer gibt es nicht. Arbeiter sehen die Karte, koennen aber nur arbeiten
 * (starten, pausieren, abschliessen), nicht planen.
 */
const props = defineProps<{
  job?: Job | null
  /** Vorbelegung beim Anlegen, z. B. aus einem Klick in den Kalender. */
  defaults?: { startsAt?: Date; assigneeId?: number | null }
  workers: UserRef[]
}>()

const emit = defineEmits<{ close: []; saved: [job: Job]; deleted: [id: number]; changed: [] }>()

const auth = useAuthStore()
const current = ref<Job | null>(props.job ?? null)
const editing = computed(() => current.value !== null)
const canEdit = computed(() => auth.isPlanner)
const done = computed(() => current.value !== null && isDone(current.value))
const started = computed(() => current.value?.started ?? false)

const initialStart = props.job?.startsAt
  ? new Date(props.job.startsAt)
  : (props.defaults?.startsAt ?? null)
const initialEnd = props.job?.endsAt
  ? new Date(props.job.endsAt)
  : initialStart
    ? addMinutes(initialStart, DEFAULT_SLOT_MINUTES)
    : null

const form = reactive({
  title: props.job?.title ?? '',
  description: props.job?.description ?? '',
  customer: props.job?.customer ?? '',
  priority: (props.job?.priority ?? 'mittel') as Priority,
  assigneeId: props.job?.assignee?.id ?? props.defaults?.assigneeId ?? null,
  scheduled: initialStart !== null,
  startsAt: initialStart ? toLocalInput(initialStart) : '',
  endsAt: initialEnd ? toLocalInput(initialEnd) : '',
  unlockSchedule: false,
})

/** Der aktuelle Zustaendige muss auswaehlbar sein, auch wenn er nicht in der Liste steht. */
const workerOptions = computed(() => {
  const assignee = current.value?.assignee
  return assignee && !props.workers.some((w) => w.id === assignee.id)
    ? [...props.workers, assignee]
    : props.workers
})

const scheduleLocked = computed(() => done.value || (started.value && !form.unlockSchedule))
const error = ref('')
const busy = ref(false)

/** Laenge des Kalendereintrags – keine Arbeitszeit, kann mehrere Tage umfassen. */
const calendarInfo = computed(() => {
  if (!form.scheduled || !form.startsAt || !form.endsAt) return null
  const start = new Date(form.startsAt)
  const end = new Date(form.endsAt)
  if (!(end > start)) return 'Das Ende muss nach dem Beginn liegen.'
  const days = spanDays(start, end)
  const length = formatDuration((end.getTime() - start.getTime()) / 1000)
  return days > 1 ? `Mehrtägiger Termin über ${days} Tage` : `Termin im Kalender: ${length}`
})

function toggleSchedule(on: boolean): void {
  form.scheduled = on
  if (on && !form.startsAt) {
    const start = props.defaults?.startsAt ?? nextHalfHour()
    form.startsAt = toLocalInput(start)
    form.endsAt = toLocalInput(addMinutes(start, DEFAULT_SLOT_MINUTES))
  }
}

function nextHalfHour(): Date {
  const now = new Date()
  now.setMinutes(now.getMinutes() < 30 ? 30 : 60, 0, 0)
  return now
}

/** Beginn verschoben: das Ende rueckt mit, die Kalenderdauer bleibt. */
let lastStart = form.startsAt
function onStartChange(): void {
  if (!form.startsAt) return
  const previous = lastStart ? new Date(lastStart) : null
  const end = form.endsAt ? new Date(form.endsAt) : null
  const duration =
    previous && end && end > previous
      ? end.getTime() - previous.getTime()
      : DEFAULT_SLOT_MINUTES * 60_000
  form.endsAt = toLocalInput(new Date(new Date(form.startsAt).getTime() + duration))
  lastStart = form.startsAt
}

function payload(): JobInput {
  const scheduled = form.scheduled && form.startsAt
  return {
    title: form.title,
    description: form.description || null,
    customer: form.customer || null,
    priority: form.priority,
    assigneeId: form.assigneeId,
    startsAt: scheduled ? new Date(form.startsAt).toISOString() : null,
    endsAt: scheduled && form.endsAt ? new Date(form.endsAt).toISOString() : null,
    confirmStartedChange: form.unlockSchedule,
  }
}

async function save(): Promise<void> {
  if (!canEdit.value) return
  error.value = ''
  busy.value = true
  try {
    const job = current.value
      ? await jobsApi.update(current.value.id, payload())
      : await jobsApi.create(payload())
    toast(
      current.value
        ? 'Änderungen gespeichert.'
        : job.scheduled
          ? 'Auftrag eingeplant.'
          : 'Auftrag angelegt.',
      'success',
    )
    emit('saved', job)
  } catch (e) {
    error.value = errorMessage(e)
  } finally {
    busy.value = false
  }
}

/** Termin entfernen – der Auftrag kehrt in die To-do-Liste zurueck, Ist-Zeit bleibt. */
async function unschedule(): Promise<void> {
  if (!current.value) return
  busy.value = true
  try {
    current.value = { ...current.value, ...(await jobsApi.unschedule(current.value.id)) }
    form.scheduled = false
    toast('Termin entfernt – der Auftrag steht wieder in der To-do-Liste.', 'success')
    emit('changed')
  } catch (e) {
    error.value = errorMessage(e)
  } finally {
    busy.value = false
  }
}

async function remove(): Promise<void> {
  if (!current.value) return
  const ok = await confirmAction({
    title: 'Arbeit löschen?',
    message: `„${current.value.title}“ und alle erfassten Arbeitsabschnitte werden endgültig gelöscht.`,
    confirmLabel: 'Löschen',
    tone: 'danger',
  })
  if (!ok) return
  try {
    await jobsApi.remove(current.value.id)
    toast('Arbeit gelöscht.', 'success')
    emit('deleted', current.value.id)
  } catch (e) {
    error.value = errorMessage(e)
  }
}

// ---- Arbeitsablauf (Start/Pause/Abschluss) --------------------------------

async function reload(): Promise<void> {
  if (!current.value) return
  try {
    current.value = await jobsApi.get(current.value.id)
  } catch {
    /* Anzeige bleibt beim letzten Stand */
  }
}

const actions = useJobActions(async () => {
  await reload()
  emit('changed')
})

onMounted(reload)

async function onFollowUpApplied(): Promise<void> {
  actions.followUp.value = null
  await reload()
  emit('changed')
}

const entries = computed(() => current.value?.timeEntries ?? [])

function entryLabel(startedAt: string, endedAt: string | null): string {
  const start = new Date(startedAt)
  if (!endedAt) return `${formatDateTimeShort(start)} – läuft`
  const end = new Date(endedAt)
  return sameDay(start, end)
    ? `${formatDateTimeShort(start)} – ${formatTime(end)}`
    : `${formatDateTimeShort(start)} – ${formatDateTimeShort(end)}`
}
</script>

<template>
  <BaseModal
    :title="editing ? (canEdit ? 'Auftrag bearbeiten' : 'Auftrag') : 'Neuer Auftrag'"
    :subtitle="
      editing
        ? undefined
        : 'Nur der Titel ist Pflicht. Ohne Termin landet der Auftrag in der To-do-Liste neben dem Kalender.'
    "
    :width="860"
    @close="emit('close')"
  >
    <template v-if="current" #header>
      <div class="job-head">
        <StatusChip :job="current" />
        <span v-if="current.scheduled" class="chip chip--plain">
          <AppIcon name="calendar" :size="11" /> Im Kalender
        </span>
        <span v-else-if="!done" class="chip chip--plain">
          <AppIcon name="list" :size="11" /> In der To-do-Liste
        </span>
      </div>
    </template>

    <form class="job-form" @submit.prevent="save">
      <div class="job-form__main">
        <label class="field">
          <span class="field__label">Titel</span>
          <input
            v-model="form.title"
            class="input job-form__title"
            maxlength="150"
            required
            :disabled="!canEdit"
            placeholder="z. B. Bremsen hinten tauschen"
            autofocus
          />
        </label>

        <label class="field">
          <span class="field__label">Beschreibung</span>
          <textarea
            v-model="form.description"
            class="input"
            rows="4"
            maxlength="5000"
            :disabled="!canEdit"
            placeholder="Was ist zu tun? Teile, Hinweise, Kundenwünsche …"
          />
        </label>

        <!-- Ist-Zeit: nur die erfassten Arbeitsabschnitte, unabhaengig vom Kalender -->
        <section v-if="current" class="time-box">
          <h3 class="section-title">Zeiterfassung</h3>
          <dl class="time-box__stats">
            <div>
              <dt>Ist-Zeit</dt>
              <dd>
                <LiveDuration :job="current" />
              </dd>
            </div>
            <div>
              <dt>Erster Start</dt>
              <dd class="time-box__small">
                {{
                  current.firstStartedAt
                    ? formatDateTimeShort(new Date(current.firstStartedAt))
                    : '–'
                }}
              </dd>
            </div>
            <div>
              <dt>Abschnitte</dt>
              <dd>
                {{ current.entryCount
                }}<small v-if="current.workedDays > 1"> an {{ current.workedDays }} Tagen</small>
              </dd>
            </div>
          </dl>

          <ul v-if="entries.length" class="entries">
            <li
              v-for="entry in entries"
              :key="entry.id"
              :class="{ 'entries--running': entry.running }"
            >
              <span class="tabular">{{ entryLabel(entry.startedAt, entry.endedAt) }}</span>
              <span class="entries__who">{{ entry.user.shortName }}</span>
              <span class="tabular entries__dur">{{
                entry.running ? 'läuft' : formatDuration(entry.durationSeconds)
              }}</span>
              <span
                v-if="entry.autoClosed"
                class="chip"
                style="--chip: var(--warning)"
                title="Nicht pausiert – automatisch am Abend beendet"
              >
                auto. beendet
              </span>
            </li>
          </ul>
          <p v-else class="time-box__empty">
            Noch nicht gestartet – es wurde keine Arbeitszeit erfasst.
          </p>

          <div class="time-box__actions">
            <button
              v-if="canStart(current, auth.user)"
              type="button"
              class="btn btn--primary"
              :disabled="actions.busyId.value !== null"
              @click="actions.start(current)"
            >
              <AppIcon name="play" :size="14" />
              {{ current.status === 'in_arbeit' ? 'Fortsetzen' : 'Arbeit starten' }}
            </button>
            <button
              v-if="current.running && canWorkOn(current, auth.user)"
              type="button"
              class="btn btn--warning"
              :disabled="actions.busyId.value !== null"
              @click="actions.pause(current)"
            >
              <AppIcon name="pause" :size="14" /> Pausieren
            </button>
            <button
              v-if="!done && canWorkOn(current, auth.user)"
              type="button"
              class="btn btn--success"
              :disabled="actions.busyId.value !== null"
              @click="actions.complete(current)"
            >
              <AppIcon name="check" :size="14" /> Abschließen
            </button>
            <button
              v-if="done && canWorkOn(current, auth.user)"
              type="button"
              class="btn btn--outline"
              :disabled="actions.busyId.value !== null"
              @click="actions.reopen(current)"
            >
              <AppIcon name="reopen" :size="14" /> Wieder öffnen
            </button>
          </div>
        </section>
      </div>

      <aside class="job-form__side">
        <label class="field">
          <span class="field__label">Zuständig</span>
          <select v-model="form.assigneeId" class="input" :disabled="!canEdit || current?.running">
            <option :value="null">Noch niemand</option>
            <option v-for="worker in workerOptions" :key="worker.id" :value="worker.id">
              {{ worker.fullName }}
            </option>
          </select>
        </label>

        <fieldset class="field job-form__plain">
          <legend class="field__label">Priorität</legend>
          <div class="segmented job-form__priorities">
            <button
              v-for="p in priorities"
              :key="p.value"
              type="button"
              :aria-pressed="form.priority === p.value"
              :disabled="!canEdit"
              @click="form.priority = p.value"
            >
              <span class="dot" :style="{ '--dot': p.color }" />{{ p.label }}
            </button>
          </div>
        </fieldset>

        <label class="field">
          <span class="field__label">Kunde</span>
          <input
            v-model="form.customer"
            class="input"
            maxlength="150"
            :disabled="!canEdit"
            placeholder="optional"
          />
        </label>

        <div class="field schedule">
          <label class="checkbox schedule__toggle">
            <input
              type="checkbox"
              :checked="form.scheduled"
              :disabled="!canEdit || scheduleLocked"
              @change="toggleSchedule(($event.target as HTMLInputElement).checked)"
            />
            <AppIcon name="calendar" :size="15" /> Im Kalender einplanen
          </label>

          <template v-if="form.scheduled">
            <label class="field">
              <span class="field__label">Beginn</span>
              <input
                v-model="form.startsAt"
                class="input"
                type="datetime-local"
                step="900"
                required
                :disabled="!canEdit || scheduleLocked"
                @change="onStartChange"
              />
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
                :disabled="!canEdit || scheduleLocked"
              />
            </label>
            <p v-if="calendarInfo" class="field__hint">{{ calendarInfo }}</p>
          </template>
          <p v-else class="field__hint">
            Ohne Termin steht der Auftrag in der To-do-Liste und kann in den Kalender gezogen
            werden.
          </p>
          <button
            v-if="current?.scheduled && !done && canEdit"
            type="button"
            class="link schedule__remove"
            :disabled="busy"
            @click="unschedule"
          >
            <AppIcon name="calendar-off" :size="13" /> Aus dem Kalender nehmen (zurück in die
            To-do-Liste)
          </button>

          <p v-if="done && canEdit" class="field__hint schedule__lock">
            <AppIcon name="info" :size="13" /> Abgeschlossen – Termin ist gesperrt.
          </p>
          <label v-else-if="started && canEdit" class="checkbox schedule__lock">
            <input v-model="form.unlockSchedule" type="checkbox" />
            Bereits begonnen – Termin trotzdem ändern
          </label>
        </div>
      </aside>

      <p v-if="error" class="form-error job-form__wide" role="alert">{{ error }}</p>

      <footer class="job-form__actions job-form__wide">
        <button
          v-if="editing && canEdit"
          type="button"
          class="btn btn--ghost job-form__delete"
          :disabled="busy"
          @click="remove"
        >
          <AppIcon name="trash" :size="15" /> Löschen
        </button>
        <button type="button" class="btn btn--outline" @click="emit('close')">
          {{ canEdit ? 'Abbrechen' : 'Schließen' }}
        </button>
        <button v-if="canEdit" class="btn btn--primary" :disabled="busy">
          {{ editing ? 'Speichern' : form.scheduled ? 'Einplanen' : 'In To-do-Liste anlegen' }}
        </button>
      </footer>
    </form>

    <FollowUpDialog
      v-if="actions.followUp.value && current"
      :follow-up="actions.followUp.value"
      :title="current.title"
      @close="actions.followUp.value = null"
      @applied="onFollowUpApplied"
    />
  </BaseModal>
</template>

<style scoped>
.job-head {
  display: flex;
  flex-wrap: wrap;
  gap: 6px;
  margin-top: 8px;
}

.job-form {
  display: grid;
  grid-template-columns: minmax(0, 1fr) 280px;
  gap: 18px 28px;
}

.job-form__main,
.job-form__side {
  display: flex;
  flex-direction: column;
  gap: 16px;
  min-width: 0;
}

.job-form__wide {
  grid-column: 1 / -1;
}

.job-form__title {
  height: 44px;
  font-size: 15px;
  font-weight: 600;
}

.job-form__plain {
  margin: 0;
  padding: 0;
  border: 0;
}

.job-form__plain legend {
  margin-bottom: 6px;
}

.job-form__priorities {
  display: flex;
}

.job-form__priorities button {
  display: inline-flex;
  flex: 1;
  align-items: center;
  justify-content: center;
  gap: 6px;
  min-width: 0;
}

.field__label small {
  color: var(--faint);
  font-weight: 500;
}

.schedule {
  gap: 10px;
  padding: 12px;
  border: 1px solid var(--border);
  border-radius: var(--radius);
  background: var(--surface-alt);
}

.schedule__toggle {
  font-weight: 600;
}

.schedule .input[type='datetime-local'] {
  font-size: 12px;
}

.schedule__remove {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  align-self: flex-start;
  font-size: 12px;
  text-align: left;
}

.schedule__lock {
  display: flex;
  align-items: center;
  gap: 6px;
  color: var(--warning-ink);
  font-size: 11px;
}

.time-box {
  display: flex;
  flex-direction: column;
  gap: 12px;
  padding: 16px;
  border: 1px solid var(--border);
  border-radius: var(--radius-lg);
  background: var(--surface-alt);
}

.time-box__stats {
  display: grid;
  grid-template-columns: repeat(3, 1fr);
  gap: 10px;
  margin: 0;
}

.time-box__stats dt {
  color: var(--muted);
  font-size: 11px;
  font-weight: 600;
}

.time-box__stats dd {
  margin: 2px 0 0;
  font-size: 17px;
  font-weight: 700;
}

.time-box__stats small {
  color: var(--muted);
  font-size: 11px;
  font-weight: 500;
}

.time-box__small {
  font-size: 13px !important;
  font-weight: 600 !important;
}

.time-box__empty {
  color: var(--muted);
  font-size: 12px;
}

.time-box__actions {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
}

.entries {
  display: grid;
  gap: 4px;
  max-height: 180px;
  margin: 0;
  padding: 0;
  overflow-y: auto;
  list-style: none;
}

.entries li {
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 6px 10px;
  border-radius: var(--radius-sm);
  background: var(--surface);
  font-size: 12px;
}

.entries--running {
  box-shadow: inset 3px 0 0 var(--primary);
}

.entries__who {
  color: var(--muted);
}

.entries__dur {
  margin-left: auto;
  font-weight: 600;
}

.job-form__actions {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  justify-content: flex-end;
  gap: 10px;
  padding-top: 18px;
  border-top: 1px solid var(--border);
}

.job-form__actions .btn {
  min-width: 104px;
}

.job-form__delete {
  margin-right: auto;
  color: var(--danger);
}

@media (max-width: 760px) {
  .job-form {
    grid-template-columns: 1fr;
  }

  .job-form__actions .btn {
    flex: 1;
  }

  .job-form__delete {
    flex-basis: 100%;
    order: 3;
  }
}
</style>
