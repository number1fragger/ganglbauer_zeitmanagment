<script setup lang="ts">
import { computed, ref } from 'vue'
import { jobsApi } from '@/api'
import { errorMessage } from '@/api/http'
import type { FollowUp } from '@/api/types'
import { toast } from '@/composables/useToast'
import { formatTime } from '@/utils/time'
import AppIcon from './AppIcon.vue'
import BaseModal from './BaseModal.vue'

/**
 * Vorschau der Umplanung nach frueherem/spaeterem Abschluss. Es wird
 * immer ein zusammenhaengender Anfang der Kette verschoben – wer eine
 * Arbeit abwaehlt, waehlt damit auch die folgenden ab.
 */
const props = defineProps<{ followUp: FollowUp; title?: string }>()
const emit = defineEmits<{ close: []; applied: [] }>()

const count = ref(props.followUp.moves.filter((m) => m.recommended).length)
const busy = ref(false)
const error = ref('')

function toggle(index: number): void {
  count.value = index < count.value ? index : index + 1
}

const minutes = computed(() => Math.abs(props.followUp.shiftMinutes))
const earlier = computed(() => props.followUp.direction === 'earlier')
const t = (iso: string) => formatTime(new Date(iso))

async function apply(): Promise<void> {
  busy.value = true
  error.value = ''
  try {
    const ids = props.followUp.moves.slice(0, count.value).map((m) => m.jobId)
    const result = await jobsApi.applyFollowUp(props.followUp.jobId, ids)
    toast(
      result.moved.length
        ? `${result.moved.length} Termin(e) verschoben.`
        : 'Termin der abgeschlossenen Arbeit angepasst.',
      'success',
    )
    emit('applied')
  } catch (e) {
    error.value = errorMessage(e)
  } finally {
    busy.value = false
  }
}
</script>

<template>
  <BaseModal
    :title="
      earlier
        ? 'Früher fertig – Folgetermine vorziehen?'
        : 'Später fertig – Folgetermine verschieben?'
    "
    :subtitle="`${title ?? followUp.title ?? 'Die Arbeit'} wurde ${minutes} Minuten ${earlier ? 'früher' : 'später'} als im Kalender eingetragen abgeschlossen (${t(followUp.actualEnd)} statt ${t(followUp.scheduledEnd)}).`"
    :width="560"
    @close="emit('close')"
  >
    <ul class="moves">
      <li
        v-for="(move, index) in followUp.moves"
        :key="move.jobId"
        class="move"
        :class="{ 'move--off': index >= count }"
      >
        <label class="move__check">
          <input type="checkbox" :checked="index < count" @change="toggle(index)" />
          <span class="move__text">
            <strong>{{ move.title }}</strong>
            <small v-if="move.customer">{{ move.customer }}</small>
          </span>
        </label>
        <span class="move__times tabular">
          <s>{{ t(move.from.startsAt) }}–{{ t(move.from.endsAt) }}</s>
          <AppIcon name="arrow-right" :size="14" />
          <b>{{ t(move.to.startsAt) }}–{{ t(move.to.endsAt) }}</b>
        </span>
        <span v-if="move.warning" class="move__warning"
          ><AppIcon name="alert" :size="13" /> {{ move.warning }}</span
        >
      </li>
    </ul>

    <p v-for="warning in followUp.warnings" :key="warning" class="form-note follow__note">
      {{ warning }}
    </p>
    <p class="follow__hint">
      Nur noch nicht begonnene Arbeiten werden verschoben. Bitte bei Kundenterminen den Kunden
      informieren.
    </p>
    <p v-if="error" class="form-error">{{ error }}</p>

    <footer class="follow__actions">
      <button class="btn btn--outline" @click="emit('close')">Nicht verschieben</button>
      <button class="btn btn--primary" :disabled="busy" @click="apply">
        {{ count ? `${count} Termin(e) verschieben` : 'Nur Termin kürzen' }}
      </button>
    </footer>
  </BaseModal>
</template>

<style scoped>
.moves {
  display: grid;
  gap: 8px;
  margin: 0;
  padding: 0;
  list-style: none;
}

.move {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 8px 16px;
  padding: 12px 14px;
  border: 1px solid var(--border);
  border-radius: var(--radius);
  background: var(--surface-alt);
}

.move--off {
  opacity: 0.6;
}

.move__check {
  display: flex;
  flex: 1;
  align-items: center;
  gap: 10px;
  min-width: 180px;
  cursor: pointer;
}

.move__check input {
  width: 18px;
  height: 18px;
  accent-color: var(--primary);
}

.move__text {
  display: flex;
  flex-direction: column;
}

.move__text small {
  color: var(--muted);
}

.move__times {
  display: flex;
  align-items: center;
  gap: 6px;
  font-size: 12px;
}

.move__times s {
  color: var(--faint);
}

.move__times b {
  color: var(--primary);
}

.move__warning {
  display: flex;
  flex-basis: 100%;
  align-items: center;
  gap: 6px;
  color: var(--warning-ink);
  font-size: 11px;
  font-weight: 500;
}

.follow__note {
  margin-top: 10px;
}

.follow__hint {
  margin-top: 14px;
  color: var(--muted);
  font-size: 12px;
}

.follow__actions {
  display: flex;
  flex-wrap: wrap;
  justify-content: flex-end;
  gap: 10px;
  margin-top: 22px;
}
</style>
