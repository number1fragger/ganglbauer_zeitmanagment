<script setup lang="ts">
import { computed } from 'vue'
import type { Overview } from '@/api/types'
import { DAY_MS, describeMoment, describeSlot } from '@/utils/time'
import PriorityLegend from '../PriorityLegend.vue'

/** Wer innerhalb eines Tages frei wird, braucht bald neue Arbeit. */
const soon = (availableFrom: string) => new Date(availableFrom).getTime() - Date.now() < DAY_MS

const props = defineProps<{ overview: Overview }>()
defineEmits<{ assign: [request: Overview['requests'][number]]; openJob: [id: number] }>()

const capacity = computed(() =>
  [...props.overview.workers].sort((a, b) => a.availableFrom.localeCompare(b.availableFrom)),
)

const openText = (worker: Overview['workers'][number]) =>
  `${worker.openJobs} offen` + (worker.unscheduledJobs ? ` · ${worker.unscheduledJobs} ohne Termin` : '')
</script>

<template>
  <aside class="sidebar">
    <section>
      <h2 class="section-title">Kapazität</h2>
      <p class="section-sub">Wann ist welcher Arbeiter laut Kalender wieder frei?</p>
      <ul class="sidebar__list">
        <li
          v-for="worker in capacity"
          :key="worker.user.id"
          class="card accent capacity"
          :class="{ 'capacity--soon': soon(worker.availableFrom) }"
        >
          <strong>{{ worker.user.fullName }}</strong>
          <span class="capacity__when">braucht Arbeit: {{ describeMoment(new Date(worker.availableFrom)) }}</span>
          <small>{{ openText(worker) }}</small>
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




</style>
