<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import { authApi, jobsApi, requestsApi } from '@/api'
import { errorMessage } from '@/api/http'
import type { Job, WorkerStatus, WorkRequest } from '@/api/types'
import AppIcon from '@/components/AppIcon.vue'
import JobDialog from '@/components/JobDialog.vue'
import LiveDuration from '@/components/LiveDuration.vue'
import PageHeader from '@/components/PageHeader.vue'
import TaskCard from '@/components/TaskCard.vue'
import { useJobActions } from '@/composables/useJobActions'
import { useNow } from '@/composables/useNow'
import { toast } from '@/composables/useToast'
import { useAuthStore } from '@/stores/auth'
import { isDone, priorityWeight } from '@/utils/domain'
import {
  addDays,
  describeMoment,
  formatDayMonth,
  formatDuration,
  formatHours,
  formatRange,
  isWeekend,
  nextWorkday,
  relativeDay,
  startOfDay,
  weekdayName,
} from '@/utils/time'

/**
 * Arbeiter-Ansicht: eigene Arbeiten starten, pausieren, fortsetzen und
 * abschliessen – plus "Ich brauche neue Arbeit" (F7/F8).
 */
const auth = useAuthStore()
const now = useNow(30_000)

const jobs = ref<Job[]>([])
const status = ref<WorkerStatus | null>(null)
const request = ref<WorkRequest | null>(null)
const error = ref('')
const loading = ref(true)
const busyKey = ref<string | null>(null)

async function load(): Promise<void> {
  try {
    ;[jobs.value, status.value, request.value] = await Promise.all([
      jobsApi.mine(),
      authApi.status(),
      requestsApi.mine(),
    ])
    error.value = ''
  } catch (e) {
    error.value = errorMessage(e)
  } finally {
    loading.value = false
  }
}

onMounted(load)

// Andere Geraete/Planung koennen etwas aendern – regelmaessig nachladen.
const refresh = window.setInterval(() => document.visibilityState === 'visible' && load(), 60_000)
onBeforeUnmount(() => window.clearInterval(refresh))

const actions = useJobActions(() => load())

// ---- Gruppen ---------------------------------------------------------------

const today = computed(() => startOfDay(now.value))
const tomorrow = computed(() => addDays(today.value, 1))

const running = computed(() => jobs.value.find((job) => job.running) ?? null)

const byImportance = (a: Job, b: Job) =>
  (a.startsAt ?? '9999').localeCompare(b.startsAt ?? '9999') ||
  priorityWeight(b.priority) - priorityWeight(a.priority)

const groups = computed(() => {
  const open = jobs.value.filter((job) => !isDone(job) && job.id !== running.value?.id)
  const isToday = (job: Job) =>
    !!job.startsAt &&
    new Date(job.startsAt) < tomorrow.value &&
    new Date(job.endsAt ?? job.startsAt) > today.value
  const overdue = (job: Job) => !!job.endsAt && new Date(job.endsAt) <= today.value

  return [
    {
      key: 'today',
      title: 'Heute geplant',
      hint: 'inkl. überfälliger und begonnener Arbeiten',
      jobs: open
        .filter(
          (job) => isToday(job) || overdue(job) || (job.status === 'in_arbeit' && !job.scheduled),
        )
        .sort(byImportance),
    },
    {
      key: 'unscheduled',
      title: 'Ohne Termin',
      hint: 'Aufgaben, die noch nicht eingeplant sind',
      jobs: open.filter((job) => !job.scheduled && job.status !== 'in_arbeit').sort(byImportance),
    },
    {
      key: 'later',
      title: 'Später geplant',
      hint: '',
      jobs: open
        .filter((job) => job.scheduled && !isToday(job) && !overdue(job))
        .sort(byImportance),
    },
    {
      key: 'done',
      title: 'Heute abgeschlossen',
      hint: '',
      jobs: jobs.value.filter(isDone),
    },
  ]
})

