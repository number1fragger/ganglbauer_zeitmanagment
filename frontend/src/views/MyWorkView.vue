<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { authApi, jobsApi, requestsApi } from '@/api'
import { errorMessage } from '@/api/http'
import type { Job, WorkerStatus, WorkRequest } from '@/api/types'
import { useNow } from '@/composables/useNow'
import { useAuthStore } from '@/stores/auth'
import { isDone, roleOf } from '@/utils/domain'
import {
  describeMoment,
  formatDayMonth,
  formatHours,
  isWeekend,
  nextWorkday,
  relativeDay,
  startOfDay,
  weekdayName,
} from '@/utils/time'

const auth = useAuthStore()
const now = useNow(30_000)

const jobs = ref<Job[]>([])
const status = ref<WorkerStatus | null>(null)
const request = ref<WorkRequest | null>(null)
const error = ref('')
const busyId = ref<number | string | null>(null)

const today = computed(() => {
  const date = now.value.toLocaleDateString('de-AT', { weekday: 'long', day: '2-digit', month: '2-digit', year: 'numeric' })
  return `Werkstatt · ${date}`
})

async function load(): Promise<void> {
  try {
    ;[jobs.value, status.value, request.value] = await Promise.all([
      jobsApi.mine(),
      authApi.status(),
      requestsApi.mine(),
    ])
  } catch (e) {
    error.value = errorMessage(e)
  }
}

onMounted(load)

/** Fuehrt eine Aktion aus, zeigt Fehler an und laedt danach neu. */
async function act(key: number | string, action: () => Promise<unknown>): Promise<void> {
  error.value = ''
  busyId.value = key
  try {
    await action()
    await load()
  } catch (e) {
    error.value = errorMessage(e)
  } finally {
    busyId.value = null
  }
}

// ---- Arbeiten --------------------------------------------------------------

function stateOf(job: Job): 'done' | 'overrun' | 'open' {
  return isDone(job) ? 'done' : job.overrun ? 'overrun' : 'open'
}

const accentOf = (job: Job) =>
  ({ done: 'var(--faint)', overrun: 'var(--danger)', open: 'var(--primary)' })[stateOf(job)]

function effortOf(job: Job): string {
  if (isDone(job)) return `${formatHours(job.actualMinutes || job.plannedMinutes)} · erledigt`
  const soll = `Soll ${formatHours(job.plannedMinutes)}`
  return job.actualMinutes > 0 ? `${soll} · Ist ${formatHours(job.actualMinutes)}` : soll
}

const toggleDone = (job: Job) =>
  act(job.id, () => (isDone(job) ? jobsApi.reopen(job.id) : jobsApi.complete(job.id)))
const toggleTimer = (job: Job) => act(job.id, () => (job.running ? jobsApi.stopTimer() : jobsApi.start(job.id)))
const addHour = (job: Job) => act(job.id, () => jobsApi.extend(job.id, 60))

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
  const at = (day: Date, hour: number) => new Date(day.getFullYear(), day.getMonth(), day.getDate(), hour)

  const options = [at(first, 7), at(first, 13), at(second, 7)].map((date) => ({
    key: date.toISOString(),
    label: slotLabel(date),
    detail: `${formatDayMonth(date)} · ab ${date.getHours() === 7 ? '07:00' : '13:00'}`,
    at: date,
    locked: false,
  }))

  if (!isWeekend(now.value) && now.value.getHours() < 17) {
    const afternoon = at(startOfDay(now.value), 13)
    options.push({ key: 'today', label: slotLabel(afternoon), detail: 'Gesperrt – zu kurzfristig', at: afternoon, locked: true })
  }

  return options
})

const isRequested = (slot: Slot) => request.value !== null && new Date(request.value.neededAt).getTime() === slot.at.getTime()

const requestWork = (slot: Slot) =>
  act(slot.key, async () => {
    request.value = await requestsApi.create(slot.at)
  })

const withdraw = () => request.value && act('withdraw', () => requestsApi.withdraw(request.value!.id))

const finishText = computed(() =>
  status.value ? `Voraussichtlich fertig: ${describeMoment(new Date(status.value.availableFrom))}` : '',
)

</script>

