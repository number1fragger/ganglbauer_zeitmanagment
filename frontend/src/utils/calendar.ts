/** Datums-Hilfen fuer den Kalender. Alle Tage in lokaler Zeit. */

export type CalendarMode = 'tag' | 'woche' | 'monat'

const DAY_MS = 86_400_000

/** "2026-09-28" -> Date um 00:00 Ortszeit (new Date("…") waere UTC). */
export function parseDay(value: string): Date {
  const [y, m, d] = value.split('-').map(Number)

  return new Date(y ?? 1970, (m ?? 1) - 1, d ?? 1)
}

export function dayKey(date: Date): string {
  const m = String(date.getMonth() + 1).padStart(2, '0')
  const d = String(date.getDate()).padStart(2, '0')

  return `${date.getFullYear()}-${m}-${d}`
}

export function addDays(date: Date, days: number): Date {
  const copy = new Date(date)
  copy.setDate(copy.getDate() + days)

  return copy
}

export function startOfDay(date: Date): Date {
  return new Date(date.getFullYear(), date.getMonth(), date.getDate())
}

/** Montag der Woche. */
export function startOfWeek(date: Date): Date {
  const day = startOfDay(date)
  const weekday = (day.getDay() + 6) % 7

  return addDays(day, -weekday)
}

export function startOfMonth(date: Date): Date {
  return new Date(date.getFullYear(), date.getMonth(), 1)
}

export function isWeekend(date: Date): boolean {
  return date.getDay() === 0 || date.getDay() === 6
}

export function isSameDay(a: Date, b: Date): boolean {
  return dayKey(a) === dayKey(b)
}

/** Werktag: Wochenenden werden uebersprungen (fuer "Tag vor/zurueck"). */
export function shiftWorkday(date: Date, direction: 1 | -1): Date {
  let cursor = addDays(date, direction)

  while (isWeekend(cursor)) {
    cursor = addDays(cursor, direction)
  }

  return cursor
}

/** ISO-Kalenderwoche, z. B. 40. */
export function isoWeek(date: Date): number {
  const target = startOfDay(date)
  target.setDate(target.getDate() + 3 - ((target.getDay() + 6) % 7))
  const firstThursday = new Date(target.getFullYear(), 0, 4)

  return (
    1 +
    Math.round(
      ((target.getTime() - firstThursday.getTime()) / DAY_MS -
        3 +
        ((firstThursday.getDay() + 6) % 7)) /
        7,
    )
  )
}

/** Sichtbarer Bereich je Ansicht – der Monat immer in ganzen Wochen (Mo–So). */
export function visibleRange(mode: CalendarMode, anchor: Date): { from: Date; to: Date } {
  if (mode === 'tag') {
    return { from: startOfDay(anchor), to: startOfDay(anchor) }
  }

  if (mode === 'woche') {
    const monday = startOfWeek(anchor)

    return { from: monday, to: addDays(monday, 4) }
  }

  const first = startOfMonth(anchor)
  const last = new Date(anchor.getFullYear(), anchor.getMonth() + 1, 0)

  return { from: startOfWeek(first), to: addDays(startOfWeek(last), 6) }
}

/** Minuten seit Mitternacht. */
export function minutesOfDay(date: Date): number {
  return date.getHours() * 60 + date.getMinutes()
}

/** Feste Farbe je Arbeiter, damit man ihn in Woche und Monat wiedererkennt. */
const WORKER_COLORS = [
  '#2563eb',
  '#7c3aed',
  '#0d9488',
  '#db2777',
  '#ea580c',
  '#0891b2',
  '#65a30d',
  '#9333ea',
]

export function workerColor(workerId: number, order: number[]): string {
  const index = order.indexOf(workerId)

  return WORKER_COLORS[(index >= 0 ? index : workerId) % WORKER_COLORS.length] ?? '#2563eb'
}

export interface Lane<T> {
  item: T
  lane: number
  lanes: number
}

/**
 * Verteilt ueberlappende Termine nebeneinander (wie Google Kalender):
 * Termine, die sich zeitlich ueberschneiden, teilen sich die Spaltenbreite.
 */
export function layoutLanes<T>(items: T[], range: (item: T) => [number, number]): Lane<T>[] {
  const sorted = [...items].sort((a, b) => range(a)[0] - range(b)[0] || range(b)[1] - range(a)[1])
  const result: Lane<T>[] = []
  let cluster: Lane<T>[] = []
  let laneEnds: number[] = []
  let clusterEnd = -Infinity

  const closeCluster = (): void => {
    for (const entry of cluster) entry.lanes = laneEnds.length
    result.push(...cluster)
    cluster = []
    laneEnds = []
  }

  for (const item of sorted) {
    const [start, end] = range(item)

    if (start >= clusterEnd && cluster.length > 0) {
      closeCluster()
    }

    let lane = laneEnds.findIndex((laneEnd) => laneEnd <= start)
    if (lane === -1) {
      lane = laneEnds.length
      laneEnds.push(end)
    } else {
      laneEnds[lane] = end
    }

    cluster.push({ item, lane, lanes: 1 })
    clusterEnd = Math.max(clusterEnd, end)
  }

  if (cluster.length > 0) closeCluster()

  return result
}
