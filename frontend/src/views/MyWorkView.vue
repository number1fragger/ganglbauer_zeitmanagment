<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import { api } from '@/api/client'
import { useAuthStore } from '@/stores/auth'
import { isWeekend, shiftWorkday, startOfDay } from '@/utils/calendar'
import { formatHours, formatWhen } from '@/utils/format'

/**
 * Arbeiter-Ansicht (fuers Handy gebaut): eigene Arbeiten, Zeiterfassung,
 * Restzeit und "Ich brauche neue Arbeit" (F4, F5, F7, F8).
 */
const auth = useAuthStore()
const router = useRouter()

const jobs = ref([])
const workload = ref(null)
const running = ref(null)
const myRequest = ref(null)
const expanded = ref(null)
const error = ref('')
const busy = ref(false)
const now = ref(Date.now())

const todayLabel = new Date().toLocaleDateString('de-AT', {
  weekday: 'long',
  day: '2-digit',
  month: '2-digit',
  year: 'numeric',
})

/** Offene Arbeiten und die heute erledigten. */
const visibleJobs = computed(() => {
  const today = startOfDay(new Date()).getTime()
  return jobs.value
    .filter((j) => j.status !== 'erledigt' || new Date(j.completedAt).getTime() >= today)
    .sort(
      (a, b) =>
        (a.status !== 'erledigt') - (b.status !== 'erledigt') ||
        (a.startsAt ?? '').localeCompare(b.startsAt ?? ''),
    )
})

async function load() {
  try {
    ;[jobs.value, workload.value, running.value, myRequest.value] = await Promise.all([
      api.get('/api/jobs', { assignee: 'me', includeDone: 1 }),
      api.get('/api/me/workload'),
      api.get('/api/time-entries/running'),
      api.get('/api/work-requests/mine'),
    ])
  } catch (e) {
    error.value = e.message
  }
}

// Laufende Zeit jede Sekunde aktualisieren.
const ticker = setInterval(() => (now.value = Date.now()), 1000)
onMounted(load)
onBeforeUnmount(() => clearInterval(ticker))

async function act(action) {
  error.value = ''
  busy.value = true
  try {
    await action()
    await load()
  } catch (e) {
    error.value = e.message
  } finally {
    busy.value = false
  }
}

const startWork = (job) => act(() => api.post('/api/time-entries/start', { jobId: job.id }))
const stopWork = () => act(() => api.post('/api/time-entries/stop'))
const extend = (job) => act(() => api.post(`/api/jobs/${job.id}/extend`, { minutes: 60 }))
const toggleDone = (job) =>
  act(() => api.post(`/api/jobs/${job.id}/complete`, { done: job.status !== 'erledigt' }))

function stateOf(job) {
  if (job.status === 'erledigt') return 'done'
  return job.overrun ? 'over' : 'open'
}

function timeLine(job) {
  if (job.status === 'erledigt')
    return `${formatHours(job.actualMinutes || job.plannedMinutes)} · erledigt`
  const soll = `Soll ${formatHours(job.plannedMinutes)}`
  return job.actualMinutes > 0 ? `${soll} · Ist ${formatHours(job.actualMinutes)}` : soll
}

const clock = computed(() => {
  if (!running.value) return ''
  const t = Math.max(0, Math.floor((now.value - new Date(running.value.startedAt)) / 1000))
  const pad = (n) => String(n).padStart(2, '0')
  return `${pad(Math.floor(t / 3600))}:${pad(Math.floor((t % 3600) / 60))}:${pad(t % 60)}`
})

// --- Ich brauche neue Arbeit (F7/F8): fruehestens der naechste Werktag ---------

function slotName(date) {
  const days = Math.round((startOfDay(date) - startOfDay(new Date())) / 86_400_000)
  if (days === 1) return 'Morgen'
  if (days === 2) return 'Übermorgen'
  return date.toLocaleDateString('de-AT', { weekday: 'long' })
}

function at(date, hour) {
  const d = new Date(date)
  d.setHours(hour, 0, 0, 0)
  return d
}

const slots = computed(() => {
  const first = shiftWorkday(startOfDay(new Date()), 1)
  const second = shiftWorkday(first, 1)
  const short = (d) =>
    d.toLocaleDateString('de-AT', { weekday: 'short', day: '2-digit', month: '2-digit' })
  const todayOpen = !isWeekend(new Date()) && new Date().getHours() < 13

  return [
    { label: `${slotName(first)} früh`, detail: `${short(first)} · ab 07:00`, value: at(first, 7) },
    {
      label: `${slotName(first)} Nachmittag`,
      detail: `${short(first)} · ab 13:00`,
      value: at(first, 13),
    },
    {
      label: `${slotName(second)} früh`,
      detail: `${short(second)} · ab 07:00`,
      value: at(second, 7),
    },
    // Zur Verdeutlichung der Regel: heute geht nicht mehr.
    {
      label: todayOpen ? 'Heute Nachmittag' : 'Heute',
      detail: 'Gesperrt – zu kurzfristig',
      value: null,
    },
  ]
})

