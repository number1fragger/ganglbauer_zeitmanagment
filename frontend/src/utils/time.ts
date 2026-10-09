/** Datums- und Zeithelfer – alles in lokaler Zeit (de-AT). */

export const DAY_MS = 86_400_000
const LOCALE = 'de-AT'

export function startOfDay(date: Date): Date {
  return new Date(date.getFullYear(), date.getMonth(), date.getDate())
}

export function addDays(date: Date, days: number): Date {
  return new Date(date.getFullYear(), date.getMonth(), date.getDate() + days, date.getHours(), date.getMinutes())
}

export function addMinutes(date: Date, minutes: number): Date {
  return new Date(date.getTime() + minutes * 60_000)
}

/** Montag der Woche, 00:00 */
export function startOfWeek(date: Date): Date {
  const day = startOfDay(date)
  return addDays(day, -((day.getDay() + 6) % 7))
}

export function startOfMonth(date: Date): Date {
  return new Date(date.getFullYear(), date.getMonth(), 1)
}

export function sameDay(a: Date, b: Date): boolean {
  return a.toDateString() === b.toDateString()
}

export function isWeekend(date: Date): boolean {
  return date.getDay() === 0 || date.getDay() === 6
}

/** Naechster Werktag (Mo–Fr) nach dem angegebenen Tag. */
export function nextWorkday(date: Date): Date {
  let next = addDays(startOfDay(date), 1)
  while (isWeekend(next)) next = addDays(next, 1)
  return next
}

/** ISO-Kalenderwoche */
export function isoWeek(date: Date): number {
  const thursday = addDays(startOfWeek(date), 3)
  const firstThursday = addDays(startOfWeek(new Date(thursday.getFullYear(), 0, 4)), 3)
  return 1 + Math.round((thursday.getTime() - firstThursday.getTime()) / (7 * DAY_MS))
}

