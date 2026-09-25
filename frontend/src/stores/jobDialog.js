import { ref } from 'vue'
import { defineStore } from 'pinia'

/**
 * Steuert den Dialog "Arbeit anlegen / bearbeiten". Er haengt einmal in
 * App.vue; nach jeder Aenderung zaehlt `version` hoch, Seiten laden dann neu.
 */
export const useJobDialogStore = defineStore('jobDialog', () => {
  const open = ref(false)
  const jobId = ref(null)
  /** { assigneeId, startsAt: "YYYY-MM-DDTHH:mm" } */
  const prefill = ref({})
  const version = ref(0)

  function create(values = {}) {
    jobId.value = null
    prefill.value = values
    open.value = true
  }

  function edit(id) {
    jobId.value = id
    prefill.value = {}
    open.value = true
  }

  function close() {
    open.value = false
  }

  /** Nach Speichern, Loeschen oder Verschieben per Drag & Drop. */
  function changed() {
    version.value++
    open.value = false
  }

  return { open, jobId, prefill, version, create, edit, close, changed }
})
