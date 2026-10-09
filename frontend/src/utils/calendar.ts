import type { Job } from '@/api/types'
import { addDays, hourOfDay, startOfDay } from './time'

/** Sichtbarer Arbeitstag im Kalender: 07:00 bis 17:00, 58 px pro Stunde wie im Entwurf. */
export const DAY_START_HOUR = 7
export const DAY_END_HOUR = 17
export const HOUR_PX = 58
export const DAY_HEIGHT = (DAY_END_HOUR - DAY_START_HOUR) * HOUR_PX

export const hours = Array.from(
  { length: DAY_END_HOUR - DAY_START_HOUR + 1 },
  (_, i) => DAY_START_HOUR + i,
)

export interface Segment {
  job: Job
  /** Beginn/Ende in Stunden am jeweiligen Tag, auf den Arbeitstag beschnitten. */
  from: number
  to: number
}

/**
 * Ergebnis eines Drag & Drop: entweder ein Platz im Kalender (Tag, Uhrzeit,
 * ggf. Arbeiter-Spalte) oder zurueck in die To-do-Liste (Termin entfernen).
 */
export type MoveEvent =
  | { kind: 'slot'; job: Job; startsAt: Date; assigneeId: number | null }
  | { kind: 'unschedule'; job: Job }

/**
 * Rein technische Standardlaenge eines Kalenderblocks beim Einplanen ohne
 * Ende (wie Job::DEFAULT_SLOT_MINUTES im Backend) – keine geplante Arbeitszeit.
 */
export const DEFAULT_SLOT_MINUTES = 60

/** Teil einer Arbeit, der auf einen Tag faellt – oder null, wenn nichts davon sichtbar ist. */
export function segmentOn(job: Job, day: Date): Segment | null {
  if (!job.startsAt || !job.endsAt) return null // Aufgabe ohne Termin
  const dayStart = startOfDay(day)
  const dayEnd = addDays(dayStart, 1)
  const starts = new Date(job.startsAt)
  const ends = new Date(job.endsAt)

  if (ends <= dayStart || starts >= dayEnd) return null

  const from = Math.max(DAY_START_HOUR, starts < dayStart ? 0 : hourOfDay(starts))
  const to = Math.min(DAY_END_HOUR, ends >= dayEnd ? 24 : hourOfDay(ends))

  return to > from ? { job, from, to } : null
}

export interface Placed extends Segment {
  /** Position und Breite in der Spalte als Anteil (0–1). */
  left: number
  width: number
}

/**
 * Verteilt sich ueberschneidende Arbeiten nebeneinander auf Spuren.
 * Arbeiten ohne Ueberschneidung bekommen die volle Breite.
 */
export function layoutLanes(segments: Segment[]): Placed[] {
  const sorted = [...segments].sort((a, b) => a.from - b.from || b.to - a.to)
  const placed: Placed[] = []
  let cluster: { item: Placed; lane: number }[] = []
  let clusterEnd = -Infinity

  const closeCluster = () => {
    const lanes = Math.max(1, ...cluster.map((c) => c.lane + 1))
    cluster.forEach(({ item, lane }) => {
      item.left = lane / lanes
      item.width = 1 / lanes
    })
    cluster = []
  }

  for (const segment of sorted) {
    if (segment.from >= clusterEnd) closeCluster()

    const busy = new Set(cluster.filter((c) => c.item.to > segment.from).map((c) => c.lane))
    let lane = 0
    while (busy.has(lane)) lane++

    const item: Placed = { ...segment, left: 0, width: 1 }
    cluster.push({ item, lane })
    placed.push(item)
    clusterEnd = Math.max(clusterEnd, segment.to)
  }
  closeCluster()

  return placed
}

/**
 * Wochenansicht: jeder Arbeiter, der an dem Tag etwas zu tun hat, bekommt
 * eine feste Spur, damit seine Arbeiten untereinander stehen.
 */
export function layoutByWorker(segments: Segment[], workerOrder: number[]): Placed[] {
  const rank = (s: Segment) => {
    const index = workerOrder.indexOf(s.job.assignee?.id ?? -1)
    return index < 0 ? workerOrder.length : index
  }
  const groups = [...new Set(segments.map(rank))].sort((a, b) => a - b)

  return groups.flatMap((group, i) =>
    layoutLanes(segments.filter((s) => rank(s) === group)).map((p) => ({
      ...p,
      left: (i + p.left) / groups.length,
      width: p.width / groups.length,
    })),
  )
}

/** Laenge des Kalenderblocks; Aufgaben ohne Termin bekommen den technischen Standardblock. */
export function jobDurationMs(job: Job): number {
  if (job.startsAt && job.endsAt)
    return new Date(job.endsAt).getTime() - new Date(job.startsAt).getTime()
  return DEFAULT_SLOT_MINUTES * 60_000
}

/** Stunde am Tag -> Abstand von oben in Pixel */
export function hourToPx(hour: number): number {
  return (hour - DAY_START_HOUR) * HOUR_PX
}

/** Klickposition in der Spalte -> Startzeit, auf halbe Stunden gerundet */
export function pxToTime(day: Date, offsetY: number): Date {
  const halfHours = Math.floor(offsetY / (HOUR_PX / 2))
  const hour = Math.min(DAY_END_HOUR - 1, DAY_START_HOUR + halfHours / 2)
  const result = startOfDay(day)
  result.setHours(Math.floor(hour), (hour % 1) * 60)
  return result
}
