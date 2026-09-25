<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import { errorMessage, http } from '@/api/client'
import type { SollIstReport } from '@/api/types'
import { decimalHours, formatDate, formatDuration, toDateInput } from '@/utils/format'

/** A1 – Soll/Ist-Vergleich für die bessere künftige Planung. */
const report = ref<SollIstReport | null>(null)
const error = ref('')
const loading = ref(true)

const range = reactive({
  from: toDateInput(new Date(Date.now() - 30 * 86_400_000)),
  to: toDateInput(new Date()),
})

const maxMinutes = computed(() =>
  Math.max(
    60,
    ...(report.value?.perWorker.flatMap((w) => [w.plannedMinutes, w.actualMinutes]) ?? []),
  ),
)

async function reload(): Promise<void> {
  error.value = ''
  loading.value = true

  try {
    const { data } = await http.get<SollIstReport>('/api/reports/soll-ist', { params: range })
    report.value = data
  } catch (e) {
    error.value = errorMessage(e)
  } finally {
    loading.value = false
  }
}

onMounted(reload)

function exportCsv(): void {
  if (!report.value) return

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

  const url = URL.createObjectURL(new Blob([`﻿${csv}`], { type: 'text/csv;charset=utf-8' }))
  const link = document.createElement('a')
  link.href = url
  link.download = `soll-ist_${range.from}_${range.to}.csv`
  link.click()
  URL.revokeObjectURL(url)
}
</script>

<template>
  <div>
    <h1>Auswertung</h1>
    <p class="muted">Geplante gegen tatsächlich benötigte Zeit bei erledigten Arbeiten.</p>

    <p v-if="error" class="error">{{ error }}</p>

    <section class="card filters">
      <div>
        <label for="from">Von</label>
        <input id="from" v-model="range.from" type="date" @change="reload" />
      </div>
      <div>
        <label for="to">Bis</label>
        <input id="to" v-model="range.to" type="date" @change="reload" />
      </div>
      <button type="button" class="secondary" @click="exportCsv">CSV exportieren</button>
    </section>

    <p v-if="loading" class="muted">Wird geladen …</p>

    <section v-if="report && report.perWorker.length" class="card">
      <h2>Pro Arbeiter</h2>

      <ul class="bars">
        <li v-for="worker in report.perWorker" :key="worker.worker">
          <div class="bars__head">
            <strong>{{ worker.worker }}</strong>
            <span class="muted">{{ worker.jobs }} Arbeiten</span>
            <span class="mono" :class="worker.diffMinutes > 0 ? 'over' : 'under'">
              {{ worker.diffMinutes > 0 ? '+' : '' }}{{ formatDuration(worker.diffMinutes) }}
            </span>
          </div>

          <div class="pair">
            <span class="pair__label muted">Soll</span>
            <div class="bar">
              <span
                class="soll"
                :style="{ width: `${(worker.plannedMinutes / maxMinutes) * 100}%` }"
              ></span>
            </div>
            <span class="mono muted">{{ formatDuration(worker.plannedMinutes) }}</span>
          </div>

          <div class="pair">
            <span class="pair__label muted">Ist</span>
            <div class="bar">
              <span
                :class="worker.diffMinutes > 0 ? 'ist over' : 'ist'"
                :style="{ width: `${(worker.actualMinutes / maxMinutes) * 100}%` }"
              ></span>
            </div>
            <span class="mono muted">{{ formatDuration(worker.actualMinutes) }}</span>
          </div>
        </li>
      </ul>
    </section>

    <section v-if="report && report.jobs.length" class="card">
      <h2>Einzelne Arbeiten</h2>

      <table>
        <thead>
          <tr>
            <th>Arbeit</th>
            <th>Arbeiter</th>
            <th class="right">Soll</th>
            <th class="right">Ist</th>
            <th class="right">Differenz</th>
            <th>Erledigt</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="job in report.jobs" :key="job.jobId">
            <td>
              {{ job.title }}
              <div v-if="job.customer" class="muted small">{{ job.customer }}</div>
            </td>
            <td class="muted">{{ job.worker }}</td>
            <td class="mono right">
              {{ formatDuration(job.plannedMinutes) }}
              <div v-if="job.plannedMinutes !== job.originalPlannedMinutes" class="muted small">
                urspr. {{ formatDuration(job.originalPlannedMinutes) }}
              </div>
            </td>
            <td class="mono right">{{ formatDuration(job.actualMinutes) }}</td>
            <td class="mono right" :class="job.diffMinutes > 0 ? 'over' : 'under'">
              {{ job.diffMinutes > 0 ? '+' : '' }}{{ formatDuration(job.diffMinutes) }}
            </td>
            <td class="muted">{{ formatDate(job.completedAt) }}</td>
          </tr>
        </tbody>
      </table>
    </section>

    <p v-if="!loading && report && report.jobs.length === 0" class="card muted">
      Im gewählten Zeitraum wurden keine Arbeiten abgeschlossen.
    </p>
  </div>
</template>

<style scoped>
section {
  margin-bottom: 1rem;
}

.filters {
  display: flex;
  gap: 1rem;
  align-items: flex-end;
  flex-wrap: wrap;
}

.filters input {
  width: auto;
}

.filters button {
  margin-left: auto;
}

.bars {
  list-style: none;
  margin: 0;
  padding: 0;
  display: grid;
  gap: 1.25rem;
}

.bars__head {
  display: flex;
  align-items: baseline;
  gap: 0.6rem;
  margin-bottom: 0.4rem;
  font-size: 0.875rem;
}

.bars__head span:last-child {
  margin-left: auto;
  font-weight: 600;
}

.pair {
  display: flex;
  align-items: center;
  gap: 0.6rem;
  margin-bottom: 0.3rem;
  font-size: 0.75rem;
}

.pair__label {
  width: 2.2rem;
  flex: none;
}

.pair .mono {
  width: 4.5rem;
  text-align: right;
  flex: none;
}

.bar {
  flex: 1;
  background: var(--bg);
  border-radius: 999px;
  height: 10px;
  overflow: hidden;
}

.bar span {
  display: block;
  height: 100%;
  border-radius: 999px;
}

.bar .soll {
  background: var(--muted);
}

.bar .ist {
  background: var(--success);
}

.bar .ist.over {
  background: var(--danger);
}

.over {
  color: var(--danger);
}

.under {
  color: var(--success);
}

.right {
  text-align: right;
}

.small {
  font-size: 0.75rem;
}
</style>