/** "2026-10-09" in lokaler Zeit */
export function toDateKey(date: Date): string {
  const pad = (n: number) => String(n).padStart(2, '0')
  return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}`
}

export function fromDateKey(key: string | undefined | null): Date | null {
  const match = /^(\d{4})-(\d{2})-(\d{2})$/.exec(key ?? '')
  return match ? new Date(Number(match[1]), Number(match[2]) - 1, Number(match[3])) : null
}

/** Wert fuer <input type="datetime-local"> */
export function toLocalInput(date: Date): string {
  const pad = (n: number) => String(n).padStart(2, '0')
  return `${toDateKey(date)}T${pad(date.getHours())}:${pad(date.getMinutes())}`
}

/** Stunden seit Mitternacht als Kommazahl, z. B. 9.5 fuer 09:30 */
export function hourOfDay(date: Date): number {
  return date.getHours() + date.getMinutes() / 60
}

/** 150 -> "2,5 h" */
export function formatHours(minutes: number): string {
  return `${(minutes / 60).toLocaleString(LOCALE, { minimumFractionDigits: 1, maximumFractionDigits: 1 })} h`
}

/** 90 -> "+1,5 h", -30 -> "−0,5 h" */
export function formatSignedHours(minutes: number): string {
  const sign = minutes > 0 ? '+' : minutes < 0 ? '−' : ''
  return sign + formatHours(Math.abs(minutes))
}

export function formatTime(date: Date): string {
  return date.toLocaleTimeString(LOCALE, { hour: '2-digit', minute: '2-digit' })
}

/** "Montag, 28. September 2026" */
export function formatLongDate(date: Date): string {
  return date.toLocaleDateString(LOCALE, { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' })
}

/** "Di, 29.09." */
export function formatDayMonth(date: Date): string {
  const dayMonth = date.toLocaleDateString(LOCALE, { day: '2-digit', month: '2-digit' })
  return `${weekdayName(date, 'short')}, ${dayMonth}`
}

export function formatMonth(date: Date): string {
  return date.toLocaleDateString(LOCALE, { month: 'long', year: 'numeric' })
}

export function weekdayName(date: Date, style: 'long' | 'short' = 'long'): string {
  return date.toLocaleDateString(LOCALE, { weekday: style }).replace('.', '')
}

/** "heute", "morgen", "übermorgen" oder null */
export function relativeDay(date: Date, now = new Date()): string | null {
  const days = Math.round((startOfDay(date).getTime() - startOfDay(now).getTime()) / DAY_MS)
  return ['heute', 'morgen', 'übermorgen'][days] ?? null
}

/** "früh" vor 12 Uhr, sonst "Nachmittag" */
export function partOfDay(date: Date): string {
  return date.getHours() < 12 ? 'früh' : 'Nachmittag'
}

/** "morgen früh", "Montag Nachmittag" – so wie Arbeiter ihre Anfrage stellen. */
export function describeSlot(date: Date, now = new Date()): string {
  return `${relativeDay(date, now) ?? weekdayName(date)} ${partOfDay(date)}`
}

/** "Montag, 07:00" bzw. "heute, 14:00"; liegt es mehr als eine Woche weg, mit Datum. */
export function describeMoment(date: Date, now = new Date()): string {
  const days = (startOfDay(date).getTime() - startOfDay(now).getTime()) / DAY_MS
  const day = relativeDay(date, now) ?? (days < 7 ? weekdayName(date) : formatDayMonth(date))
  return `${day}, ${formatTime(date)}`
}

/** "Mi 14:00" – kompakte Form fuer Spaltenkoepfe. */
export function describeShort(date: Date, now = new Date()): string {
  const days = (startOfDay(date).getTime() - startOfDay(now).getTime()) / DAY_MS
  const day = days === 0 ? 'heute' : days < 7 ? weekdayName(date, 'short') : formatDayMonth(date)
  return `${day} ${formatTime(date)}`
}

/** 22500 Sekunden -> "6 h 15 min", 300 -> "5 min", 0 -> "0 min" */
export function formatDuration(seconds: number): string {
  const minutes = Math.floor(Math.max(0, seconds) / 60)
  const h = Math.floor(minutes / 60)
  const m = minutes % 60
  if (h === 0) return `${m} min`
  return m === 0 ? `${h} h` : `${h} h ${m} min`
}

/** Live-Uhr: 3725 -> "1:02:05" */
export function formatClock(seconds: number): string {
  const s = Math.max(0, Math.floor(seconds))
  const pad = (n: number) => String(n).padStart(2, '0')
  return `${Math.floor(s / 3600)}:${pad(Math.floor((s % 3600) / 60))}:${pad(s % 60)}`
}

/** Termin als Text; mehrtaegig mit beiden Tagen: "Mo 28.09., 09:00 – Di 29.09., 16:00" */
export function formatRange(start: Date, end: Date): string {
  if (sameDay(start, end)) return `${formatDayMonth(start)}, ${formatTime(start)} – ${formatTime(end)}`
  return `${formatDayMonth(start)}, ${formatTime(start)} – ${formatDayMonth(end)}, ${formatTime(end)}`
}

/** Anzahl Kalendertage, ueber die sich ein Termin erstreckt. */
export function spanDays(start: Date, end: Date): number {
  return Math.round((startOfDay(new Date(end.getTime() - 1)).getTime() - startOfDay(start).getTime()) / DAY_MS) + 1
}

/** Minuten aus "h" und "min" Eingaben; leer -> null */
export function minutesFrom(hours: number | string | null, minutes: number | string | null): number | null {
  const h = Number(hours || 0)
  const m = Number(minutes || 0)
  const total = Math.round(h * 60 + m)
  return total > 0 ? total : null
}

/** "Mo 28.09., 09:00" */
export function formatDateTimeShort(date: Date): string {
  return `${formatDayMonth(date)}, ${formatTime(date)}`
}
