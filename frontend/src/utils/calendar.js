/** Datums-Hilfen fuer den Kalender. Alle Tage in lokaler Zeit. */

const DAY_MS = 86_400_000

/** "2026-09-28" -> Date um 00:00 Ortszeit (new Date("...") waere UTC). */
export function parseDay(value) {
  const [y, m, d] = value.split('-').map(Number)
  return new Date(y, m - 1, d)
}

export function dayKey(date) {
  const pad = (n) => String(n).padStart(2, '0')
  return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}`
}

export function addDays(date, days) {
  const copy = new Date(date)
  copy.setDate(copy.getDate() + days)
  return copy
}

export const startOfDay = (date) => new Date(date.getFullYear(), date.getMonth(), date.getDate())
export const startOfWeek = (date) => addDays(startOfDay(date), -((date.getDay() + 6) % 7))
export const isWeekend = (date) => date.getDay() === 0 || date.getDay() === 6
export const isSameDay = (a, b) => dayKey(a) === dayKey(b)
export const minutesOfDay = (date) => date.getHours() * 60 + date.getMinutes()

/** Naechster bzw. vorheriger Werktag (Wochenenden werden uebersprungen). */
export function shiftWorkday(date, direction) {
  let cursor = addDays(date, direction)
  while (isWeekend(cursor)) cursor = addDays(cursor, direction)
  return cursor
}

/** ISO-Kalenderwoche */
export function isoWeek(date) {
  const thursday = addDays(startOfDay(date), 3 - ((date.getDay() + 6) % 7))
  const firstThursday = new Date(thursday.getFullYear(), 0, 4)
  return (
    1 +
    Math.round(((thursday - firstThursday) / DAY_MS - 3 + ((firstThursday.getDay() + 6) % 7)) / 7)
  )
}

/** Sichtbarer Bereich je Ansicht – der Monat immer in ganzen Wochen. */
export function visibleRange(mode, anchor) {
  if (mode === 'tag') return { from: startOfDay(anchor), to: startOfDay(anchor) }
  if (mode === 'woche') {
    const monday = startOfWeek(anchor)
    return { from: monday, to: addDays(monday, 4) }
  }

  const last = new Date(anchor.getFullYear(), anchor.getMonth() + 1, 0)
  return {
    from: startOfWeek(new Date(anchor.getFullYear(), anchor.getMonth(), 1)),
    to: addDays(startOfWeek(last), 6),
  }
}

/** Die vier Arbeiterfarben aus Figma (Balken + heller Hintergrund). */
const WORKER_COLORS = [
  { color: '#2563eb', soft: '#eaf0fe' },
  { color: '#7c3aed', soft: '#f1ebfe' },
  { color: '#0d9488', soft: '#e6f6f4' },
  { color: '#db2777', soft: '#fdebf3' },
]

export function workerColor(workerId, order) {
  const index = order.indexOf(workerId)
  return WORKER_COLORS[(index >= 0 ? index : workerId) % WORKER_COLORS.length]
}

/**
 * Verteilt ueberlappende Termine nebeneinander (wie Google Kalender).
 * range(item) liefert [start, ende] in Minuten.
 */
export function layoutLanes(items, range) {
  const sorted = [...items].sort((a, b) => range(a)[0] - range(b)[0] || range(b)[1] - range(a)[1])
  const result = []
  let cluster = []
  let laneEnds = []
  let clusterEnd = -Infinity

  const close = () => {
    cluster.forEach((entry) => (entry.lanes = laneEnds.length))
    result.push(...cluster)
    cluster = []
    laneEnds = []
  }

  for (const item of sorted) {
    const [start, end] = range(item)
    if (start >= clusterEnd && cluster.length) close()

    let lane = laneEnds.findIndex((laneEnd) => laneEnd <= start)
    if (lane === -1) lane = laneEnds.push(end) - 1
    else laneEnds[lane] = end

    cluster.push({ item, lane, lanes: 1 })
    clusterEnd = Math.max(clusterEnd, end)
  }

  if (cluster.length) close()
  return result
}
