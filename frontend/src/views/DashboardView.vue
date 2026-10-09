<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import { jobsApi, planningApi, requestsApi } from '@/api'
import { errorMessage } from '@/api/http'
import type { FollowUp, Job, Overview } from '@/api/types'
import AppIcon from '@/components/AppIcon.vue'
import FollowUpDialog from '@/components/FollowUpDialog.vue'
import JobDialog from '@/components/JobDialog.vue'
import PageHeader from '@/components/PageHeader.vue'
import PriorityChip from '@/components/PriorityChip.vue'
import StatusChip from '@/components/StatusChip.vue'
import { useNow } from '@/composables/useNow'
import { useAuthStore } from '@/stores/auth'
import { isDone, priorityWeight } from '@/utils/domain'
import {
  addDays,
  describeMoment,
  describeShort,
  formatClock,
  formatHours,
  formatTime,
  startOfDay,
} from '@/utils/time'

/**
 * Ueberblick fuer die Planung: wer arbeitet gerade woran, was steht heute
 * an, was braucht eine Entscheidung. Bewusst ohne grosse Kennzahl-Kacheln.
 */
const auth = useAuthStore()
const now = useNow(1000)

const overview = ref<Overview | null>(null)
const today = ref<Job[]>([])
const backlog = ref<Job[]>([])
const error = ref('')
const loading = ref(true)

async function load(): Promise<void> {
  try {
    const start = startOfDay(new Date())
    const [o, t, b] = await Promise.all([
      planningApi.overview(),
      jobsApi.inRange(start, addDays(start, 1)),
      jobsApi.board({ doneDays: 0 }),
    ])
    overview.value = o
    today.value = t
    backlog.value = b.filter((job) => !job.scheduled)
    error.value = ''
  } catch (e) {
    error.value = errorMessage(e)
  } finally {
    loading.value = false
  }
}

onMounted(load)
const refresh = window.setInterval(() => document.visibilityState === 'visible' && load(), 60_000)
onBeforeUnmount(() => window.clearInterval(refresh))

const greeting = computed(() => {
  const hour = now.value.getHours()
  const name = auth.user?.firstName ?? ''
  return `${hour < 11 ? 'Guten Morgen' : hour < 18 ? 'Guten Tag' : 'Guten Abend'}, ${name}`
})

const dateLabel = computed(() =>
  now.value.toLocaleDateString('de-AT', {
    weekday: 'long',
    day: 'numeric',
    month: 'long',
    year: 'numeric',
  }),
)

const workers = computed(() => overview.value?.workers ?? [])
const workerRefs = computed(() => workers.value.map((w) => w.user))
const runningCount = computed(() => workers.value.filter((w) => w.current).length)

const elapsed = (since: string) =>
  formatClock((now.value.getTime() - new Date(since).getTime()) / 1000)

const todaySorted = computed(() =>
  [...today.value].sort(
    (a, b) => (a.startsAt ?? '').localeCompare(b.startsAt ?? '') || a.title.localeCompare(b.title),
  ),
)

const backlogTop = computed(() =>
  [...backlog.value]
    .sort((a, b) => priorityWeight(b.priority) - priorityWeight(a.priority))
    .slice(0, 5),
)

const notices = computed(() => {
  const o = overview.value
  if (!o) return 0
  return o.followUps.length + o.conflicts.length + o.warnings.length + o.requests.length
})

// ---- Dialoge ---------------------------------------------------------------

const dialog = ref<{
  job: Job | null
  defaults?: { startsAt?: Date; assigneeId?: number | null }
  requestId?: number
} | null>(null)
const followUp = ref<FollowUp | null>(null)

async function openJob(id: number): Promise<void> {
  try {
    dialog.value = { job: await jobsApi.get(id) }
  } catch (e) {
    error.value = errorMessage(e)
  }
}

function assign(request: Overview['requests'][number]): void {
  dialog.value = {
    job: null,
    defaults: { startsAt: new Date(request.neededAt), assigneeId: request.user.id },
    requestId: request.id,
  }
}

async function closeAndReload(): Promise<void> {
  dialog.value = null
  followUp.value = null
  await load()
}

