import type { Priority } from '@/api/types'

/** 135 -> "2:15 h" */
export function formatDuration(minutes: number): string {
  const sign = minutes < 0 ? '-' : ''
  const abs = Math.abs(Math.round(minutes))
  const h = Math.floor(abs / 60)
  const m = abs % 60

  return `${sign}${h}:${String(m).padStart(2, '0')} h`
}

/** 135 -> "2,25" (Dezimalstunden fuer die Abrechnung) */
export function decimalHours(minutes: number): string {
  return (minutes / 60).toFixed(2).replace('.', ',')
}

export function formatTime(iso: string | null): string {
  if (!iso) return '–'

  return new Date(iso).toLocaleTimeString('de-AT', { hour: '2-digit', minute: '2-digit' })
}

export function formatDate(iso: string | null): string {
  if (!iso) return '–'

  return new Date(iso).toLocaleDateString('de-AT', {
    weekday: 'short',
    day: '2-digit',
    month: '2-digit',
    year: 'numeric',
  })
}

export function formatDateTime(iso: string | null): string {
  if (!iso) return '–'

  const date = new Date(iso)

  return `${date.toLocaleDateString('de-AT', {
    weekday: 'short',
    day: '2-digit',
    month: '2-digit',
  })}, ${date.toLocaleTimeString('de-AT', { hour: '2-digit', minute: '2-digit' })}`
}

/** "heute 14:30" / "morgen 07:00" / "Do, 22.01., 07:00" */
export function formatWhen(iso: string | null): string {
  if (!iso) return '–'

  const date = new Date(iso)
  const time = date.toLocaleTimeString('de-AT', { hour: '2-digit', minute: '2-digit' })
  const days = daysFromToday(date)

  if (days === 0) return `heute ${time}`
  if (days === 1) return `morgen ${time}`
  if (days === 2) return `übermorgen ${time}`

  return `${date.toLocaleDateString('de-AT', {
    weekday: 'short',
    day: '2-digit',
    month: '2-digit',
  })}, ${time}`
}

function daysFromToday(date: Date): number {
  const a = new Date(date.getFullYear(), date.getMonth(), date.getDate())
  const now = new Date()
  const b = new Date(now.getFullYear(), now.getMonth(), now.getDate())

  return Math.round((a.getTime() - b.getTime()) / 86_400_000)
}

export function toDateInput(date: Date): string {
  const offset = date.getTimezoneOffset()

  return new Date(date.getTime() - offset * 60_000).toISOString().slice(0, 10)
}

export function toDateTimeInput(value: string | Date): string {
  const date = typeof value === 'string' ? new Date(value) : value
  const offset = date.getTimezoneOffset()

  return new Date(date.getTime() - offset * 60_000).toISOString().slice(0, 16)
}

/**
 * Prioritaeten mit den Figma-Farben: hoch rot, mittel orange, nieder gruen.
 * "Dringend" gibt es zusaetzlich (dunkelrot) fuer echte Notfaelle.
 */
export const priorities: { value: Priority; label: string; color: string }[] = [
  { value: 'dringend', label: 'Dringend', color: '#991b1b' },
  { value: 'hoch', label: 'Hoch', color: '#dc2626' },
  { value: 'normal', label: 'Mittel', color: '#f59e0b' },
  { value: 'niedrig', label: 'Nieder', color: '#16a34a' },
]

export function priorityLabel(priority: Priority): string {
  return priorities.find((p) => p.value === priority)?.label ?? priority
}

export function priorityColor(priority: Priority): string {
  return priorities.find((p) => p.value === priority)?.color ?? '#64748b'
}

export const statusLabels: Record<string, string> = {
  offen: 'Offen',
  in_arbeit: 'In Arbeit',
  erledigt: 'Erledigt',
}

/** 150 -> "2,5 h" – Dezimalstunden wie im Figma-Entwurf. */
export function formatHours(minutes: number, unit = 'h'): string {
  return `${(minutes / 60).toFixed(1).replace('.', ',')} ${unit}`
}

/** "Montag, 07:00" bzw. "heute 11:00" – fuer "braucht Arbeit: …" */
export function formatWeekdayTime(iso: string | null, short = false): string {
  if (!iso) return '–'

  const date = new Date(iso)
  const time = date.toLocaleTimeString('de-AT', { hour: '2-digit', minute: '2-digit' })
  const days = daysFromToday(date)

  if (days === 0) return `heute ${time}`
  if (days === 1) return `morgen ${time}`
  if (days > 1 && days < 7) {
    return `${date.toLocaleDateString('de-AT', { weekday: short ? 'short' : 'long' })}${short ? '' : ','} ${time}`
  }

  return `${date.toLocaleDateString('de-AT', { day: '2-digit', month: '2-digit' })}, ${time}`
}
