/**
 * Rechnen mit den regulaeren Arbeitszeiten – dieselben Regeln wie im Backend
 * (App\Util\WorkingHours). Die Zeiten selbst kommen vom Server
 * (GET /api/working-hours); hier stehen nur die Rechenregeln.
 *
 * Bewusst ohne Imports, damit die Datei mit `node --test` geprueft werden kann.
 *
 *  - Dauer eines Termins = Minuten, die in Arbeitsfenster fallen (Mittagspause,
 *    Abende und Wochenenden zaehlen nicht). Beginnt der Termin ausserhalb der
 *    Arbeitszeit, zaehlt die Uhrzeit.
 *  - Ende aus Beginn + Dauer = fruehester Zeitpunkt, an dem die Dauer erreicht
 *    ist; Pausen und arbeitsfreie Zeit werden uebersprungen.
 */

export interface WorkingHoursConfig {
  timezone: string
  /** ISO-Wochentag "1" (Montag) bis "7" (Sonntag); null = arbeitsfrei */
  days: Record<string, { start: string; end: string } | null>
  breaks: { start: string; end: string; label: string }[]
  summary: string
}

type Window = [Date, Date]

const MAX_DAYS = 400
const WEEKDAYS = ['Sonntag', 'Montag', 'Dienstag', 'Mittwoch', 'Donnerstag', 'Freitag', 'Samstag']

/** "07:30" -> 7.5 */
export function hourOf(time: string): number {
  const [hours = 0, minutes = 0] = time.split(':').map(Number)
  return hours + minutes / 60
}

function at(day: Date, time: string): Date {
  const [hours = 0, minutes = 0] = time.split(':').map(Number)
  return new Date(day.getFullYear(), day.getMonth(), day.getDate(), hours, minutes)
}

function isoWeekday(date: Date): string {
  return String(date.getDay() === 0 ? 7 : date.getDay())
}

function nextDay(day: Date): Date {
  return new Date(day.getFullYear(), day.getMonth(), day.getDate() + 1)
}

function startOf(day: Date): Date {
  return new Date(day.getFullYear(), day.getMonth(), day.getDate())
}

/** Arbeitsfenster eines Tages ohne Pausen. */
export function windowsOn(config: WorkingHoursConfig, day: Date): Window[] {
  const hours = config.days[isoWeekday(day)]
  if (!hours) return []

  let windows: Window[] = [[at(day, hours.start), at(day, hours.end)]]
  for (const pause of config.breaks) {
    const from = at(day, pause.start)
    const to = at(day, pause.end)
    windows = windows.flatMap(([start, end]): Window[] => {
      if (to <= start || from >= end) return [[start, end]]
      const parts: Window[] = []
      if (from > start) parts.push([start, from])
      if (to < end) parts.push([to, end])
      return parts
    })
  }
  return windows
}

export function isWorkingStart(config: WorkingHoursConfig, moment: Date): boolean {
  return windowsOn(config, moment).some(([start, end]) => moment >= start && moment < end)
}

export function isWorkingEnd(config: WorkingHoursConfig, moment: Date): boolean {
  return windowsOn(config, moment).some(([start, end]) => moment > start && moment <= end)
}

export function workingMinutesBetween(config: WorkingHoursConfig, from: Date, to: Date): number {
  let ms = 0
  let day = startOf(from)
  for (let i = 0; day < to && i < MAX_DAYS; i++, day = nextDay(day)) {
    for (const [start, end] of windowsOn(config, day)) {
      const overlap = Math.min(end.getTime(), to.getTime()) - Math.max(start.getTime(), from.getTime())
      if (overlap > 0) ms += overlap
    }
  }
  return Math.floor(ms / 60_000)
}