<template>
  <div class="my-work">
    <header class="my-work__header">
      <span v-if="auth.user" class="avatar my-work__avatar">{{ auth.user.initials }}</span>
      <div class="my-work__who">
        <h1>{{ auth.user?.fullName }}</h1>
        <p class="muted">{{ today }}</p>
        <div class="my-work__meta">
          <span
            v-if="auth.user"
            class="role-pill"
            :style="{ '--role': roleOf(auth.user.role).color }"
          >
            <span class="dot" :style="{ '--dot': 'var(--role)' }" />{{ roleOf(auth.user.role).label }}
          </span>
          <RouterLink v-if="auth.isPlanner" class="link" :to="{ name: 'planning' }">Zur Planung</RouterLink>
          <button class="link my-work__logout" @click="auth.logout()">Abmelden</button>
        </div>
      </div>
    </header>

    <main class="my-work__main">
      <p v-if="error" class="form-error" role="alert">{{ error }}</p>

      <section>
        <h2 class="section-title">Meine Arbeiten heute</h2>
        <ul v-if="jobs.length" class="my-work__jobs">
          <li v-for="job in jobs" :key="job.id" class="card accent job" :style="{ '--accent': accentOf(job) }">
            <div class="job__text">
              <strong :class="{ muted: isDone(job) }">{{ job.title }}</strong>
              <span v-if="job.customer" class="muted">Kunde: {{ job.customer }}</span>
              <span class="job__effort" :class="{ 'job__effort--over': job.overrun && !isDone(job) }">
                {{ effortOf(job) }}
              </span>

              <span v-if="!isDone(job)" class="job__actions">
                <button class="job__action" :disabled="busyId === job.id" @click="toggleTimer(job)">
                  {{ job.running ? '■ Stoppen' : '▶ Starten' }}
                </button>
                <button class="job__action" :disabled="busyId === job.id" @click="addHour(job)">+ 1 Stunde</button>
                <span v-if="job.running" class="job__running">läuft</span>
              </span>
            </div>

            <button
              class="job__check"
              :class="`job__check--${stateOf(job)}`"
              :disabled="busyId === job.id"
              :aria-label="isDone(job) ? `${job.title} wieder öffnen` : `${job.title} als erledigt abhaken`"
              :title="isDone(job) ? 'Wieder öffnen' : 'Als erledigt abhaken'"
              @click="toggleDone(job)"
            >
              {{ stateOf(job) === 'done' ? 'OK' : stateOf(job) === 'overrun' ? '!' : '' }}
            </button>
          </li>
        </ul>
        <p v-else class="empty my-work__empty">Für heute ist dir keine Arbeit zugeteilt.</p>
      </section>

      <section v-if="status" class="remaining">
        <span class="remaining__label">Verbleibende Arbeit</span>
        <strong>{{ formatHours(status.remainingMinutes).replace(' h', ' Stunden') }}</strong>
        <span class="muted">{{ finishText }}</span>
      </section>

      <section>
        <h2 class="section-title">Ich brauche neue Arbeit</h2>
        <p class="section-sub">Anfrage frühestens einen Tag im Voraus möglich</p>

        <ul class="slots">
          <li v-for="slot in slots" :key="slot.key" class="slot" :class="{ 'slot--locked': slot.locked }">
            <span>
              <strong>{{ slot.label }}</strong>
              <small>{{ slot.detail }}</small>
            </span>
            <span v-if="slot.locked" class="slot__button slot__button--locked">gesperrt</span>
            <span v-else-if="isRequested(slot)" class="slot__button slot__button--done">angefragt</span>
            <button
              v-else
              class="btn btn--primary slot__button"
              :disabled="busyId === slot.key"
              @click="requestWork(slot)"
            >
              anfragen
            </button>
          </li>
        </ul>

        <p v-if="request" class="my-work__request">
          Angefragt: {{ describeMoment(new Date(request.neededAt)) }}.
          <button class="link" :disabled="busyId === 'withdraw'" @click="withdraw">Anfrage zurückziehen</button>
        </p>
      </section>
    </main>
  </div>
</template>

<style scoped>
.my-work {
  max-width: 460px;
  min-height: 100vh;
  margin: 0 auto;
  background: var(--bg);
}

