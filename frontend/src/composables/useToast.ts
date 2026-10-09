import { reactive } from 'vue'

export type ToastTone = 'info' | 'success' | 'warning' | 'error'

export interface Toast {
  id: number
  tone: ToastTone
  message: string
  action?: { label: string; run: () => void }
}

/** Kurze Rueckmeldungen unten rechts (mobil: unten mittig). */
export const toasts = reactive<Toast[]>([])
let next = 1

export function toast(
  message: string,
  tone: ToastTone = 'info',
  action?: Toast['action'],
  ms = 4500,
): void {
  const id = next++
  toasts.push({ id, tone, message, action })
  window.setTimeout(() => dismiss(id), action ? ms + 3000 : ms)
}

export function dismiss(id: number): void {
  const index = toasts.findIndex((t) => t.id === id)
  if (index >= 0) toasts.splice(index, 1)
}