async function onSaved(): Promise<void> {
  const requestId = dialog.value?.requestId
  dialog.value = null
  if (requestId) await requestsApi.fulfil(requestId).catch(() => undefined)
  await load()
}
</script>

<template>
  <div class="dash">
    <PageHeader :title="greeting" :subtitle="dateLabel">
      <template #actions>
        <RouterLink class="btn btn--outline" :to="{ name: 'planning' }"
          ><AppIcon name="calendar" :size="15" /> Kalender</RouterLink
        >
        <button class="btn btn--primary" @click="dialog = { job: null }">
          <AppIcon name="plus" :size="16" /> Neue Arbeit
        </button>
      </template>
    </PageHeader>

    <p v-if="error" class="form-error dash__error" role="alert">
      {{ error }} <button class="link" @click="load">Erneut laden</button>
    </p>

    <p v-if="overview" class="summary">
      <span
        ><b>{{ runningCount }}</b> von {{ workers.length }} arbeiten gerade</span
      >
      <span
        ><b>{{ today.filter((j) => !isDone(j)).length }}</b> Termine heute offen</span
      >
      <RouterLink :to="{ name: 'tasks' }"
        ><b>{{ backlog.length }}</b> Aufgaben ohne Termin</RouterLink
      >
      <span :class="{ 'summary--alert': notices > 0 }"
        ><b>{{ notices }}</b> Hinweise</span
      >
    </p>

    <div class="dash__grid">
      <div class="dash__col">
        <section class="card panel">
          <header class="panel__head">
            <h2 class="section-title">Jetzt in der Werkstatt</h2>
          </header>
          <div v-if="loading" class="panel__body">
            <div v-for="n in 4" :key="n" class="skeleton dash__skeleton" />
          </div>
          <ul v-else class="people">
            <li v-for="w in workers" :key="w.user.id" class="person">
              <span class="avatar">{{ w.user.initials }}</span>
              <span class="person__main">
                <strong>{{ w.user.fullName }}</strong>
                <button v-if="w.current" class="person__job" @click="openJob(w.current.jobId)">
                  <span class="person__live" aria-hidden="true" />{{ w.current.title }}
                </button>
                <small v-else class="muted">keine laufende Arbeit</small>
              </span>
              <span v-if="w.current" class="person__clock tabular">{{
                elapsed(w.current.runningSince)
              }}</span>
              <span class="person__free">
                <small>frei ab</small>
                <b>{{ describeShort(new Date(w.availableFrom)) }}</b>
              </span>
            </li>
          </ul>
        </section>

        <section class="card panel">
          <header class="panel__head">
            <h2 class="section-title">Heute geplant</h2>
            <RouterLink class="link" :to="{ name: 'planning', query: { ansicht: 'tag' } }"
              >Tagesansicht</RouterLink
            >
          </header>
          <ul v-if="todaySorted.length" class="agenda">
            <li v-for="job in todaySorted" :key="job.id">
              <button class="agenda__row" @click="openJob(job.id)">
                <span class="agenda__time tabular">{{
                  job.startsAt ? formatTime(new Date(job.startsAt)) : ''
                }}</span>
                <span class="agenda__title" :class="{ 'agenda__title--done': isDone(job) }">
                  <strong>{{ job.title }}</strong>
                  <small
                    >{{ job.assignee?.fullName ?? 'Nicht zugeteilt'
                    }}<template v-if="job.customer"> · {{ job.customer }}</template></small
                  >
                </span>
                <StatusChip :job="job" />
              </button>
            </li>
          </ul>
          <p v-else-if="!loading" class="empty panel__empty">Für heute ist nichts eingeplant.</p>
        </section>
      </div>

      <div class="dash__col">
        <section class="card panel">
          <header class="panel__head">
            <h2 class="section-title">Hinweise</h2>
          </header>
          <ul v-if="overview && notices" class="notices">
            <li
              v-for="f in overview.followUps"
              :key="`f${f.jobId}`"
              class="notice"
              style="--tone: var(--primary)"
            >
              <AppIcon name="shuffle" :size="16" />
              <span>
                <strong
                  >{{ f.direction === 'earlier' ? 'Früher fertig' : 'Später fertig' }}:
                  {{ f.title }}</strong
                >
                <small
                  >{{ f.worker }} · {{ f.moves.length }} Folgetermin(e) können
                  {{ f.direction === 'earlier' ? 'vorgezogen' : 'verschoben' }} werden</small
                >
              </span>
              <button class="btn btn--small btn--outline" @click="followUp = f">Ansehen</button>
            </li>
            <li
              v-for="(c, i) in overview.conflicts"
              :key="`c${i}`"
              class="notice"
              style="--tone: var(--danger)"
            >
              <AppIcon name="layers" :size="16" />
              <span>
                <strong>Terminkonflikt bei {{ c.worker }}</strong>
                <small
                  >„{{ c.jobs[0]?.title }}“ und „{{ c.jobs[1]?.title }}“ ·
                  {{ describeMoment(new Date(c.from)) }}</small
                >
              </span>
              <button class="btn btn--small btn--outline" @click="openJob(c.jobs[1]!.id)">
                Lösen
              </button>
            </li>
            <li
              v-for="w in overview.warnings"
              :key="`w${w.jobId}`"
              class="notice"
              style="--tone: var(--warning)"
            >
              <AppIcon name="alert" :size="16" />
              <span>
                <strong>Zeit überschritten: {{ w.title }}</strong>
                <small
                  >{{ w.worker ?? 'Nicht zugeteilt' }} · {{ formatHours(w.overrunMinutes) }} über
                  Plan</small
                >
              </span>
              <button class="btn btn--small btn--outline" @click="openJob(w.jobId)">Öffnen</button>
            </li>
            <li
              v-for="r in overview.requests"
              :key="`r${r.id}`"
              class="notice"
              style="--tone: var(--success)"
            >
              <AppIcon name="hand" :size="16" />
              <span>
                <strong>{{ r.user.fullName }} braucht Arbeit</strong>
                <small>ab {{ describeMoment(new Date(r.neededAt)) }}</small>
              </span>
              <button class="btn btn--small btn--primary" @click="assign(r)">Zuteilen</button>
            </li>
          </ul>
          <p v-else-if="!loading" class="empty panel__empty">Alles im grünen Bereich.</p>
        </section>

        <section class="card panel">
          <header class="panel__head">
            <h2 class="section-title">Aufgaben ohne Termin</h2>
            <RouterLink class="link" :to="{ name: 'tasks' }">Alle anzeigen</RouterLink>
          </header>
          <ul v-if="backlogTop.length" class="agenda">
            <li v-for="job in backlogTop" :key="job.id">
              <button class="agenda__row" @click="openJob(job.id)">
                <span class="agenda__title">
                  <strong>{{ job.title }}</strong>
                  <small>{{ job.assignee?.fullName ?? 'Nicht zugeteilt' }}</small>
                </span>
                <PriorityChip :priority="job.priority" />
              </button>
            </li>
          </ul>
          <p v-else-if="!loading" class="empty panel__empty">Keine offenen Aufgaben ohne Termin.</p>
        </section>
      </div>
    </div>

    <JobDialog
      v-if="dialog"
      :job="dialog.job"
      :defaults="dialog.defaults"
      :workers="workerRefs"
      @close="dialog = null"
      @saved="onSaved"
      @deleted="closeAndReload"
      @changed="load"
    />
    <FollowUpDialog
      v-if="followUp"
      :follow-up="followUp"
      @close="followUp = null"
      @applied="closeAndReload"
    />
  </div>
