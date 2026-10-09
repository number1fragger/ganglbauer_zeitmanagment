<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { planningApi } from '@/api'
import { errorMessage } from '@/api/http'
import type { ActualTimeReport, Overview } from '@/api/types'
import {
  addDays,
  describeShort,
  formatDuration,
  formatSignedHours,
  fromDateKey,
  isoWeek,
  startOfWeek,
  toDateKey,
  weekdayName,
} from '@/utils/time'

/**
 * Auswertung der tatsaechlich geleisteten Arbeitszeit (Ist-Zeit). Es gibt
 * keine geplante Dauer mehr – verglichen wird mit der Vorwoche.
 */
const WEEKS_TO_CHOOSE = 8

/** Die laufende und die letzten Wochen zur Auswahl. */
const weeks = Array.from({ length: WEEKS_TO_CHOOSE }, (_, i) => {
  const monday = addDays(startOfWeek(new Date()), -7 * i)
  return {
    key: toDateKey(monday),
    label: `KW ${isoWeek(monday)}`,
    from: monday,
    to: addDays(monday, 6),
  }
})

const selected = ref(weeks[0]!.key)
const report = ref<ActualTimeReport | null>(null)
const overview = ref<Overview | null>(null)
const error = ref('')

async function load(): Promise<void> {
  const week = weeks.find((w) => w.key === selected.value)!
  error.value = ''
  try {
    ;[report.value, overview.value] = await Promise.all([
      planningApi.report(toDateKey(week.from), toDateKey(week.to)),
      planningApi.overview(),
    ])
  } catch (e) {
    error.value = errorMessage(e)
  }
}

watch(selected, load, { immediate: true })

const hours = (minutes: number) => formatDuration(minutes * 60)

const kpis = computed(() => {
  const t = report.value?.totals
  if (!t) return []
  const diff = t.actualMinutes - t.previousActualMinutes
  return [
    {
      label: 'Ist-Zeit gesamt',
      value: hours(t.actualMinutes),
      note: t.previousActualMinutes
        ? `${formatSignedHours(diff)} zur Vorwoche`
        : 'kein Vergleich zur Vorwoche',
    },
    {
      label: 'Bearbeitete Aufträge',
      value: String(t.jobsWorkedOn),
      note: `${t.workers} Arbeiter mit erfasster Zeit`,
    },
    { label: 'Abgeschlossen', value: String(t.jobsCompleted), note: 'Aufträge in diesem Zeitraum' },
    {
      label: 'Mehrtägige Arbeiten',
      value: String(t.multiDayJobs),
      note: t.autoClosedEntries
        ? `${t.autoClosedEntries} Abschnitt(e) automatisch beendet`
        : 'alle Abschnitte selbst beendet',
    },
  ]
})

/** Laengster Balken = groesster Wert. */
const workerScale = computed(() =>
  Math.max(1, ...(report.value?.perWorker ?? []).map((w) => w.actualMinutes)),
)
const dayScale = computed(() =>
  Math.max(60, ...(report.value?.perDay ?? []).map((d) => d.actualMinutes)),
)

const dayLabel = (key: string) => {
  const day = fromDateKey(key)
  return day ? weekdayName(day, 'short') : key
}

/** Wann braucht wer wieder Arbeit – laut den Terminen im Kalender. */
const utilisation = computed(() => {
  const workers = overview.value?.workers ?? []
  const now = Date.now()
  const ahead = (w: (typeof workers)[number]) =>
    Math.max(0, new Date(w.availableFrom).getTime() - now)
  const max = Math.max(24 * 3_600_000, ...workers.map(ahead))

  return [...workers]
    .sort((a, b) => a.availableFrom.localeCompare(b.availableFrom))
    .map((w) => {
      const ms = ahead(w)
      return {
        name: w.user.fullName,
        percent: Math.max(2, (ms / max) * 100),
        color:
          ms < 4 * 3_600_000
            ? 'var(--danger)'
            : ms < 24 * 3_600_000
              ? 'var(--warning)'
              : 'var(--success)',
        freeFrom: `frei ab ${describeShort(new Date(w.availableFrom))}`,
      }
    })
})
</script>

