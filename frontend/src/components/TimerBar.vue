<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { errorMessage } from '@/api/client'
import { useWorkshopStore } from '@/stores/workshop'
import { formatDuration, priorityColor } from '@/utils/format'

const workshop = useWorkshopStore()

const selectedJobId = ref<number | null>(null)
const error = ref('')
const busy = ref(false)

const clock = computed(() => {
  const total = workshop.runningSeconds
  const h = Math.floor(total / 3600)
  const m = Math.floor((total % 3600) / 60)
  const s = total % 60

  return [h, m, s].map((part) => String(part).padStart(2, '0')).join(':')
})

/** Die Arbeit, an der gerade gearbeitet wird – inklusive Soll/Ist. */
const currentJob = computed(() => {
  const id = workshop.running?.job.id

  return id ? (workshop.jobs.find((job) => job.id === id) ?? null) : null
})

const selectable = computed(() => workshop.openJobs)

onMounted(async () => {
  try {
    await workshop.loadRunning()
  } catch (e) {
    error.value = errorMessage(e)
  }
})

async function start(): Promise<void> {
  if (selectedJobId.value === null) return

  error.value = ''
  busy.value = true

  try {
    await workshop.startWork(selectedJobId.value)
    selectedJobId.value = null
    await Promise.all([workshop.loadJobs(), workshop.loadOverview()])
  } catch (e) {
    error.value = errorMessage(e)
  } finally {
    busy.value = false
  }
}

async function stop(): Promise<void> {
  error.value = ''
  busy.value = true

  try {
    await workshop.stopWork()
    await Promise.all([workshop.loadJobs(), workshop.loadOverview()])
  } catch (e) {
    error.value = errorMessage(e)
  } finally {
    busy.value = false
  }
}
</script>

<template>
  <section class="timer card">
    <div v-if="workshop.running" class="running">
      <span class="pulse" aria-hidden="true"></span>

      <div class="running__info">
        <strong>{{ workshop.running.job.title }}</strong>
        <span class="muted">
          {{ workshop.running.job.customer ?? 'Ohne Kunde' }}
          <template v-if="currentJob">
            · Soll {{ formatDuration(currentJob.plannedMinutes) }}
          </template>
        </span>
      </div>

      <span v-if="currentJob?.overrun" class="warn">
        Zeit überschritten um {{ formatDuration(currentJob.overrunMinutes) }}
      </span>

      <span class="clock mono">{{ clock }}</span>
      <button type="button" class="stop" :disabled="busy" @click="stop">Stopp</button>
    </div>

    <form v-else class="start" @submit.prevent="start">
      <select v-model="selectedJobId" aria-label="Arbeit auswählen">
        <option :value="null">Arbeit auswählen …</option>
        <option v-for="job in selectable" :key="job.id" :value="job.id">
          {{ job.title }}{{ job.customer ? ` – ${job.customer}` : '' }}
        </option>
      </select>

      <span
        v-if="selectedJobId"
        class="dot"
        :style="{
          background: priorityColor(
            selectable.find((j) => j.id === selectedJobId)?.priority ?? 'normal',
          ),
        }"
      ></span>

      <button type="submit" :disabled="busy || selectedJobId === null">Zeit starten</button>
    </form>

    <p v-if="error" class="error">{{ error }}</p>
  </section>
</template>

<style scoped>
.timer {
  margin-bottom: 1.5rem;
}

.start {
  display: flex;
  align-items: center;
  gap: 0.75rem;
}

.start select {
  flex: 1;
}

.running {
  display: flex;
  align-items: center;
  gap: 0.9rem;
}

.running__info {
  display: flex;
  flex-direction: column;
  min-width: 0;
}

.running__info span {
  font-size: 0.8125rem;
}

.warn {
  font-size: 0.75rem;
  font-weight: 600;
  color: var(--danger);
  background: color-mix(in srgb, var(--danger) 12%, transparent);
  padding: 0.2rem 0.5rem;
  border-radius: 999px;
}

.clock {
  margin-left: auto;
  font-size: 1.5rem;
  font-weight: 700;
  letter-spacing: 0.02em;
}

.stop {
  background: var(--danger);
}

.pulse {
  width: 10px;
  height: 10px;
  border-radius: 50%;
  background: var(--success);
  flex: none;
  animation: pulse 1.6s ease-in-out infinite;
}

@keyframes pulse {
  0%,
  100% {
    opacity: 1;
  }
  50% {
    opacity: 0.25;
  }
}

@media (max-width: 720px) {
  .start {
    flex-wrap: wrap;
  }

  .clock {
    font-size: 1.25rem;
  }
}
</style>
