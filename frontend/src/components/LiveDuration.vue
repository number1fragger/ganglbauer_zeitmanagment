<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import type { Job } from '@/api/types'
import { useNow } from '@/composables/useNow'
import { formatClock, formatDuration } from '@/utils/time'

/**
 * Ist-Zeit einer Arbeit. Laeuft gerade ein Abschnitt, tickt die Anzeige
 * jede Sekunde weiter (Basis: Serverwert + Zeit seit dem Laden).
 */
const props = defineProps<{ job: Job; clock?: boolean }>()

const now = useNow(1000)
const loadedAt = ref(Date.now())
// Neue Serverdaten: ab hier weiterzaehlen, sonst wuerde doppelt gezaehlt.
watch(
  () => [props.job.actualSeconds, props.job.running],
  () => (loadedAt.value = Date.now()),
)

const seconds = computed(() => {
  if (!props.job.running) return props.job.actualSeconds
  return (
    props.job.actualSeconds + Math.max(0, Math.floor((now.value.getTime() - loadedAt.value) / 1000))
  )
})
</script>

<template>
  <span class="tabular">{{ clock ? formatClock(seconds) : formatDuration(seconds) }}</span>
</template>
