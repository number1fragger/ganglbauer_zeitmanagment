<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { planningApi } from '@/api'
import { errorMessage } from '@/api/http'
import type { WorkRequestReport } from '@/api/types'
import { roleOf } from '@/utils/domain'
import { addDays, fromDateKey, startOfMonth, startOfWeek, toDateKey } from '@/utils/time'

/**
 * Statistik "Wie oft fragen Mitarbeiter Arbeit an?" – Zeitraum Woche, Monat
 * oder frei waehlbar. Gezaehlt wird serverseitig (Zaehlregel steht unter der
 * Tabelle und im Backend: WorkRequestReport).
 */
type Period = 'woche' | 'monat' | 'frei'

const period = ref<Period>('monat')
const anchor = ref(new Date())
const customFrom = ref(toDateKey(addDays(new Date(), -30)))
const customTo = ref(toDateKey(new Date()))

const range = computed(() => {
  if (period.value === 'woche') {
    const from = startOfWeek(anchor.value)
    return { from, to: addDays(from, 6) }
  }
  if (period.value === 'monat') {
    const from = startOfMonth(anchor.value)
    return { from, to: addDays(new Date(from.getFullYear(), from.getMonth() + 1, 1), -1) }
  }
  const from = fromDateKey(customFrom.value) ?? new Date()
  const to = fromDateKey(customTo.value) ?? from
  return { from, to: to < from ? from : to }
})

const label = computed(() => {
  const { from, to } = range.value
  if (period.value === 'monat') return from.toLocaleDateString('de-AT', { month: 'long', year: 'numeric' })
  const day = (d: Date) => d.toLocaleDateString('de-AT', { day: '2-digit', month: '2-digit', year: 'numeric' })
  return `${day(from)} – ${day(to)}`
})

function step(direction: -1 | 1): void {
  const date = anchor.value
  anchor.value =
    period.value === 'woche'
      ? addDays(date, 7 * direction)
      : new Date(date.getFullYear(), date.getMonth() + direction, 1)
}

const report = ref<WorkRequestReport | null>(null)
const error = ref('')
const loading = ref(false)
let token = 0

async function load(): Promise<void> {
  const current = ++token
  loading.value = true
  error.value = ''
  try {
    const result = await planningApi.workRequestReport(toDateKey(range.value.from), toDateKey(range.value.to))
    if (current === token) report.value = result
  } catch (e) {
    if (current === token) error.value = errorMessage(e)
  } finally {
    if (current === token) loading.value = false
  }
}

watch(range, load, { immediate: true })

const scale = computed(() => Math.max(1, ...(report.value?.perWorker ?? []).map((r) => r.requests)))

const comparison = computed(() => {
  const t = report.value?.totals
  if (!t) return ''
  const diff = t.requests - t.previousRequests
  if (!t.previousRequests && !t.requests) return 'keine Anfragen im Vergleichszeitraum'
  return `${diff > 0 ? '+' : diff < 0 ? '−' : '±'}${Math.abs(diff)} zum gleich langen Zeitraum davor`
})
</script>

