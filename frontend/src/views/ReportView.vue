<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { planningApi } from '@/api'
import { errorMessage } from '@/api/http'
import type { Overview, SollIstReport } from '@/api/types'
import RoleBadge from '@/components/RoleBadge.vue'
import { addDays, describeShort, formatHours, formatSignedHours, isoWeek, startOfWeek, toDateKey } from '@/utils/time'

const WEEKS_TO_CHOOSE = 8

/** Die laufende und die letzten Wochen zur Auswahl. */
const weeks = Array.from({ length: WEEKS_TO_CHOOSE }, (_, i) => {
  const monday = addDays(startOfWeek(new Date()), -7 * i)
  return { key: toDateKey(monday), label: `KW ${isoWeek(monday)}`, from: monday, to: addDays(monday, 6) }
})

const selected = ref(weeks[0]!.key)
const report = ref<SollIstReport | null>(null)
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

const kpis = computed(() => {
  const totals = report.value?.totals
  if (!totals) return []
  const diff = totals.actualMinutes - totals.plannedMinutes
  const percent = (value: number | null) =>
    value === null ? '–' : `${value.toLocaleString('de-AT', { minimumFractionDigits: 1 })} %`

  return [
    {
      label: 'Geplante Zeit gesamt',
      value: formatHours(totals.plannedMinutes),
      note: `${totals.workers} Arbeiter · ${totals.jobs} Arbeiten`,
      tone: 'neutral',
    },
    {
      label: 'Tatsächliche Zeit',
      value: formatHours(totals.actualMinutes),
      note: `${formatSignedHours(diff)} gegenüber Plan`,
      tone: diff > 0 ? 'bad' : 'good',
    },
    {
      label: 'Planungsgenauigkeit',
      value: percent(totals.accuracyPercent),
      note:
        totals.accuracyDelta === null
          ? 'kein Vergleich zur Vorwoche'
          : `${totals.accuracyDelta > 0 ? '+' : ''}${totals.accuracyDelta.toLocaleString('de-AT')} % zur Vorwoche`,
      tone: (totals.accuracyPercent ?? 0) >= 90 ? 'good' : 'warn',
    },
    { label: 'Zeitüberschreitungen', value: String(totals.overruns), note: `von ${totals.jobs} Arbeiten`, tone: 'warn' },
  ]
})

/** Laengster Balken = groesster Wert ueber alle Arbeiter. */
const scale = computed(() =>
  Math.max(1, ...(report.value?.perWorker ?? []).flatMap((w) => [w.plannedMinutes, w.actualMinutes])),
)

/** Auslastung: Restarbeit relativ zum am laengsten ausgelasteten Arbeiter (mindestens ein Tag). */
const utilisation = computed(() => {
  const workers = overview.value?.workers ?? []
  const max = Math.max(8 * 60, ...workers.map((w) => w.remainingMinutes))

  return [...workers]
    .sort((a, b) => a.availableFrom.localeCompare(b.availableFrom))
    .map((w) => ({
      name: w.user.fullName,
      percent: Math.max(2, (w.remainingMinutes / max) * 100),
      color: w.remainingMinutes < 4 * 60 ? 'var(--danger)' : w.remainingMinutes < 8 * 60 ? 'var(--warning)' : 'var(--success)',
      freeFrom: `frei ab ${describeShort(new Date(w.availableFrom))}`,
    }))
})
</script>

<template>
  <div class="report">
    <header class="report__header">
      <div>
        <h1 class="page-title">Auswertung</h1>
        <p class="page-sub">Geplante gegenüber tatsächlich benötigter Zeit</p>
      </div>
      <RouterLink class="link report__back" :to="{ name: 'planning' }">Zur Planung</RouterLink>
      <RoleBadge with-logout />
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
        <article v-for="kpi in kpis" :key="kpi.label" class="card kpi" :class="`kpi--${kpi.tone}`">
          <span class="kpi__label">{{ kpi.label }}</span>
          <strong class="kpi__value">{{ kpi.value }}</strong>
          <span class="kpi__note">{{ kpi.note }}</span>
        </article>
      </section>

      <div v-if="report" class="report__row">
        <section class="card panel">
          <h2 class="section-title">Soll/Ist je Arbeiter</h2>
          <p class="section-sub">blau = geplant · grün/orange = tatsächlich (orange heißt länger als geplant)</p>

          <div v-if="report.perWorker.length" class="bars">
            <div v-for="row in report.perWorker" :key="row.worker" class="bars__worker">
              <strong>{{ row.worker }}</strong>
              <div class="bars__line">
                <span class="bars__bar" :style="{ width: `${(row.plannedMinutes / scale) * 75}%` }" />
                <small>{{ formatHours(row.plannedMinutes) }}</small>
              </div>
              <div class="bars__line" :class="row.actualMinutes > row.plannedMinutes ? 'bars--over' : 'bars--ok'">
                <span class="bars__bar" :style="{ width: `${(row.actualMinutes / scale) * 75}%` }" />
                <small>{{ formatHours(row.actualMinutes) }}</small>
              </div>
            </div>
          </div>
          <p v-else class="empty report__empty">In dieser Woche wurden keine Arbeiten mit erfasster Zeit abgeschlossen.</p>
        </section>

        <section class="card panel">
          <h2 class="section-title">Größte Abweichungen</h2>
          <ul v-if="report.deviations.length" class="deviations">
            <li v-for="d in report.deviations" :key="d.jobId">
              <span>
                <strong>{{ d.title }}</strong>
                <small>{{ d.worker }}</small>
              </span>
              <b :class="d.diffMinutes > 0 ? 'deviations--over' : 'deviations--under'">
                {{ formatSignedHours(d.diffMinutes) }}
              </b>
            </li>
          </ul>
          <p v-else class="empty report__empty">Keine Abweichungen – alles wie geplant.</p>
        </section>
      </div>

      <section v-if="utilisation.length" class="card panel">
        <h2 class="section-title">Auslastung – wann wird wieder Arbeit gebraucht?</h2>
        <ul class="utilisation">
          <li v-for="row in utilisation" :key="row.name">
            <span>{{ row.name }}</span>
            <span class="utilisation__track">
              <span class="utilisation__bar" :style="{ width: `${row.percent}%`, background: row.color }" />
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
  gap: 16px;
  padding: 22px 32px 16px;
  border-bottom: 1px solid var(--border);
  background: var(--surface);
}

.report__back {
  margin-left: auto;
  font-size: 12px;
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
  font-weight: 600;
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