const todayLabel = computed(() =>
  now.value.toLocaleDateString('de-AT', {
    weekday: 'long',
    day: 'numeric',
    month: 'long',
    year: 'numeric',
  }),
)

const finishText = computed(() =>
  status.value
    ? `Voraussichtlich fertig: ${describeMoment(new Date(status.value.availableFrom))}`
    : '',
)

// ---- Detail -----------------------------------------------------------------

const dialog = ref<Job | null>(null)

async function closeDialog(): Promise<void> {
  dialog.value = null
  await load()
}

// ---- "Ich brauche neue Arbeit" (F7/F8) --------------------------------------

interface Slot {
  key: string
  label: string
  detail: string
  at: Date
  locked: boolean
}

function slotLabel(at: Date): string {
  const day = relativeDay(at) ?? weekdayName(at)
  const part = at.getHours() < 12 ? 'früh' : 'Nachmittag'
  return `${day.charAt(0).toUpperCase()}${day.slice(1)} ${part}`
}

/** Drei waehlbare Zeitpunkte ab dem naechsten Werktag; heute ist zu kurzfristig. */
const slots = computed<Slot[]>(() => {
  const first = nextWorkday(now.value)
  const second = nextWorkday(first)
  const at = (day: Date, hour: number) =>
    new Date(day.getFullYear(), day.getMonth(), day.getDate(), hour)

  const options = [at(first, 7), at(first, 13), at(second, 7)].map((date) => ({
    key: date.toISOString(),
    label: slotLabel(date),
    detail: `${formatDayMonth(date)} · ab ${date.getHours() === 7 ? '07:00' : '13:00'}`,
    at: date,
    locked: false,
  }))

  if (!isWeekend(now.value) && now.value.getHours() < 17) {
    const afternoon = at(startOfDay(now.value), 13)
    options.push({
      key: 'today',
      label: slotLabel(afternoon),
      detail: 'Zu kurzfristig',
      at: afternoon,
      locked: true,
    })
  }

  return options
})

const isRequested = (slot: Slot) =>
  request.value !== null && new Date(request.value.neededAt).getTime() === slot.at.getTime()

async function act(key: string, action: () => Promise<unknown>, success: string): Promise<void> {
  if (busyKey.value) return
  busyKey.value = key
  try {
    await action()
    toast(success, 'success')
    await load()
  } catch (e) {
    toast(errorMessage(e), 'error')
  } finally {
    busyKey.value = null
  }
}

const requestWork = (slot: Slot) =>
  act(
    slot.key,
    () => requestsApi.create(slot.at),
    `Angefragt: ${slot.label}. Die Planung ist informiert.`,
  )

const withdraw = () =>
  request.value &&
  act('withdraw', () => requestsApi.withdraw(request.value!.id), 'Anfrage zurückgezogen.')
</script>