const isRequested = (slot) =>
  slot.value &&
  myRequest.value &&
  new Date(myRequest.value.neededAt).getTime() === slot.value.getTime()

const requestSlot = (slot) =>
  act(() => api.post('/api/work-requests', { neededAt: slot.value.toISOString(), note: null }))
const withdraw = () =>
  act(() =>
    api.put(`/api/work-requests/${myRequest.value.id}/status`, { status: 'zurueckgezogen' }),
  )

function logout() {
  auth.logout()
  router.push('/login')
}

const roleClass = computed(() => `role role-${auth.role.toLowerCase().replace('role_', '')}`)
</script>

<template>
  <div class="phone">
    <header v-if="!auth.isForeman" class="top">
      <span class="avatar big">{{ auth.user?.initials }}</span>
      <div class="who">
        <strong>{{ auth.user?.fullName }}</strong>
        <span>Werkstatt · {{ todayLabel }}</span>
        <span :class="roleClass"><i></i>{{ auth.user?.roleLabel }}</span>
      </div>
      <div class="links">
        <RouterLink to="/einstellungen">Einstellungen</RouterLink>
        <button type="button" @click="logout">Abmelden</button>
      </div>
    </header>

    <main class="body">
      <p v-if="error" class="error">{{ error }}</p>

      <div v-if="running" class="running">
        <span class="pulse"></span>
        <span class="running__text">
          <strong>{{ running.job.title }}</strong>
          <span>läuft seit {{ clock }}</span>
        </span>
        <button type="button" class="stop" :disabled="busy" @click="stopWork">Stopp</button>
      </div>

      <h2>Meine Arbeiten heute</h2>

      <article
        v-for="job in visibleJobs"
        :key="job.id"
        class="job"
        :class="stateOf(job)"
        @click="expanded = expanded === job.id ? null : job.id"
      >
        <div class="job__main">
          <strong>{{ job.title }}</strong>
          <span>Kunde: {{ job.customer ?? '–' }}</span>
          <span class="job__time">{{ timeLine(job) }}</span>
        </div>

        <button
          type="button"
          class="state"
          :aria-label="job.status === 'erledigt' ? 'Wieder öffnen' : 'Als erledigt abhaken'"
          :disabled="busy"
          @click.stop="toggleDone(job)"
        >
          <template v-if="job.status === 'erledigt'">OK</template>
          <template v-else-if="job.overrun">!</template>
        </button>

        <div
          v-if="expanded === job.id && job.status !== 'erledigt'"
          class="job__actions"
          @click.stop
        >
          <button
            v-if="running?.job.id !== job.id"
            type="button"
            class="small"
            :disabled="busy"
            @click="startWork(job)"
          >
            Zeit starten
          </button>
          <button type="button" class="small secondary" :disabled="busy" @click="extend(job)">
            + 1 Stunde
          </button>
          <button type="button" class="small secondary" :disabled="busy" @click="toggleDone(job)">
            Erledigt
          </button>
        </div>
      </article>

      <p v-if="visibleJobs.length === 0" class="empty">Heute sind dir keine Arbeiten zugeteilt.</p>

      <section v-if="workload" class="rest">
        <span>Verbleibende Arbeit</span>
        <strong>{{ formatHours(workload.remainingMinutes, 'Stunden') }}</strong>
        <small>Voraussichtlich fertig: {{ formatWhen(workload.availableFrom) }}</small>
      </section>

      <h2 class="gap">Ich brauche neue Arbeit</h2>
      <p class="hint">Anfrage frühestens einen Tag im Voraus möglich</p>

      <div v-for="slot in slots" :key="slot.label" class="slot" :class="{ locked: !slot.value }">
        <span>
          <strong>{{ slot.label }}</strong>
          <small>{{ slot.detail }}</small>
        </span>
        <button v-if="!slot.value" type="button" class="small" disabled>gesperrt</button>
        <button
          v-else-if="isRequested(slot)"
          type="button"
          class="small requested"
          :disabled="busy"
          @click="withdraw"
        >
          angefragt ✓
        </button>
        <button v-else type="button" class="small" :disabled="busy" @click="requestSlot(slot)">
          anfragen
        </button>
      </div>

      <p v-if="myRequest?.neededAt" class="note">
        Deine Anfrage steht: {{ formatWhen(myRequest?.neededAt) }}. Nochmal tippen zieht sie zurück,
        eine andere Zeit ersetzt sie.
      </p>
    </main>
  </div>
</template>

<style scoped>
.phone {
  max-width: 420px;
  margin: 0 auto;
  min-height: 100vh;
  background: var(--bg);
}

.top {
  display: flex;
  gap: 12px;
  align-items: flex-start;
  padding: 28px 24px 14px;
  background: var(--surface);
  border-bottom: 1px solid var(--border);
}

.avatar.big {
  width: 40px;
  height: 40px;
  font-size: 0.8125rem;
}

.who {
  display: flex;
  flex-direction: column;
  flex: 1;
  min-width: 0;
}

.who strong {
  font-size: 1rem;
  font-weight: 700;
}

