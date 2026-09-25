<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { errorMessage, http } from '@/api/client'
import { useAuthStore } from '@/stores/auth'
import { useWorkshopStore } from '@/stores/workshop'
import type { WorkRequest } from '@/api/types'
import { formatWhen, toDateTimeInput } from '@/utils/format'

/**
 * F7/F8 – "Brauche Arbeit". Die Vorlaufzeit von einem Tag ist im
 * Formular vorgegeben und wird im Backend nochmals geprüft.
 */
const workshop = useWorkshopStore()
const auth = useAuthStore()

const error = ref('')
const busy = ref(false)
const note = ref('')
const all = ref<WorkRequest[]>([])

/** Frühestens morgen 00:00 – dieselbe Regel wie im Backend (WorkRequest::earliestNeededAt). */
const earliest = computed(() => withTime(shift(1), 0))

const neededAt = ref(toDateTimeInput(withTime(earliest.value, 7)))

const quickOptions = computed(() => [
  { label: 'morgen früh', value: toDateTimeInput(withTime(shift(1), 7)) },
  { label: 'morgen nachmittag', value: toDateTimeInput(withTime(shift(1), 13)) },
  { label: 'übermorgen früh', value: toDateTimeInput(withTime(shift(2), 7)) },
  { label: 'übermorgen nachmittag', value: toDateTimeInput(withTime(shift(2), 13)) },
])

function shift(days: number): Date {
  const date = new Date()
  date.setDate(date.getDate() + days)

  return date
}

function withTime(date: Date, hour: number): Date {
  const copy = new Date(date)
  copy.setHours(hour, 0, 0, 0)

  return copy
}

async function reload(): Promise<void> {
  error.value = ''

  try {
    await workshop.loadMyRequest()

    if (auth.isForeman) {
      const { data } = await http.get<WorkRequest[]>('/api/work-requests', {
        params: { all: true },
      })
      all.value = data
    }
  } catch (e) {
    error.value = errorMessage(e)
  }
}

onMounted(reload)

async function submit(): Promise<void> {
  error.value = ''
  busy.value = true

  try {
    await workshop.requestWork(new Date(neededAt.value).toISOString(), note.value || null)
    note.value = ''
    await Promise.all([reload(), auth.isForeman ? workshop.loadOverview() : Promise.resolve()])
  } catch (e) {
    error.value = errorMessage(e)
  } finally {
    busy.value = false
  }
}

async function withdraw(): Promise<void> {
  if (!workshop.myRequest) return

  error.value = ''

  try {
    await workshop.withdrawRequest(workshop.myRequest.id)
    await Promise.all([reload(), auth.isForeman ? workshop.loadOverview() : Promise.resolve()])
  } catch (e) {
    error.value = errorMessage(e)
  }
}

async function markFulfilled(request: WorkRequest): Promise<void> {
  error.value = ''

  try {
    await http.put(`/api/work-requests/${request.id}/status`, { status: 'zugeteilt' })
    await Promise.all([reload(), auth.isForeman ? workshop.loadOverview() : Promise.resolve()])
  } catch (e) {
    error.value = errorMessage(e)
  }
}
</script>

<template>
  <div>
    <h1>Brauche Arbeit</h1>
    <p class="muted">
      Melde, ab wann du wieder eine neue Arbeit brauchst. Frühestens einen Tag im Voraus, damit noch
      ein Kunde in die Werkstatt geholt werden kann.
    </p>

    <p v-if="error" class="error">{{ error }}</p>

    <section v-if="workshop.myRequest" class="card active">
      <h2>Deine Anforderung steht</h2>
      <p>
        Du brauchst neue Arbeit ab
        <strong>{{ formatWhen(workshop.myRequest.neededAt) }}</strong
        >.
      </p>
      <p v-if="workshop.myRequest.note" class="muted">{{ workshop.myRequest.note }}</p>
      <button type="button" class="secondary" @click="withdraw">Zurückziehen</button>
    </section>

    <section class="card">
      <h2>{{ workshop.myRequest ? 'Anforderung ändern' : 'Neue Arbeit anfordern' }}</h2>

      <div class="quick">
        <button
          v-for="option in quickOptions"
          :key="option.value"
          type="button"
          class="secondary small"
          :class="{ picked: neededAt === option.value }"
          @click="neededAt = option.value"
        >
          {{ option.label }}
        </button>
      </div>

      <form class="form" @submit.prevent="submit">
        <div>
          <label for="neededAt">Ab wann</label>
          <input
            id="neededAt"
            v-model="neededAt"
            type="datetime-local"
            :min="toDateTimeInput(earliest)"
            required
          />
        </div>

        <div>
          <label for="note">Notiz (optional)</label>
          <input
            id="note"
            v-model="note"
            type="text"
            placeholder="z. B. Traktor ist bis dahin fertig"
          />
        </div>

        <div class="actions">
          <button type="submit" :disabled="busy">Melden</button>
        </div>
      </form>
    </section>

    <section v-if="auth.isForeman" class="card">
      <h2>Offene Anforderungen</h2>

      <table v-if="all.length">
        <thead>
          <tr>
            <th>Arbeiter</th>
            <th>Braucht Arbeit ab</th>
            <th>Notiz</th>
            <th></th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="request in all" :key="request.id">
            <td>{{ request.user.fullName }}</td>
            <td>{{ formatWhen(request.neededAt) }}</td>
            <td class="muted">{{ request.note ?? '–' }}</td>
            <td class="right">
              <button type="button" class="secondary small" @click="markFulfilled(request)">
                Arbeit zugeteilt
              </button>
            </td>
          </tr>
        </tbody>
      </table>

      <p v-else class="muted">Zurzeit braucht niemand neue Arbeit.</p>
    </section>
  </div>
</template>

<style scoped>
section {
  margin-bottom: 1rem;
}

.active {
  border-color: color-mix(in srgb, var(--primary) 40%, var(--border));
}

.active p {
  margin: 0 0 0.5rem;
}

.quick {
  display: flex;
  gap: 0.5rem;
  flex-wrap: wrap;
  margin-bottom: 1rem;
}

.quick .picked {
  border-color: var(--primary);
  color: var(--primary);
}

.small {
  font-size: 0.8125rem;
  padding: 0.3rem 0.6rem;
}

.form {
  display: grid;
  grid-template-columns: repeat(2, 1fr);
  gap: 0.9rem;
  align-items: end;
}

.actions {
  grid-column: 1 / -1;
}

.right {
  text-align: right;
}

@media (max-width: 720px) {
  .form {
    grid-template-columns: 1fr;
  }
}
</style>