<template>
  <div class="my-work">
    <PageHeader title="Meine Arbeiten" :subtitle="todayLabel" />

    <div class="my-work__layout">
      <main class="my-work__main">
        <p v-if="error" class="form-error" role="alert">
          {{ error }} <button class="link" @click="load">Erneut laden</button>
        </p>

        <!-- Laufende Arbeit -->
        <section v-if="running" class="now card" aria-live="polite">
          <div class="now__label"><span class="now__pulse" aria-hidden="true" /> Läuft gerade</div>
          <button class="now__title" @click="dialog = running">{{ running.title }}</button>
          <p v-if="running.customer" class="muted">{{ running.customer }}</p>
          <div class="now__clock">
            <LiveDuration :job="running" clock />
            <small v-if="running.plannedMinutes"
              >von {{ formatDuration(running.plannedMinutes * 60) }} geplant</small
            >
          </div>
          <div class="now__actions">
            <button
              class="btn btn--warning btn--large"
              :disabled="actions.busyId.value !== null"
              @click="actions.pause(running)"
            >
              <AppIcon name="pause" :size="16" /> Pausieren
            </button>
            <button
              class="btn btn--success btn--large"
              :disabled="actions.busyId.value !== null"
              @click="actions.complete(running)"
            >
              <AppIcon name="check" :size="16" /> Abschließen
            </button>
          </div>
        </section>
        <section v-else-if="!loading" class="idle">
          <AppIcon name="timer" :size="20" />
          <p>
            <strong>Gerade läuft keine Zeiterfassung.</strong>
            <span class="muted"
              >Starte eine Arbeit mit <AppIcon name="play" :size="11" />, dann wird die Zeit
              erfasst.</span
            >
          </p>
        </section>

        <template v-if="loading">
          <div v-for="n in 3" :key="n" class="skeleton my-work__skeleton" />
        </template>

        <template v-for="group in groups" :key="group.key">
          <section v-if="group.jobs.length || group.key === 'today'" class="group">
            <header class="group__head">
              <h2 class="section-title">{{ group.title }}</h2>
              <span class="group__count">{{ group.jobs.length }}</span>
              <small v-if="group.hint" class="muted">{{ group.hint }}</small>
            </header>
            <div v-if="group.jobs.length" class="group__list">
              <TaskCard
                v-for="job in group.jobs"
                :key="job.id"
                :job="job"
                :user="auth.user"
                :busy="actions.busyId.value === job.id"
                @open="dialog = $event"
                @start="actions.start"
                @pause="actions.pause"
                @complete="actions.complete"
              />
            </div>
            <p v-else-if="!loading" class="empty">Für heute ist dir keine Arbeit zugeteilt.</p>
          </section>
        </template>
      </main>

      <aside class="my-work__side">
        <section v-if="status" class="remaining card">
          <span class="remaining__label">Verbleibende Arbeit (laut Plan)</span>
          <strong>{{ formatHours(status.remainingMinutes).replace(' h', ' Stunden') }}</strong>
          <span class="muted">{{ status.openJobs }} offene Arbeit(en) · {{ finishText }}</span>
        </section>

        <section class="card request">
          <h2 class="section-title"><AppIcon name="hand" :size="16" /> Ich brauche neue Arbeit</h2>
          <p class="section-sub">Anfrage frühestens einen Tag im Voraus möglich</p>

          <ul class="slots">
            <li
              v-for="slot in slots"
              :key="slot.key"
              class="slot"
              :class="{ 'slot--locked': slot.locked }"
            >
              <span>
                <strong>{{ slot.label }}</strong>
                <small>{{ slot.detail }}</small>
              </span>
              <span v-if="slot.locked" class="chip chip--plain">gesperrt</span>
              <span v-else-if="isRequested(slot)" class="chip" style="--chip: var(--success)">
                <AppIcon name="check" :size="11" /> angefragt
              </span>
              <button
                v-else
                class="btn btn--outline btn--small"
                :disabled="busyKey !== null"
                @click="requestWork(slot)"
              >
                Anfragen
              </button>
            </li>
          </ul>

          <p v-if="request" class="request__current">
            Angefragt: {{ describeMoment(new Date(request.neededAt)) }}.
            <button class="link" :disabled="busyKey !== null" @click="withdraw">
              Zurückziehen
            </button>
          </p>
        </section>

        <p v-if="running?.startsAt && running.endsAt" class="my-work__hint muted">
          Termin der laufenden Arbeit:
          {{ formatRange(new Date(running.startsAt), new Date(running.endsAt)) }}
        </p>
      </aside>
    </div>

    <JobDialog v-if="dialog" :job="dialog" :workers="[]" @close="closeDialog" @changed="load" />
  </div>
</template>

<style scoped>
.my-work__layout {
  display: grid;
  grid-template-columns: minmax(0, 1fr) 340px;
  align-items: start;
  gap: 24px;
  padding: 0 28px 32px;
}

.my-work__main,
.my-work__side {
  display: flex;
  flex-direction: column;
  gap: 20px;
  min-width: 0;
}