.who > span {
  font-size: 0.6875rem;
  color: var(--muted);
  margin-top: 2px;
}

.role {
  align-self: flex-start;
  display: inline-flex;
  align-items: center;
  gap: 6px;
  height: 20px;
  margin-top: 8px !important;
  padding: 0 10px;
  border-radius: 10px;
  font-size: 0.625rem !important;
  font-weight: 600;
}

.role i {
  width: 6px;
  height: 6px;
  border-radius: 50%;
  background: currentColor;
}

.role-user {
  background: var(--success-soft);
  color: var(--success) !important;
}

.role-foreman {
  background: var(--warning-soft);
  color: var(--warning-text) !important;
}

.role-admin {
  background: var(--primary-soft);
  color: var(--primary) !important;
}

.links {
  align-self: flex-end;
  display: flex;
  flex-direction: column;
  align-items: flex-end;
  gap: 4px;
}

.links a,
.links button {
  height: auto;
  padding: 0;
  background: none;
  color: var(--primary);
  font-size: 0.625rem;
  font-weight: 600;
  text-decoration: none;
}

.body {
  padding: 24px;
}

h2 {
  font-size: 0.8125rem;
  margin: 0 0 12px;
}

h2.gap {
  margin-top: 28px;
  margin-bottom: 0;
}

.hint {
  margin: 4px 0 20px;
  font-size: 0.6875rem;
  color: var(--muted);
}

/* ---------- Arbeitskarten ---------- */
.job {
  position: relative;
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
  padding: 14px 16px 14px 20px;
  margin-bottom: 12px;
  border-radius: 10px;
  background: var(--surface);
  border-left: 3px solid var(--primary);
  cursor: pointer;
}

.job.done {
  border-left-color: var(--faint);
}

.job.over {
  border-left-color: var(--danger);
}

.job__main {
  display: flex;
  flex-direction: column;
  gap: 3px;
  flex: 1;
  min-width: 0;
}

.job__main strong {
  font-size: 0.8125rem;
  font-weight: 600;
}

.job.done .job__main strong {
  color: var(--muted);
}

.job__main span {
  font-size: 0.6875rem;
  color: var(--muted);
}

.job__main .job__time {
  font-weight: 500;
  margin-top: 3px;
}

.job.over .job__time {
  color: var(--danger);
}

.state {
  width: 26px;
  height: 26px;
  padding: 0;
  border-radius: 50%;
  background: var(--button);
  color: #fff;
  font-size: 0.5625rem;
  font-weight: 700;
}

.job.done .state {
  background: var(--success);
}

.job.over .state {
  background: var(--danger);
  font-size: 0.75rem;
}

.job__actions {
  width: 100%;
  display: flex;
  gap: 8px;
  padding-top: 10px;
  border-top: 1px solid var(--grid);
}

.running {
  display: flex;
  align-items: center;
  gap: 12px;
  padding: 12px 16px;
  margin-bottom: 20px;
  border-radius: 10px;
  background: var(--surface);
  border-left: 3px solid var(--success);
}

.running__text {
  display: flex;
  flex-direction: column;
  flex: 1;
  font-size: 0.6875rem;
  color: var(--muted);
}

.running__text strong {
  font-size: 0.75rem;
  color: var(--text);
}

.stop {
  background: var(--danger);
  height: 30px;
}

.pulse {
  width: 10px;
  height: 10px;
  border-radius: 50%;
  background: var(--success);
  animation: pulse 1.6s ease-in-out infinite;
}

@keyframes pulse {
  50% {
    opacity: 0.25;
  }
}

.empty {
  font-size: 0.75rem;
  color: var(--muted);
}

/* ---------- Verbleibende Arbeit ---------- */
.rest {
  display: flex;
  flex-direction: column;
  margin-top: 24px;
  padding: 16px 20px;
  border-radius: 10px;
  background: var(--primary-soft);
}

.rest span {
  font-size: 0.6875rem;
  font-weight: 500;
  color: var(--primary);
}

.rest strong {
  font-size: 1.375rem;
  font-weight: 700;
  margin: 2px 0;
}

.rest small {
  font-size: 0.625rem;
  color: var(--muted);
}

/* ---------- Anfrage-Slots ---------- */
.slot {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  padding: 10px 16px 10px 20px;
  margin-bottom: 8px;
  border-radius: 10px;
  background: var(--surface);
}

.slot span {
  display: flex;
  flex-direction: column;
}

.slot strong {
  font-size: 0.75rem;
  font-weight: 600;
}

.slot small {
  font-size: 0.625rem;
  color: var(--muted);
}

.slot button {
  width: 80px;
  height: 26px;
}

.slot.locked {
  background: var(--button);
}

.slot.locked strong,
.slot.locked small {
  color: var(--faint);
}

.slot.locked button {
  background: #e5e7eb;
  color: var(--faint);
  opacity: 1;
}

.requested {
  background: var(--success);
}

.note {
  font-size: 0.6875rem;
  color: var(--muted);
  margin-top: 12px;
}
</style>