<template>
  <section class="card panel requests-stats" aria-labelledby="requests-title">
    <header class="requests-stats__head">
      <div>
        <h2 id="requests-title" class="section-title">Arbeitsanfragen („Brauche Arbeit“)</h2>
        <p class="section-sub">Wie oft fragen Mitarbeiter neue Arbeit an?</p>
      </div>
      <div class="requests-stats__period">
        <div class="segmented" role="group" aria-label="Zeitraum">
          <button :aria-pressed="period === 'woche'" @click="period = 'woche'">Woche</button>
          <button :aria-pressed="period === 'monat'" @click="period = 'monat'">Monat</button>
          <button :aria-pressed="period === 'frei'" @click="period = 'frei'">Zeitraum</button>
        </div>
        <template v-if="period !== 'frei'">
          <button class="btn btn--icon btn--ghost" aria-label="Zurück" @click="step(-1)">‹</button>
          <strong class="requests-stats__label">{{ label }}</strong>
          <button class="btn btn--icon btn--ghost" aria-label="Weiter" @click="step(1)">›</button>
        </template>
        <template v-else>
          <input v-model="customFrom" class="input requests-stats__date" type="date" aria-label="Von" />
          <span>–</span>
          <input
            v-model="customTo"
            class="input requests-stats__date"
            type="date"
            :min="customFrom"
            aria-label="Bis"
          />
        </template>
        <span v-if="loading" class="spinner" aria-label="Lädt" />
      </div>
    </header>

    <p v-if="error" class="form-error" role="alert">{{ error }}</p>

    <template v-if="report">
      <div class="requests-stats__kpis">
        <div>
          <span>Anfragen gesamt</span>
          <strong>{{ report.totals.requests }}</strong>
          <small>{{ comparison }}</small>
        </div>
        <div>
          <span>Davon zugeteilt</span>
          <strong>{{ report.totals.fulfilled }}</strong>
          <small>{{ report.totals.open }} noch offen</small>
        </div>
        <div>
          <span>Mitarbeiter mit Anfragen</span>
          <strong>{{ report.totals.workersWithRequests }}</strong>
          <small>Ø {{ report.totals.averagePerWorker.toLocaleString('de-AT') }} je Mitarbeiter</small>
        </div>
        <div>
          <span>Zurückgezogen</span>
          <strong>{{ report.totals.withdrawn }}</strong>
          <small>zählen nicht als Anfrage</small>
        </div>
      </div>

      <table v-if="report.perWorker.length" class="requests-stats__table">
        <thead>
          <tr>
            <th scope="col">Mitarbeiter</th>
            <th scope="col" class="requests-stats__bar-col">Vergleich</th>
            <th scope="col" class="num">Anfragen</th>
            <th scope="col" class="num">Anteil</th>
            <th scope="col" class="num">zugeteilt</th>
            <th scope="col" class="num">offen</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="row in report.perWorker" :key="row.workerId ?? row.worker">
            <td>
              <strong>{{ row.worker }}</strong>
              <small>{{
                row.deleted ? 'gelöscht' : row.role ? roleOf(row.role).label : ''
              }}</small>
            </td>
            <td class="requests-stats__bar-col">
              <span class="requests-stats__track">
                <span
                  class="requests-stats__bar"
                  :style="{ width: `${(row.requests / scale) * 100}%` }"
                />
              </span>
            </td>
            <td class="num"><b>{{ row.requests }}</b></td>
            <td class="num">{{ row.share.toLocaleString('de-AT') }} %</td>
            <td class="num">{{ row.fulfilled }}</td>
            <td class="num">{{ row.open }}</td>
          </tr>
        </tbody>
      </table>
      <p class="requests-stats__rule">{{ report.rule }}</p>
    </template>
  </section>
</template>

<style scoped>
.requests-stats {
  display: grid;
  gap: 18px;
}

.requests-stats__head {
  display: flex;
  flex-wrap: wrap;
  align-items: flex-start;
  justify-content: space-between;
  gap: 12px;
}

.requests-stats__period {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 6px;
}

.requests-stats__label {
  min-width: 150px;
  font-size: 12px;
  text-align: center;
}

.requests-stats__date {
  width: auto;
  height: 34px;
  font-size: 12px;
}

.requests-stats__kpis {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
  gap: 12px;
}

.requests-stats__kpis div {
  display: flex;
  flex-direction: column;
  gap: 4px;
  padding: 12px 14px;
  border-radius: var(--radius);
  background: var(--surface-alt);
}

.requests-stats__kpis span,
.requests-stats__kpis small {
  color: var(--muted);
  font-size: 10px;
}

.requests-stats__kpis span {
  font-size: 11px;
  font-weight: 500;
}

.requests-stats__kpis strong {
  font-size: 22px;
  font-weight: 700;
}

.requests-stats__table {
  width: 100%;
  border-collapse: collapse;
  font-size: 12px;
}

.requests-stats__table th {
  padding: 8px 6px;
  border-bottom: 1px solid var(--border);
  color: var(--muted);
  font-size: 10px;
  font-weight: 600;
  text-align: left;
}

.requests-stats__table td {
  padding: 10px 6px;
  border-bottom: 1px solid var(--grid);
  vertical-align: middle;
}

.requests-stats__table td strong {
  display: block;
  font-weight: 600;
}

.requests-stats__table td small {
  color: var(--muted);
  font-size: 10px;
}

.requests-stats__table .num {
  text-align: right;
  white-space: nowrap;
}

.requests-stats__bar-col {
  width: 40%;
}

.requests-stats__track {
  display: block;
  height: 10px;
  border-radius: 5px;
  background: var(--grid);
}

.requests-stats__bar {
  display: block;
  height: 100%;
  min-width: 2px;
  border-radius: 5px;
  background: var(--primary);
}

.requests-stats__rule {
  color: var(--muted);
  font-size: 11px;
  line-height: 1.5;
}

@media (max-width: 640px) {
  .requests-stats__bar-col {
    display: none;
  }
}
</style>