<template>
  <div class="report">
    <header class="report__header">
      <div>
        <h1 class="page-title">Auswertung</h1>
        <p class="page-sub">Tatsächlich geleistete Arbeitszeit (Ist-Zeit) aus der Zeiterfassung</p>
      </div>
      <label class="report__period">
        <span>Zeitraum:</span>
        <select v-model="selected" aria-label="Zeitraum">
          <option v-for="week in weeks" :key="week.key" :value="week.key">{{ week.label }}</option>
        </select>
      </label>
    </header>

    <main class="report__main">
      <p v-if="error" class="form-error" role="alert">{{ error }}</p>

      <section v-if="report" class="kpis">
        <article v-for="kpi in kpis" :key="kpi.label" class="card kpi">
          <span class="kpi__label">{{ kpi.label }}</span>
          <strong class="kpi__value">{{ kpi.value }}</strong>
          <span class="kpi__note">{{ kpi.note }}</span>
        </article>
      </section>

      <div v-if="report" class="report__row">
        <section class="card panel">
          <h2 class="section-title">Ist-Zeit je Arbeiter</h2>
          <p class="section-sub">Summe aller Arbeitsabschnitte im Zeitraum</p>

          <div v-if="report.perWorker.length" class="bars">
            <div v-for="row in report.perWorker" :key="row.workerId" class="bars__worker">
              <strong>{{ row.worker }}</strong>
              <div class="bars__line">
                <span
                  class="bars__bar"
                  :style="{ width: `${(row.actualMinutes / workerScale) * 75}%` }"
                />
                <small
                  >{{ hours(row.actualMinutes) }} · {{ row.jobs }} Auftr. ·
                  {{ row.days }} Tag(e)</small
                >
              </div>
            </div>
          </div>
          <p v-else class="empty report__empty">
            In diesem Zeitraum wurde keine Arbeitszeit erfasst.
          </p>
        </section>

        <section class="card panel">
          <h2 class="section-title">Aufwändigste Aufträge</h2>
          <ul v-if="report.topJobs.length" class="deviations">
            <li v-for="j in report.topJobs" :key="j.jobId">
              <span>
                <strong>{{ j.title }}</strong>
                <small
                  >{{ j.worker
                  }}<template v-if="j.workedDays > 1">
                    · an {{ j.workedDays }} Tagen</template
                  ></small
                >
              </span>
              <b class="tabular">{{ hours(j.actualMinutes) }}</b>
            </li>
          </ul>
          <p v-else class="empty report__empty">Noch keine erfasste Arbeitszeit.</p>
        </section>
      </div>

      <section v-if="report" class="card panel">
        <h2 class="section-title">Ist-Zeit je Tag</h2>
        <div class="days" role="list">
          <div v-for="d in report.perDay" :key="d.date" class="days__col" role="listitem">
            <span class="days__track">
              <span
                class="days__bar"
                :style="{ height: `${(d.actualMinutes / dayScale) * 100}%` }"
              />
            </span>
            <small class="tabular">{{ d.actualMinutes ? hours(d.actualMinutes) : '–' }}</small>
            <b>{{ dayLabel(d.date) }}</b>
          </div>
        </div>
      </section>

      <section v-if="utilisation.length" class="card panel">
        <h2 class="section-title">Wann wird wieder Arbeit gebraucht?</h2>
        <p class="section-sub">Laut den Terminen im Kalender</p>
        <ul class="utilisation">
          <li v-for="row in utilisation" :key="row.name">
            <span>{{ row.name }}</span>
            <span class="utilisation__track">
              <span
                class="utilisation__bar"
                :style="{ width: `${row.percent}%`, background: row.color }"
              />
            </span>
            <small>{{ row.freeFrom }}</small>
          </li>
        </ul>
      </section>
    </main>
  </div>
</template>

<style scoped>
.report__header {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  justify-content: space-between;
  gap: 16px;
  padding: 20px 28px 16px;
}

.report__period {
  display: flex;
  align-items: center;
  gap: 6px;
  height: 36px;
  padding: 0 14px 0 20px;
  border-radius: var(--radius);
  background: var(--surface-muted);
  font-size: 12px;
  font-weight: 500;
}

