import { shallowRef } from 'vue'

export interface ConfirmRequest {
  title: string
  message?: string
  confirmLabel?: string
  tone?: 'primary' | 'danger' | 'success'
  /** Weitere Zeilen, z. B. was beim Loeschen erhalten bleibt */
  details?: string[]
  /** Bei unwiderruflichen Aktionen: dieser Text muss zur Bestaetigung eingetippt werden */
  requireText?: string
  resolve: (ok: boolean) => void
}

/** Ein offener Bestaetigungsdialog (statt window.confirm, passend zum Design). */
export const pendingConfirm = shallowRef<ConfirmRequest | null>(null)

export function confirmAction(options: Omit<ConfirmRequest, 'resolve'>): Promise<boolean> {
  pendingConfirm.value?.resolve(false)
  return new Promise((resolve) => {
    pendingConfirm.value = {
      ...options,
      resolve: (ok) => {
        pendingConfirm.value = null
        resolve(ok)
      },
    }
  })
}
