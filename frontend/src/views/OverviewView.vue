<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { RouterLink } from 'vue-router'
import { errorMessage } from '@/api/client'
import { useWorkshopStore } from '@/stores/workshop'
import { formatDuration, formatWhen, priorityColor, priorityLabel } from '@/utils/format'

/**
 * F9 – die Startseite: Auf einen Blick sehen, wer wie lange
 * ausgelastet ist und wann wieder eine neue Arbeit gebraucht wird.
 */
const workshop = useWorkshopStore()
const error = ref('')
const loading = ref(true)

const workers = computed(() => workshop.overview?.workers ?? [])
const totals = computed(() => workshop.overview?.totals)

/** Laengste Restzeit als Bezug fuer die Balkenbreite. */
const maxRemaining = computed(() =>
  Math.max(60, ...workers.value.map((worker) => worker.remainingMinutes)),
)

onMounted(async () => {
  try {
    await Promise.all([workshop.loadOverview(), workshop.loadJobs()])
  } catch (e) {
    error.value = errorMessage(e)
  } finally {
    loading.value = false
  }
})
</script>

<template>
  <div>
    <h1>Übersicht</h1>
    <p class="muted">Wer ist wie lange ausgelastet und wann wird wieder Arbeit gebraucht.</p>

    <p v-if="error" class="error">{{ error }}</p>

    <section v-if="totals" class="tiles grid">
      <div class="card tile">
        <span class="muted">Arbeiter</span>
        <strong class="mono">{{ totals.workers }}</strong>
      </div>
      <div class="card tile">
        <span class="muted">Offene Arbeiten</span>
        <strong class="mono">{{ totals.openJobs }}</strong>
      </div>
      <div class="card tile">
        <span class="muted">Geplante Restzeit</span>
        <strong class="mono">{{ formatDuration(totals.remainingMinutes) }}</strong>
      </div>
      <div class="card tile">
        <span class="muted">Offene Anforderungen</span>
        <strong class="mono" :class="{ attention: totals.openRequests > 0 }">
          {{ totals.openRequests }}
        </strong>
      </div>
    </section>

    <p v-if="loading" class="muted">Wird geladen …</p>

    <section v-for="worker in workers" :key="worker.user.id" class="card worker">
      <header class="worker__head">
        <div>
          <h2>{{ worker.user.fullName }}</h2>
          <span class="muted">
            {{ worker.openJobs }} offene Arbeiten ·
            {{ formatDuration(worker.remainingMinutes) }} Restzeit
          </span>
        </div>

        <div class="worker__free">
          <span class="muted">wieder frei</span>
          <strong :class="{ soon: worker.availableToday }">
            {{ formatWhen(worker.availableFrom) }}
          </strong>
        </div>
      </header>

      <div class="bar" :aria-label="`Auslastung ${formatDuration(worker.remainingMinutes)}`">
        <span
          :style="{ width: `${(worker.remainingMinutes / maxRemaining) * 100}%` }"
          :class="{ overrun: worker.overrunJobs > 0 }"
        ></span>
      </div>

      <div class="worker__jobs">
        <div v-if="worker.currentJob" class="job">
          <span class="tag tag--now">läuft</span>
          <span
            class="dot"
            :style="{ background: priorityColor(worker.currentJob.priority) }"
          ></span>
          <span class="job__title">{{ worker.currentJob.title }}</span>
          <span class="muted">{{ worker.currentJob.customer ?? '–' }}</span>
          <span class="mono muted">
            {{ formatDuration(worker.currentJob.actualMinutes) }} /
            {{ formatDuration(worker.currentJob.plannedMinutes) }}
          </span>
          <span v-if="worker.currentJob.overrun" class="tag tag--warn">überschritten</span>
        </div>

        <div v-else-if="worker.nextJob" class="job">
          <span class="tag">als Nächstes</span>
          <span class="dot" :style="{ background: priorityColor(worker.nextJob.priority) }"></span>
          <span class="job__title">{{ worker.nextJob.title }}</span>
          <span class="muted">{{ priorityLabel(worker.nextJob.priority) }}</span>
          <span class="mono muted">{{ formatDuration(worker.nextJob.remainingMinutes) }}</span>
        </div>

        <div v-else class="job muted">Keine offene Arbeit zugeteilt.</div>
      </div>

      <p v-if="worker.workRequest" class="request">
        Braucht neue Arbeit ab <strong>{{ formatWhen(worker.workRequest.neededAt) }}</strong>
        <span v-if="worker.workRequest.note"> – {{ worker.workRequest.note }}</span>
      </p>

      <p v-if="worker.overrunJobs > 0" class="overrun-hint">
        {{ worker.overrunJobs }} Arbeit(en) über der geplanten Zeit.
      </p>
    </section>

    <p v-if="!loading && workers.length === 0" class="card muted">
      Noch keine Arbeiter angelegt. Unter
      <RouterLink to="/einstellungen">Einstellungen</RouterLink> siehst du dein Konto.
    </p>
  </div>
</template>

<style scoped>
section {
  margin-bottom: 1rem;
}

.tiles {
  grid-template-columns: repeat(auto-fit, minmax(170px, 1fr));
}

.tile {
  display: flex;
  flex-direction: column;
  gap: 0.3rem;
}

.tile strong {
  font-size: 1.6rem;
  font-weight: 700;
}

.attention {
  color: var(--warning);
}

.worker__head {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 1rem;
  margin-bottom: 0.75rem;
}

.worker__head h2 {
  margin-bottom: 0.15rem;
}

.worker__head span {
  font-size: 0.8125rem;
}

.worker__free {
  text-align: right;
  display: flex;
  flex-direction: column;
  white-space: nowrap;
}

.worker__free strong {
  font-size: 1.05rem;
}

.worker__free .soon {
  color: var(--success);
}

.bar {
  background: var(--bg);
  border-radius: 999px;
  height: 8px;
  overflow: hidden;
  margin-bottom: 0.75rem;
}

.bar span {
  display: block;
  height: 100%;
  background: var(--primary);
  border-radius: 999px;
}

.bar span.overrun {
  background: var(--danger);
}

.job {
  display: flex;
  align-items: center;
  gap: 0.5rem;
  flex-wrap: wrap;
  font-size: 0.875rem;
}

.job__title {
  font-weight: 600;
}

.tag {
  font-size: 0.6875rem;
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: 0.04em;
  padding: 0.12rem 0.45rem;
  border-radius: 4px;
  background: var(--bg);
  color: var(--muted);
}

.tag--now {
  background: color-mix(in srgb, var(--success) 15%, transparent);
  color: var(--success);
}

.tag--warn {
  background: color-mix(in srgb, var(--danger) 15%, transparent);
  color: var(--danger);
}

.request {
  margin: 0.75rem 0 0;
  font-size: 0.875rem;
  background: var(--primary-soft);
  color: var(--primary);
  padding: 0.5rem 0.7rem;
  border-radius: 8px;
}

.overrun-hint {
  margin: 0.5rem 0 0;
  font-size: 0.8125rem;
  color: var(--danger);
}

@media (max-width: 720px) {
  .worker__head {
    flex-direction: column;
  }

  .worker__free {
    text-align: left;
  }
}
</style>