</template>

<style scoped>
.dash__error {
  margin: 0 28px 12px;
}

.summary {
  display: flex;
  flex-wrap: wrap;
  gap: 8px 22px;
  margin: 0 28px 18px;
  color: var(--muted);
  font-size: 13px;
}

.summary b {
  color: var(--text);
  font-weight: 700;
}

.summary a {
  color: inherit;
  text-decoration: none;
}

.summary a:hover {
  color: var(--primary);
}

.summary--alert b {
  color: var(--danger);
}

.dash__grid {
  display: grid;
  grid-template-columns: minmax(0, 1.25fr) minmax(0, 1fr);
  gap: 20px;
  padding: 0 28px 32px;
}

.dash__col {
  display: flex;
  flex-direction: column;
  gap: 20px;
  min-width: 0;
}

.panel {
  overflow: hidden;
}

.panel__head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 14px 18px;
  border-bottom: 1px solid var(--border);
}

.panel__body {
  display: grid;
  gap: 8px;
  padding: 14px 18px;
}

.panel__empty {
  margin: 14px 18px;
}

.dash__skeleton {
  height: 44px;
}

.people,
.agenda,
.notices {
  margin: 0;
  padding: 0;
  list-style: none;
}

.person {
  display: flex;
  align-items: center;
  gap: 12px;
  padding: 12px 18px;
  border-bottom: 1px solid var(--grid);
}