/** Dauer eines Termins in Minuten (Arbeitszeit, bzw. Uhrzeit ausserhalb der Arbeitszeit). */
export function durationMinutes(config: WorkingHoursConfig, startsAt: Date, endsAt: Date): number {
  if (!isWorkingStart(config, startsAt)) {
    return Math.max(0, Math.floor((endsAt.getTime() - startsAt.getTime()) / 60_000))
  }
  return workingMinutesBetween(config, startsAt, endsAt)
}

/** Ende aus Beginn und Dauer (Gegenstueck zu durationMinutes). */
export function endFor(config: WorkingHoursConfig, startsAt: Date, minutes: number): Date {
  if (minutes <= 0) throw new Error('Die Dauer muss positiv sein.')
  if (!isWorkingStart(config, startsAt)) return new Date(startsAt.getTime() + minutes * 60_000)

  let left = minutes * 60_000
  let day = startOf(startsAt)
  for (let i = 0; i < MAX_DAYS; i++, day = nextDay(day)) {
    for (const [start, end] of windowsOn(config, day)) {
      const from = Math.max(start.getTime(), startsAt.getTime())
      const available = end.getTime() - from
      if (available <= 0) continue
      if (left <= available) return new Date(from + left)
      left -= available
    }
  }
  throw new Error('Die Dauer ist zu lang.')
}

function describeOutside(config: WorkingHoursConfig, moment: Date, asStart: boolean): string {
  const hours = config.days[isoWeekday(moment)]
  const weekday = WEEKDAYS[moment.getDay()]
  if (!hours) return `an einem arbeitsfreien Tag (${weekday})`

  for (const pause of config.breaks) {
    const from = at(moment, pause.start)
    const to = at(moment, pause.end)
    const inside = asStart ? moment >= from && moment < to : moment > from && moment <= to
    if (inside) return `in der ${pause.label} (${pause.start}–${pause.end})`
  }
  return `außerhalb der Arbeitszeit (${weekday}: ${hours.start}–${hours.end} Uhr)`
}

/** Hinweise, wenn Beginn oder Ende ausserhalb der Arbeitszeit liegen (wie im Backend). */
export function timeWarnings(config: WorkingHoursConfig, startsAt: Date, endsAt: Date): string[] {
  const warnings: string[] = []
  if (!isWorkingStart(config, startsAt))
    warnings.push(`Beginn ${describeOutside(config, startsAt, true)}.`)
  if (!isWorkingEnd(config, endsAt)) warnings.push(`Ende ${describeOutside(config, endsAt, false)}.`)
  return warnings
}

/** Frueheste und spaeteste Uhrzeit aller Arbeitstage als Kommazahl, z. B. 7.5 und 17. */
export function dayBounds(config: WorkingHoursConfig): { start: number; end: number } {
  const days = Object.values(config.days).filter((d): d is { start: string; end: string } => !!d)
  return {
    start: Math.min(...days.map((d) => hourOf(d.start))),
    end: Math.max(...days.map((d) => hourOf(d.end))),
  }
}

/**
 * Nicht verfuegbare Zeiten eines Tages innerhalb [fromHour, toHour] (Stunden
 * als Kommazahl) – fuer die Schattierung im Kalender.
 */
export function blockedRanges(
  config: WorkingHoursConfig,
  day: Date,
  fromHour: number,
  toHour: number,
): { from: number; to: number; label: string }[] {
  const hours = config.days[isoWeekday(day)]
  if (!hours) return [{ from: fromHour, to: toHour, label: 'Arbeitsfrei' }]

  const result: { from: number; to: number; label: string }[] = []
  const start = hourOf(hours.start)
  const end = hourOf(hours.end)
  if (start > fromHour) result.push({ from: fromHour, to: Math.min(start, toHour), label: '' })
  for (const pause of config.breaks) {
    const from = Math.max(hourOf(pause.start), fromHour)
    const to = Math.min(hourOf(pause.end), toHour)
    if (to > from && from < end) result.push({ from, to, label: pause.label })
  }
  if (end < toHour) result.push({ from: Math.max(end, fromHour), to: toHour, label: 'Feierabend' })
  return result
}
