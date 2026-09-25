<script setup>
import { computed, onMounted, ref, watch } from 'vue'
import { api } from '@/api/client'
import { addDays, dayKey, isoWeek, startOfWeek } from '@/utils/calendar'
import { decimalHours, formatDate, formatHours, formatWeekdayTime } from '@/utils/format'

/** Auswertung: geplante gegenueber tatsaechlich benoetigter Zeit (A1). */
const period = ref('week')
const report = ref(null)
const previous = ref(null)
const overview = ref(null)
const error = ref('')

/** Zeitraum und der gleich lange Zeitraum davor (fuer den Vergleich). */
const ranges = computed(() => {
  const today = new Date()
  let from = addDays(today, -29)
  let to = today

  if (period.value === 'week' || period.value === 'lastWeek') {
    from = addDays(startOfWeek(today), period.value === 'week' ? 0 : -7)
    to = addDays(from, 6)
  } else if (period.value === 'month') {
    from = new Date(today.getFullYear(), today.getMonth(), 1)
    to = new Date(today.getFullYear(), today.getMonth() + 1, 0)
  }

  const length = Math.round((to - from) / 86_400_000) + 1
  return { from, to, prevFrom: addDays(from, -length), prevTo: addDays(from, -1) }
})

const week = isoWeek(new Date())
const periodOptions = [
  { value: 'week', label: `Zeitraum: KW ${week}` },
  { value: 'lastWeek', label: `Zeitraum: KW ${week > 1 ? week - 1 : 52}` },
  { value: 'month', label: 'Zeitraum: dieser Monat' },
  { value: 'days30', label: 'Zeitraum: letzte 30 Tage' },
]

async function load() {
  error.value = ''
  const r = ranges.value
  try {
    ;[report.value, previous.value, overview.value] = await Promise.all([
      api.get('/api/reports/soll-ist', { from: dayKey(r.from), to: dayKey(r.to) }),
      api.get('/api/reports/soll-ist', { from: dayKey(r.prevFrom), to: dayKey(r.prevTo) }),
      api.get('/api/overview'),
    ])
  } catch (e) {
    error.value = e.message
  }
}

onMounted(load)
watch(period, load)

function totals(r) {
  const jobs = r?.jobs ?? []
  const planned = jobs.reduce((s, j) => s + j.plannedMinutes, 0)
  const actual = jobs.reduce((s, j) => s + j.actualMinutes, 0)
  // Genauigkeit: wie nah liegt jede Arbeit an ihrem Plan (100 % = genau getroffen).
  const deviation = jobs.reduce((s, j) => s + Math.abs(j.actualMinutes - j.plannedMinutes), 0)

  return {
    jobs: jobs.length,
    workers: r?.perWorker.length ?? 0,
    planned,
    actual,
    accuracy: planned > 0 ? Math.max(0, 100 - (deviation / planned) * 100) : null,
    overruns: jobs.filter((j) => j.actualMinutes > j.plannedMinutes).length,
  }
}

const now = computed(() => totals(report.value))
const before = computed(() => totals(previous.value))

const pct = (value) => `${value.toFixed(1).replace('.', ',')} %`
const signed = (minutes) =>
  `${minutes > 0 ? '+' : minutes < 0 ? '−' : '±'}${formatHours(Math.abs(minutes))}`

const accuracyDelta = computed(() =>
  now.value.accuracy !== null && before.value.accuracy !== null
    ? now.value.accuracy - before.value.accuracy
    : null,
)

const maxBar = computed(() =>
  Math.max(
    60,
    ...(report.value?.perWorker.flatMap((w) => [w.plannedMinutes, w.actualMinutes]) ?? []),
  ),
)

const deviations = computed(() =>
  [...(report.value?.jobs ?? [])]
    .filter((j) => j.diffMinutes !== 0)
    .sort((a, b) => Math.abs(b.diffMinutes) - Math.abs(a.diffMinutes))
    .slice(0, 4),
)

/** Auslastung: Restarbeit im Verhaeltnis zu einer Arbeitswoche. */
const load7 = computed(() =>
  (overview.value?.workers ?? []).map((w) => {
    const share = Math.min(1, w.remainingMinutes / Math.max(1, w.user.dailyMinutes * 5))
    return {
      ...w,
      share,
      color: share < 0.2 ? 'var(--danger)' : share < 0.5 ? 'var(--warning)' : 'var(--success)',
    }
  }),
)

