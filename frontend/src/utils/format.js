/** Formatierung fuer Zeiten, Daten und Prioritaeten (de-AT). */

const time = (date) => date.toLocaleTimeString('de-AT', { hour: '2-digit', minute: '2-digit' })

function daysFromToday(date) {
  const a = new Date(date.getFullYear(), date.getMonth(), date.getDate())
  const now = new Date()
  const b = new Date(now.getFullYear(), now.getMonth(), now.getDate())

  return Math.round((a - b) / 86_400_000)
}

/** 150 -> "2,5 h" */
export function formatHours(minutes, unit = 'h') {
  return `${(minutes / 60).toFixed(1).replace('.', ',')} ${unit}`
}

/** 135 -> "2,25" (fuer den CSV-Export) */
export function decimalHours(minutes) {
  return (minutes / 60).toFixed(2).replace('.', ',')
}

export function formatTime(iso) {
  return iso ? time(new Date(iso)) : '–'
}

export function formatDate(iso) {
  return iso
    ? new Date(iso).toLocaleDateString('de-AT', {
        weekday: 'short',
        day: '2-digit',
        month: '2-digit',
        year: 'numeric',
      })
    : '–'
}

export function formatDateTime(iso) {
  if (!iso) return '–'
  const date = new Date(iso)

  return `${date.toLocaleDateString('de-AT', { weekday: 'short', day: '2-digit', month: '2-digit' })}, ${time(date)}`
}

/** "heute 14:30" / "morgen 07:00" / "übermorgen 07:00" / "Do, 22.01., 07:00" */
export function formatWhen(iso) {
  if (!iso) return '–'
  const date = new Date(iso)
  const days = daysFromToday(date)

  if (days === 0) return `heute ${time(date)}`
  if (days === 1) return `morgen ${time(date)}`
  if (days === 2) return `übermorgen ${time(date)}`

  return formatDateTime(iso)
}

/** "heute 11:00" / "Montag, 07:00" (short: "Mo 07:00") */
export function formatWeekdayTime(iso, short = false) {
  if (!iso) return '–'
  const date = new Date(iso)
  const days = daysFromToday(date)

  if (days === 0) return `heute ${time(date)}`
  if (days === 1) return `morgen ${time(date)}`
  if (days > 1 && days < 7) {
    const weekday = date.toLocaleDateString('de-AT', { weekday: short ? 'short' : 'long' })
    return short ? `${weekday.replace('.', '')} ${time(date)}` : `${weekday}, ${time(date)}`
  }

  return `${date.toLocaleDateString('de-AT', { day: '2-digit', month: '2-digit' })}, ${time(date)}`
}

/** Date oder ISO-String -> "YYYY-MM-DDTHH:mm" fuer <input type="datetime-local"> */
export function toDateTimeInput(value) {
  const date = typeof value === 'string' ? new Date(value) : value
  const local = new Date(date.getTime() - date.getTimezoneOffset() * 60_000)

  return local.toISOString().slice(0, 16)
}

/** Prioritaeten wie im Figma-Entwurf. `soft` = heller Hintergrund. */
export const priorities = [
  { value: 'hoch', label: 'Hoch', color: '#dc2626', soft: '#fef2f2' },
  { value: 'normal', label: 'Mittel', color: '#f59e0b', soft: '#fff8e8' },
  { value: 'niedrig', label: 'Nieder', color: '#16a34a', soft: '#e7f6ec' },
]

/** Aeltere Arbeiten koennen "dringend" sein – die zaehlen als "Hoch". */
const priorityOf = (value) =>
  priorities.find((p) => p.value === (value === 'dringend' ? 'hoch' : value))

export const priorityLabel = (value) => priorityOf(value)?.label ?? value
export const priorityColor = (value) => priorityOf(value)?.color ?? '#9ca3af'

export const statusLabels = { offen: 'Offen', in_arbeit: 'In Arbeit', erledigt: 'Erledigt' }