.my-work__side {
  position: sticky;
  top: 20px;
}

.my-work__skeleton {
  height: 96px;
}

.now {
  display: flex;
  flex-direction: column;
  gap: 6px;
  padding: 20px 22px;
  border-color: color-mix(in srgb, var(--primary) 40%, var(--border));
  background: linear-gradient(135deg, var(--primary-soft), var(--surface) 70%);
}

.now__label {
  display: flex;
  align-items: center;
  gap: 8px;
  color: var(--primary);
  font-size: 12px;
  font-weight: 700;
  letter-spacing: 0.02em;
  text-transform: uppercase;
}

.now__pulse {
  width: 9px;
  height: 9px;
  border-radius: 50%;
  background: var(--primary);
  animation: pulse 1.6s ease-in-out infinite;
}

.now__title {
  padding: 0;
  border: 0;
  background: none;
  font-size: 20px;
  font-weight: 700;
  text-align: left;
}

.now__title:hover {
  color: var(--primary);
}

.now__clock {
  display: flex;
  align-items: baseline;
  flex-wrap: wrap;
  gap: 10px;
  margin-top: 6px;
  font-size: 40px;
  font-weight: 700;
  letter-spacing: -0.02em;
}

.now__clock small {
  color: var(--muted);
  font-size: 13px;
  font-weight: 500;
  letter-spacing: 0;
}

.now__actions {
  display: flex;
  flex-wrap: wrap;
  gap: 10px;
  margin-top: 10px;
}

.now__actions .btn {
  flex: 1 1 140px;
}

.idle {
  display: flex;
  align-items: center;
  gap: 14px;
  padding: 14px 18px;
  border: 1px dashed var(--border-strong);
  border-radius: var(--radius-lg);
  color: var(--muted);
}

.idle p {
  display: flex;
  flex-direction: column;
  gap: 2px;
}

.idle strong {
  color: var(--text);
}

.group__head {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 8px;
  margin-bottom: 10px;
}

.group__count {
  padding: 1px 7px;
  border-radius: 10px;
  background: var(--surface-muted);
  color: var(--muted);
  font-size: 11px;
  font-weight: 600;
}

.group__list {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(260px, 1fr));
  gap: 10px;
}

.remaining {
  display: flex;
  flex-direction: column;
  gap: 4px;
  padding: 16px 18px;
  font-size: 12px;
}

.remaining__label {
  color: var(--primary);
  font-weight: 600;
}

.remaining strong {
  font-size: 24px;
  font-weight: 700;
}

.request {
  padding: 16px 18px;
}

.request .section-title {
  display: flex;
  align-items: center;
  gap: 8px;
}

.slots {
  display: grid;
  gap: 8px;
  margin: 14px 0 0;
  padding: 0;
  list-style: none;
}

.slot {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 10px;
  min-height: 52px;
  padding: 8px 10px 8px 14px;
  border: 1px solid var(--border);
  border-radius: var(--radius);
}

.slot strong {
  display: block;
  font-size: 13px;
  font-weight: 600;
}

.slot small {
  display: block;
  margin-top: 2px;
  color: var(--muted);
  font-size: 11px;
}

.slot--locked {
  background: var(--surface-alt);
}

.slot--locked strong,
.slot--locked small {
  color: var(--faint);
}

.request__current {
  margin-top: 12px;
  color: var(--muted);
  font-size: 12px;
}

.my-work__hint {
  font-size: 12px;
}

@keyframes pulse {
  50% {
    opacity: 0.3;
  }
}

@media (max-width: 1099px) {
  .my-work__layout {
    grid-template-columns: 1fr;
  }

  .my-work__side {
    position: static;
  }
}

@media (max-width: 699px) {
  .my-work__layout {
    padding: 0 16px 24px;
  }

  .now__clock {
    font-size: 34px;
  }

  .slot .btn {
    height: 40px;
    padding: 0 14px;
  }
}
</style>