function shortName(full) {
  const [first, ...rest] = full.split(' ')
  return rest.length ? `${first[0]}. ${rest.join(' ')}` : full
}

function exportCsv() {
  const header = [
    'Arbeit',
    'Kunde',
    'Arbeiter',
    'Soll (h)',
    'Ist (h)',
    'Differenz (h)',
    'Erledigt am',
  ]
  const rows = report.value.jobs.map((job) => [
    job.title,
    job.customer ?? '',
    job.worker,
    decimalHours(job.plannedMinutes),
    decimalHours(job.actualMinutes),
    decimalHours(job.diffMinutes),
    job.completedAt ? formatDate(job.completedAt) : '',
  ])
  const csv = [header, ...rows]
    .map((row) => row.map((cell) => `"${String(cell).replace(/"/g, '""')}"`).join(';'))
    .join('\n')

  const link = document.createElement('a')
  link.href = URL.createObjectURL(new Blob([`\ufeff${csv}`], { type: 'text/csv;charset=utf-8' }))
  link.download = `soll-ist_${report.value.from}_${report.value.to}.csv`
  link.click()
  URL.revokeObjectURL(link.href)
}
</script>

<template>
  <div class="page-head">
    <div>
      <h1>Auswertung</h1>
      <p>Geplante gegenüber tatsächlich benötigter Zeit</p>
    </div>
    <div class="head-actions">
      <button type="button" class="secondary" :disabled="!report?.jobs.length" @click="exportCsv">
        CSV exportieren
      </button>
      <select v-model="period" class="period" aria-label="Zeitraum">
        <option v-for="o in periodOptions" :key="o.value" :value="o.value">{{ o.label }}</option>
      </select>
    </div>
  </div>

  <div class="page-body report">
    <p v-if="error" class="error">{{ error }}</p>

    <section class="kpis">
      <div class="kpi">
        <span>Geplante Zeit gesamt</span>
        <strong>{{ formatHours(now.planned) }}</strong>
        <small>{{ now.workers }} Arbeiter · {{ now.jobs }} Arbeiten</small>
      </div>
      <div class="kpi">
        <span>Tatsächliche Zeit</span>
        <strong :class="now.actual > now.planned ? 'red' : 'green'">{{
          formatHours(now.actual)
        }}</strong>
        <small>{{ signed(now.actual - now.planned) }} gegenüber Plan</small>
      </div>
      <div class="kpi">
        <span>Planungsgenauigkeit</span>
        <strong class="green">{{ now.accuracy === null ? '–' : pct(now.accuracy) }}</strong>
        <small v-if="accuracyDelta !== null">
          {{ accuracyDelta >= 0 ? '+' : '−' }}{{ pct(Math.abs(accuracyDelta)) }} zum Zeitraum davor
        </small>
        <small v-else>kein Vergleichszeitraum</small>
      </div>
      <div class="kpi">
        <span>Zeitüberschreitungen</span>
        <strong class="orange">{{ now.overruns }}</strong>
        <small>von {{ now.jobs }} Arbeiten</small>
      </div>
    </section>

    <section class="middle">
      <div class="panel bars">
        <h2>Soll/Ist je Arbeiter</h2>
        <p class="hint">blau = geplant · orange = tatsächlich (grün, wenn im Plan)</p>

        <div v-for="w in report?.perWorker ?? []" :key="w.worker" class="bar-group">
          <strong>{{ w.worker }}</strong>
          <div class="bar-line">
            <span
              class="bar"
              :style="{
                width: `${(w.plannedMinutes / maxBar) * 75}%`,
                background: 'var(--primary)',
              }"
            ></span>
            <small>{{ formatHours(w.plannedMinutes) }}</small>
          </div>
          <div class="bar-line">
            <span
              class="bar"
              :style="{
                width: `${(w.actualMinutes / maxBar) * 75}%`,
                background:
                  w.actualMinutes > w.plannedMinutes ? 'var(--warning)' : 'var(--success)',
              }"
            ></span>
            <small :class="w.actualMinutes > w.plannedMinutes ? 'red' : 'green'">
              {{ formatHours(w.actualMinutes) }}
            </small>
          </div>
        </div>
        <p v-if="!report?.perWorker.length" class="hint">
          In diesem Zeitraum wurde noch keine Arbeit abgeschlossen.
        </p>
      </div>

      <div class="panel">
        <h2>Größte Abweichungen</h2>
        <div v-for="d in deviations" :key="d.jobId" class="dev">
          <span>
            <strong>{{ d.title }}</strong>
            <small>{{ shortName(d.worker) }}</small>
          </span>
          <b :class="d.diffMinutes > 0 ? 'red' : 'green'">{{ signed(d.diffMinutes) }}</b>
        </div>
        <p v-if="!deviations.length" class="hint">Keine Abweichungen.</p>
      </div>
    </section>

    <section class="panel">
      <h2>Auslastung – wann wird wieder Arbeit gebraucht?</h2>
      <div v-for="w in load7" :key="w.user.id" class="cap">
        <span class="cap__name">{{ w.user.fullName }}</span>
        <span class="cap__bar"
          ><span :style="{ width: `${Math.max(2, w.share * 100)}%`, background: w.color }"></span
        ></span>
        <span class="cap__when">frei ab {{ formatWeekdayTime(w.availableFrom, true) }}</span>
      </div>
    </section>
  </div>
