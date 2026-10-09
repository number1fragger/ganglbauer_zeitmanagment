import { onBeforeUnmount, onMounted, ref } from 'vue'

/** Aktuelle Uhrzeit als reaktiver Wert, aktualisiert im angegebenen Takt. */
export function useNow(intervalMs = 60_000) {
  const now = ref(new Date())
  let timer: ReturnType<typeof setInterval> | undefined

  onMounted(() => {
    timer = setInterval(() => (now.value = new Date()), intervalMs)
  })
  onBeforeUnmount(() => clearInterval(timer))

  return now
}
