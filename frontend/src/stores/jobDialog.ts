import { ref } from 'vue'
import { defineStore } from 'pinia'

export interface JobPrefill {
  assigneeId?: number
  /** "YYYY-MM-DDTHH:mm" in Ortszeit */
  startsAt?: string
}

/**
 * Steuert den Dialog "Arbeit anlegen / bearbeiten" (Figma-Screen 02).
 * Der Dialog haengt einmal in App.vue; jede Seite kann ihn oeffnen.
 * Nach dem Speichern zaehlt `version` hoch – Seiten laden dann neu.
 */
export const useJobDialogStore = defineStore('jobDialog', () => {
  const open = ref(false)
  const jobId = ref<number | null>(null)
  const prefill = ref<JobPrefill>({})
  const version = ref(0)

  function create(values: JobPrefill = {}): void {
    jobId.value = null
    prefill.value = values
    open.value = true
  }

  function edit(id: number): void {
    jobId.value = id
    prefill.value = {}
    open.value = true
  }

  function close(): void {
    open.value = false
  }

  function saved(): void {
    version.value++
    open.value = false
  }

  return { open, jobId, prefill, version, create, edit, close, saved }
})