</template>

<style scoped>
.head-actions {
  display: flex;
  gap: 12px;
}

.period {
  width: 190px;
  height: 36px;
  background: var(--button);
  font-weight: 500;
  font-size: 0.75rem;
}

.report {
  display: flex;
  flex-direction: column;
  gap: 24px;
}

.kpis {
  display: grid;
  grid-template-columns: repeat(4, 1fr);
  gap: 16px;
}

.kpi,
.panel {
  background: var(--surface);
  border-radius: 10px;
  padding: 18px 20px;
}

.kpi {
  display: flex;
  flex-direction: column;
  gap: 6px;
}

.kpi span {
  font-size: 0.6875rem;
  font-weight: 500;
  color: var(--muted);
}

.kpi strong {
  font-size: 1.5rem;
  font-weight: 700;
}

.kpi small {
  font-size: 0.625rem;
  color: var(--muted);
}

.red {
  color: var(--danger);
}

.green {
  color: var(--success);
}

.orange {
  color: var(--warning);
}

.middle {
  display: grid;
  grid-template-columns: 2fr 1fr;
  gap: 24px;
}

.panel h2 {
  font-size: 0.8125rem;
  margin: 0;
}

.hint {
  margin: 4px 0 16px;
  font-size: 0.6875rem;
  color: var(--muted);
}

.bar-group {
  margin-bottom: 22px;
}

.bar-group strong {
  font-size: 0.75rem;
  font-weight: 600;
}

.bar-line {
  display: flex;
  align-items: center;
  gap: 6px;
  margin-top: 6px;
}

.bar {
  height: 14px;
  border-radius: 4px;
  min-width: 4px;
}

.bar-line small {
  font-size: 0.625rem;
  font-weight: 500;
  color: var(--muted);
}

.bar-line small.red {
  color: var(--danger);
}

.bar-line small.green {
  color: var(--success);
}

.dev {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  margin-top: 14px;
  padding: 12px 16px;
  border-radius: 8px;
  background: var(--surface-muted);
}

.dev span {
  display: flex;
  flex-direction: column;
  min-width: 0;
}

.dev strong {
  font-size: 0.75rem;
  font-weight: 600;
}

.dev small {
  font-size: 0.625rem;
  color: var(--muted);
}

.dev b {
  font-size: 0.875rem;
}

.cap {
  display: grid;
  grid-template-columns: 140px 1fr 110px;
  align-items: center;
  gap: 16px;
  margin-top: 14px;
}

.cap__name {
  font-size: 0.6875rem;
  font-weight: 500;
}

.cap__bar {
  height: 10px;
  border-radius: 5px;
  background: var(--grid);
  overflow: hidden;
}

.cap__bar span {
  display: block;
  height: 100%;
  border-radius: 5px;
}

.cap__when {
  font-size: 0.625rem;
  font-weight: 500;
  color: var(--muted);
}

@media (max-width: 900px) {
  .kpis {
    grid-template-columns: 1fr 1fr;
  }

  .middle {
    grid-template-columns: 1fr;
  }
}
</style>
