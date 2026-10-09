<script setup lang="ts">
import { computed } from 'vue'
import type { Overview } from '@/api/types'
import { describeMoment, describeSlot, formatHours, formatSignedHours } from '@/utils/time'
import PriorityLegend from '../PriorityLegend.vue'

/** Unter einem halben Arbeitstag Restarbeit gilt "braucht bald Arbeit". */
const SOON_MINUTES = 4 * 60

const props = defineProps<{ overview: Overview }>()
defineEmits<{ assign: [request: Overview['requests'][number]]; openJob: [id: number] }>()

const capacity = computed(() =>
  [...props.overview.workers].sort((a, b) => a.availableFrom.localeCompare(b.availableFrom)),
)

const remainingText = (minutes: number) =>
  minutes <= 2 * 60 ? `in ${formatHours(minutes)} fertig` : `noch ${formatHours(minutes)} Arbeit`
</script>

<template>
  <aside class="sidebar">
    <section>
      <h2 class="section-title">Kapazität</h2>
      <p class="section-sub">Wann ist welcher Arbeiter wieder frei?</p>
      <ul class="sidebar__list">
        <li
          v-for="worker in capacity"
          :key="worker.user.id"
          class="card accent capacity"
          :class="{ 'capacity--soon': worker.remainingMinutes < SOON_MINUTES }"
        >
          <strong>{{ worker.user.fullName }}</strong>
          <span class="capacity__when">braucht Arbeit: {{ describeMoment(new Date(worker.availableFrom)) }}</span>
          <small>{{ remainingText(worker.remainingMinutes) }}</small>
        </li>
      </ul>
    </section>

    <section>
      <h2 class="section-title">Offene Anfragen</h2>
      <p class="section-sub">Arbeiter haben Arbeit angefordert</p>
      <ul v-if="overview.requests.length" class="sidebar__list">
        <li v-for="request in overview.requests" :key="request.id" class="card request">
          <span>
            <strong>{{ request.user.fullName }}</strong>
            <span class="request__when">braucht Arbeit: {{ describeSlot(new Date(request.neededAt)) }}</span>
          </span>
          <button class="btn btn--primary request__assign" @click="$emit('assign', request)">zuteilen</button>
        </li>
      </ul>
      <p v-else class="empty sidebar__empty">Gerade wartet niemand auf neue Arbeit.</p>
    </section>

    <section>
      <h2 class="section-title">Warnungen</h2>
      <ul v-if="overview.warnings.length" class="sidebar__list">
        <li v-for="warning in overview.warnings" :key="warning.jobId">
          <button class="card warning" @click="$emit('openJob', warning.jobId)">
            <span class="badge warning__icon">!</span>
            <span>
              <strong>Zeit überschritten</strong>
              <small>
                {{ warning.title }}<template v-if="warning.worker"> · {{ warning.worker }}</template>
                · {{ formatSignedHours(warning.overrunMinutes) }}
              </small>
            </span>
          </button>
        </li>
      </ul>
      <p v-else class="empty sidebar__empty">Alle Arbeiten liegen im Plan.</p>
    </section>

    <PriorityLegend with-done />
  </aside>
</template>

<style scoped>
.sidebar {
  display: flex;
  flex-direction: column;
  gap: 28px;
  padding: 20px 16px 32px 20px;
}

.sidebar__list {
  display: grid;
  gap: 12px;
  margin: 14px 0 0;
  padding: 0;
  list-style: none;
}

.sidebar__empty {
  margin-top: 12px;
}

.capacity {
  --accent: var(--primary);
  display: flex;
  flex-direction: column;
  gap: 4px;
  padding: 12px 16px;
}

.capacity strong {
  font-size: 12px;
  font-weight: 600;
}

.capacity__when {
  color: var(--primary);
  font-size: 11px;
  font-weight: 500;
}

.capacity--soon {
  --accent: var(--danger);
}

.capacity--soon .capacity__when {
  color: var(--danger);
}

.capacity small {
  color: var(--muted);
  font-size: 10px;
}

.request {
  display: flex;
  align-items: flex-end;
  justify-content: space-between;
  gap: 12px;
  padding: 12px 16px;
  background: var(--warning-soft);
}

.request strong {
  display: block;
  font-size: 12px;
  font-weight: 600;
}

.request__when {
  display: block;
  margin-top: 4px;
  color: var(--warning-ink);
  font-size: 11px;
  font-weight: 500;
}

.request__assign {
  width: 96px;
  height: 22px;
  border-radius: var(--radius-sm);
  font-size: 10px;
}

.warning {
  display: flex;
  align-items: flex-start;
  gap: 10px;
  width: 100%;
  padding: 12px 16px;
  border: 0;
  background: var(--danger-soft);
  text-align: left;
}

.warning__icon {
  background: var(--danger);
  font-size: 11px;
}

.warning strong {
  display: block;
  color: var(--danger);
  font-size: 12px;
  font-weight: 600;
}

.warning small {
  display: block;
  margin-top: 4px;
  color: var(--muted);
  font-size: 10px;
}
</style>
