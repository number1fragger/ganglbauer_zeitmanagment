<script setup lang="ts">
import { computed, reactive, ref } from 'vue'
import { jobsApi } from '@/api'
import { errorMessage } from '@/api/http'
import type { Job, UserRef } from '@/api/types'
import { toast } from '@/composables/useToast'
import { DEFAULT_SLOT_MINUTES } from '@/utils/calendar'
import { addMinutes, nextWorkday, toDateKey } from '@/utils/time'
import BaseModal from '../BaseModal.vue'

/**
 * "Einplanen" ohne Drag & Drop (Touch, Tastatur): Tag, Uhrzeit und optional
 * Ende waehlen. Ohne Ende bekommt der Termin den technischen Standardblock.
 */
const props = defineProps<{ job: Job; workers: UserRef[]; date?: Date }>()
const emit = defineEmits<{ close: []; scheduled: [job: Job] }>()

const today = new Date()
const initialDay = props.date ?? (today.getHours() >= 15 ? nextWorkday(today) : today)

const form = reactive({
  day: toDateKey(initialDay),
  start: '07:00',
  end: '',
  assigneeId: props.job.assignee?.id ?? null,
})

const busy = ref(false)
const error = ref('')

const startsAt = computed(() => new Date(`${form.day}T${form.start}`))
const endsAt = computed(() => (form.end ? new Date(`${form.day}T${form.end}`) : null))

const invalid = computed(() => {
  if (!form.day || !form.start || Number.isNaN(startsAt.value.getTime()))
    return 'Bitte Tag und Uhrzeit wählen.'
  if (endsAt.value && endsAt.value <= startsAt.value) return 'Das Ende muss nach dem Beginn liegen.'
  return null
})

async function save(): Promise<void> {
  if (invalid.value) return
  busy.value = true
  error.value = ''
  try {
    const job = await jobsApi.schedule(props.job.id, {
      startsAt: startsAt.value.toISOString(),
      endsAt: endsAt.value?.toISOString() ?? null,
      assigneeId: form.assigneeId,
      changeAssignee: true,
    })
    toast(`„${job.title}“ eingeplant.`, 'success')
    emit('scheduled', job)
  } catch (e) {
    error.value = errorMessage(e)
  } finally {
    busy.value = false
  }
}

const endHint = computed(() =>
  form.end
    ? ''
    : `Ohne Ende wird ein Kalenderblock bis ${addMinutes(startsAt.value, DEFAULT_SLOT_MINUTES).toLocaleTimeString('de-AT', { hour: '2-digit', minute: '2-digit' })} angezeigt.`,
)
</script>

<template>
  <BaseModal title="Einplanen" :subtitle="job.title" :width="440" @close="emit('close')">
    <form class="plan" @submit.prevent="save">
      <label class="field">
        <span class="field__label">Tag</span>
        <input v-model="form.day" class="input" type="date" required />
      </label>
      <div class="plan__pair">
        <label class="field">
          <span class="field__label">Beginn</span>
          <input v-model="form.start" class="input" type="time" step="900" required />
        </label>
        <label class="field">
          <span class="field__label">Ende <small>(optional)</small></span>
          <input v-model="form.end" class="input" type="time" step="900" :min="form.start" />
        </label>
      </div>
      <p v-if="endHint" class="field__hint">{{ endHint }}</p>
      <label class="field">
        <span class="field__label">Zuständig</span>
        <select v-model="form.assigneeId" class="input">
          <option :value="null">Noch niemand</option>
          <option v-for="w in workers" :key="w.id" :value="w.id">{{ w.fullName }}</option>
        </select>
      </label>

      <p v-if="invalid && (form.end || !form.start)" class="form-error">{{ invalid }}</p>
      <p v-if="error" class="form-error" role="alert">{{ error }}</p>

      <footer class="plan__actions">
        <button type="button" class="btn btn--outline" @click="emit('close')">Abbrechen</button>
        <button class="btn btn--primary" :disabled="busy || !!invalid">Einplanen</button>
      </footer>
    </form>
  </BaseModal>
</template>

<style scoped>
.plan {
  display: grid;
  gap: 14px;
}

.plan__pair {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 12px;
}

.field__label small {
  color: var(--faint);
  font-weight: 500;
}

.plan__actions {
  display: flex;
  justify-content: flex-end;
  gap: 10px;
  padding-top: 8px;
}

@media (max-width: 699px) {
  .plan__actions .btn {
    flex: 1;
    height: 44px;
  }
}
</style>