.person:last-child,
.agenda li:last-child .agenda__row,
.notice:last-child {
  border-bottom: 0;
}

.person__main {
  display: flex;
  flex: 1;
  flex-direction: column;
  gap: 2px;
  min-width: 0;
}

.person__main strong {
  font-size: 13px;
}

.person__job {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  max-width: 100%;
  padding: 0;
  border: 0;
  background: none;
  color: var(--primary);
  font-size: 12px;
  font-weight: 500;
  text-align: left;
}

.person__job:hover {
  text-decoration: underline;
}

.person__live {
  flex: none;
  width: 7px;
  height: 7px;
  border-radius: 50%;
  background: var(--primary);
  animation: pulse 1.6s ease-in-out infinite;
}

.person__clock {
  color: var(--primary);
  font-size: 13px;
  font-weight: 700;
}

.person__free {
  display: flex;
  flex-direction: column;
  align-items: flex-end;
  min-width: 92px;
  font-size: 12px;
}

.person__free small {
  color: var(--faint);
  font-size: 10px;
}

.agenda__row {
  display: flex;
  align-items: center;
  gap: 12px;
  width: 100%;
  padding: 10px 18px;
  border: 0;
  border-bottom: 1px solid var(--grid);
  background: none;
  text-align: left;
}

.agenda__row:hover {
  background: var(--surface-alt);
}

.agenda__time {
  width: 42px;
  color: var(--muted);
  font-size: 12px;
  font-weight: 600;
}

.agenda__title {
  display: flex;
  flex: 1;
  flex-direction: column;
  min-width: 0;
}

.agenda__title strong {
  overflow: hidden;
  font-size: 13px;
  font-weight: 600;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.agenda__title small {
  overflow: hidden;
  color: var(--muted);
  font-size: 11px;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.agenda__title--done strong {
  color: var(--muted);
  text-decoration: line-through;
}

.notice {
  display: flex;
  align-items: center;
  gap: 12px;
  padding: 12px 18px;
  border-bottom: 1px solid var(--grid);
}

.notice > .icon {
  padding: 7px;
  border-radius: 9px;
  background: color-mix(in srgb, var(--tone) var(--tint), var(--surface));
  color: var(--tone);
  box-sizing: content-box;
}

.notice > span {
  display: flex;
  flex: 1;
  flex-direction: column;
  gap: 2px;
  min-width: 0;
}

.notice strong {
  font-size: 13px;
}

.notice small {
  color: var(--muted);
  font-size: 11px;
}

@keyframes pulse {
  50% {
    opacity: 0.3;
  }
}

@media (max-width: 1099px) {
  .dash__grid {
    grid-template-columns: 1fr;
  }
}

@media (max-width: 699px) {
  .summary,
  .dash__error {
    margin-right: 16px;
    margin-left: 16px;
  }

  .dash__grid {
    padding: 0 16px 24px;
  }

  .person {
    flex-wrap: wrap;
    padding: 12px 14px;
  }

  .person__free {
    min-width: 0;
  }

  .notice {
    flex-wrap: wrap;
    padding: 12px 14px;
  }

  .notice .btn {
    height: 36px;
    margin-left: 44px;
  }
}
</style>