.report__period select {
  border: 0;
  background: transparent;
  color: var(--text);
  font-weight: 600;
}

.report__period select option {
  background: var(--surface);
}

.report__main {
  display: grid;
  gap: 24px;
  max-width: 1080px;
  margin: 0 auto;
  padding: 28px 32px 48px;
}

.kpis {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
  gap: 16px;
}

.kpi {
  display: flex;
  flex-direction: column;
  gap: 8px;
  min-height: 104px;
  padding: 16px 18px;
}

.kpi__label {
  color: var(--muted);
  font-size: 11px;
  font-weight: 500;
}

.kpi__value {
  font-size: 24px;
  font-weight: 700;
}

.kpi__note {
  color: var(--muted);
  font-size: 10px;
}

.kpi--bad .kpi__value {
  color: var(--danger);
}

.kpi--good .kpi__value {
  color: var(--success);
}

.kpi--warn .kpi__value {
  color: var(--warning);
}

.report__row {
  display: grid;
  grid-template-columns: minmax(0, 2fr) minmax(280px, 1fr);
  gap: 24px;
}

.panel {
  padding: 20px;
}

.report__empty {
  margin-top: 12px;
  padding: 0;
}

.bars {
  display: grid;
  gap: 22px;
  margin-top: 20px;
}

.bars__worker strong {
  display: block;
  margin-bottom: 6px;
  font-size: 12px;
  font-weight: 600;
}

.bars__line {
  display: flex;
  align-items: center;
  gap: 6px;
  margin-top: 6px;
}

.bars__bar {
  height: 14px;
  border-radius: 4px;
  background: var(--primary);
}

.bars__line small {
  color: var(--muted);
  font-size: 10px;
  font-weight: 500;
}

.bars--ok .bars__bar {
  background: var(--success);
}

.bars--ok small {
  color: var(--success);
}

.bars--over .bars__bar {
  background: var(--warning);
}

.bars--over small {
  color: var(--danger);
}

.deviations {
  display: grid;
  gap: 14px;
  margin: 18px 0 0;
  padding: 0;
  list-style: none;
}

.deviations li {
  display: flex;
  align-items: center;
  justify-content: space-between;
  min-height: 62px;
  padding: 12px 16px;
  border-radius: var(--radius);
  background: var(--surface-alt);
}

.deviations strong {
  display: block;
  font-size: 12px;
  font-weight: 600;
}

.deviations small {
  display: block;
  margin-top: 4px;
  color: var(--muted);
  font-size: 10px;
}

.deviations b {
  font-size: 14px;
}

.deviations--over {
  color: var(--danger);
}

.deviations--under {
  color: var(--success);
}

.days {
  display: grid;
  grid-template-columns: repeat(7, minmax(0, 1fr));
  gap: 10px;
  margin-top: 18px;
}

.days__col {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 6px;
}

.days__track {
  display: flex;
  align-items: flex-end;
  width: 100%;
  max-width: 44px;
  height: 120px;
  border-radius: var(--radius-sm);
  background: var(--grid);
}

.days__bar {
  display: block;
  width: 100%;
  min-height: 2px;
  border-radius: var(--radius-sm);
  background: var(--primary);
}

.days__col small {
  color: var(--muted);
  font-size: 10px;
}

.days__col b {
  font-size: 11px;
}

.utilisation {
  display: grid;
  gap: 14px;
  margin: 18px 0 0;
  padding: 0;
  list-style: none;
}

.utilisation li {
  display: grid;
  grid-template-columns: 138px 1fr 110px;
  align-items: center;
  gap: 20px;
  font-size: 11px;
  font-weight: 500;
}

.utilisation small {
  color: var(--muted);
  font-size: 10px;
}

.utilisation__track {
  height: 10px;
  border-radius: 5px;
  background: var(--grid);
}

.utilisation__bar {
  display: block;
  height: 100%;
  border-radius: 5px;
}

@media (max-width: 860px) {
  .report__row {
    grid-template-columns: 1fr;
  }

  .report__main,
  .report__header {
    padding-inline: 16px;
  }

  .utilisation li {
    grid-template-columns: 1fr;
    gap: 6px;
  }
}
</style>