.my-work__header {
  display: flex;
  gap: 12px;
  padding: 28px 24px 4px;
  border-bottom: 1px solid var(--border);
  background: var(--surface);
}

.my-work__avatar {
  width: 40px;
  height: 40px;
  font-size: 13px;
}

.my-work__who {
  flex: 1;
}

.my-work__who h1 {
  font-size: 16px;
  font-weight: 700;
}

.my-work__who p {
  margin-top: 2px;
  font-size: 11px;
}

.my-work__meta {
  display: flex;
  align-items: center;
  gap: 14px;
  margin: 6px 0 4px;
}

.my-work__logout {
  margin-left: auto;
  font-size: 10px;
  font-weight: 600;
}

.role-pill {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  height: 20px;
  padding: 0 10px;
  border-radius: 10px;
  background: color-mix(in srgb, var(--role) 12%, white);
  color: var(--role);
  font-size: 10px;
  font-weight: 600;
}

.role-pill .dot {
  width: 6px;
  height: 6px;
}

.my-work__main {
  display: grid;
  gap: 28px;
  padding: 24px 24px 40px;
}

.my-work__jobs,
.slots {
  display: grid;
  gap: 12px;
  margin: 14px 0 0;
  padding: 0;
  list-style: none;
}

.my-work__empty {
  margin-top: 14px;
}

.job {
  display: flex;
  align-items: flex-start;
  gap: 12px;
  min-height: 80px;
  padding: 14px 18px 14px 20px;
}

.job__text {
  display: flex;
  flex: 1;
  flex-direction: column;
  gap: 5px;
  min-width: 0;
  font-size: 11px;
}

.job__text strong {
  font-size: 13px;
  font-weight: 600;
}

.job__effort {
  color: var(--muted);
  font-weight: 500;
}

.job__effort--over {
  color: var(--danger);
}

.job__actions {
  display: flex;
  align-items: center;
  gap: 8px;
  margin-top: 6px;
}

.job__action {
  height: 24px;
  padding: 0 10px;
  border: 0;
  border-radius: var(--radius-sm);
  background: var(--surface-muted);
  font-size: 10px;
  font-weight: 600;
}

.job__running {
  padding: 2px 8px;
  border-radius: 9px;
  background: var(--primary);
  color: #fff;
  font-size: 9px;
  font-weight: 700;
}

.job__check {
  display: grid;
  flex: none;
  place-items: center;
  width: 26px;
  height: 26px;
  border: 0;
  border-radius: 50%;
  background: var(--surface-muted);
  color: #fff;
  font-size: 9px;
  font-weight: 700;
}

.job__check--done {
  background: var(--success);
}

.job__check--overrun {
  background: var(--danger);
  font-size: 12px;
}

.job__check--open:hover {
  box-shadow: inset 0 0 0 2px var(--success);
}

.remaining {
  display: flex;
  flex-direction: column;
  gap: 4px;
  padding: 16px 20px;
  border-radius: var(--radius-lg);
  background: var(--primary-soft);
  font-size: 10px;
}

.remaining__label {
  color: var(--primary);
  font-size: 11px;
  font-weight: 500;
}

.remaining strong {
  font-size: 22px;
  font-weight: 700;
}

.slot {
  display: flex;
  align-items: center;
  justify-content: space-between;
  min-height: 48px;
  padding: 8px 16px 8px 20px;
  border-radius: var(--radius-lg);
  background: var(--surface);
}

.slot strong {
  display: block;
  font-size: 12px;
  font-weight: 600;
}

.slot small {
  display: block;
  margin-top: 2px;
  color: var(--muted);
  font-size: 10px;
}

.slot--locked {
  background: var(--surface-muted);
}

.slot--locked strong,
.slot--locked small {
  color: var(--faint);
}

.slot__button {
  display: inline-grid;
  place-items: center;
  width: 80px;
  height: 26px;
  padding: 0;
  border-radius: var(--radius-sm);
  font-size: 10px;
  font-weight: 600;
}

.slot__button--locked {
  background: #e5e7eb;
  color: var(--faint);
}

.slot__button--done {
  background: var(--success-soft);
  color: var(--success);
}

.my-work__request {
  margin-top: 12px;
  color: var(--muted);
  font-size: 11px;
}
</style>
