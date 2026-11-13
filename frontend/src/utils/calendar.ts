import type { Absence, Job } from '@/api/types'
import { workingHours } from '@/composables/useWorkingHours'
import { addDays, hourOfDay, startOfDay } from './time'
import { blockedRanges, dayBounds, durationMinutes, endFor } from './workTime'

/** 58 px pro Stunde wie im Entwurf. */
export const HOUR_PX = 58

/**
 * Sichtbarer Bereich der Zeitachse: von der vollen Stunde vor Arbeitsbeginn
 * bis zur vollen Stunde nach Arbeitsschluss – abgeleitet aus den
 * Arbeitszeiten vom Server (vor dem Laden: ganzer Werktag 06–20 Uhr).
 */
export function gridStartHour(): number {
  return workingHours.value ? Math.floor(dayBounds(workingHours.value).start) : 6
}

export function gridEndHour(): number {
  return workingHours.value ? Math.ceil(dayBounds(workingHours.value).end) : 20
}

export function dayHeight(): number {
  return (gridEndHour() - gridStartHour()) * HOUR_PX
}

export function gridHours(): number[] {
  return Array.from({ length: gridEndHour() - gridStartHour() + 1 }, (_, i) => gridStartHour() + i)
}

/** Mittagspause, vor Arbeitsbeginn, nach Feierabend – im Kalender schattiert. */
export function blockedOn(day: Date): { from: number; to: number; label: string }[] {
  return workingHours.value
    ? blockedRanges(workingHours.value, day, gridStartHour(), gridEndHour())
    : []
}

interface BaseSegment {
  /** eindeutig je Spalte */
  key: string
  /** Person, deren Zeile/Spalte das Element belegt (null = nicht zugeteilt) */
  personId: number | null
  /** Beginn/Ende in Stunden am jeweiligen Tag, auf die Zeitachse beschnitten. */
  from: number
  to: number
  /** Beginnt bzw. endet der Eintrag an diesem Tag sichtbar? (Ziehgriffe nur dort) */
  startsHere: boolean
  endsHere: boolean
}

/**
 * Teil eines Auftrags an einem Tag. Mit Lehrling erscheint derselbe Auftrag
 * zweimal: beim Zustaendigen (role "assignee") und beim Lehrling ("apprentice").
 */
export interface JobSegment extends BaseSegment {
  kind: 'job'
  job: Job
  role: 'assignee' | 'apprentice'
}

export interface AbsenceSegment extends BaseSegment {
  kind: 'absence'
  absence: Absence
}

export type Segment = JobSegment | AbsenceSegment

/**
 * Ergebnis eines Drag & Drop: entweder ein Platz im Kalender (Tag, Uhrzeit,
 * ggf. Arbeiter-Spalte) oder zurueck in die To-do-Liste (Termin entfernen).
 */
export type MoveEvent =
  | { kind: 'slot'; job: Job; startsAt: Date; assigneeId: number | null }
  | { kind: 'unschedule'; job: Job }

/** Ziehen am oberen oder unteren Rand eines Termins. */
export interface ResizeEvent {
  job: Job
  startsAt: Date
  endsAt: Date
}

/** Standardlaenge beim Einplanen ohne Ende (wie Job::DEFAULT_SLOT_MINUTES), in Arbeitsminuten. */
export const DEFAULT_SLOT_MINUTES = 60

/** Sichtbarer Teil eines Zeitraums an einem Tag – oder null. */
function clip(
  starts: Date,
  ends: Date,
  day: Date,
): Pick<BaseSegment, 'from' | 'to' | 'startsHere' | 'endsHere'> | null {
  const dayStart = startOfDay(day)
  const dayEnd = addDays(dayStart, 1)
  if (ends <= dayStart || starts >= dayEnd) return null

  const rawFrom = starts < dayStart ? 0 : hourOfDay(starts)
  const rawTo = ends >= dayEnd ? 24 : hourOfDay(ends)
  const from = Math.max(gridStartHour(), rawFrom)
  const to = Math.min(gridEndHour(), rawTo)
  if (to <= from) return null

  return {
    from,
    to,
    startsHere: starts >= dayStart && rawFrom >= gridStartHour(),
    endsHere: ends < dayEnd && rawTo <= gridEndHour(),
  }
}

/** Teil eines Auftrags beim Zustaendigen an einem Tag (ohne Lehrling). */
export function segmentOn(job: Job, day: Date): JobSegment | null {
  return jobSegmentsOn(job, day).find((s) => s.role === 'assignee') ?? null
}

/** Auftrag an einem Tag – beim Zustaendigen und, falls eingetragen, beim Lehrling. */
export function jobSegmentsOn(job: Job, day: Date): JobSegment[] {
  if (!job.startsAt || !job.endsAt) return [] // Aufgabe ohne Termin
  const part = clip(new Date(job.startsAt), new Date(job.endsAt), day)
  if (!part) return []

  const result: JobSegment[] = [
    { kind: 'job', key: `job-${job.id}`, job, role: 'assignee', personId: job.assignee?.id ?? null, ...part },
  ]
  if (job.apprentice) {
    result.push({
      kind: 'job',
      key: `job-${job.id}-lehrling`,
      job,
      role: 'apprentice',
      personId: job.apprentice.id,
      ...part,
    })
  }
  return result
}

export function absenceSegmentOn(absence: Absence, day: Date): AbsenceSegment | null {
  const part = clip(new Date(absence.startsAt), new Date(absence.endsAt), day)
  return part
    ? { kind: 'absence', key: `absence-${absence.id}`, absence, personId: absence.user.id, ...part }
    : null
}

/** Alle Kalenderelemente eines Tages. */
export function segmentsOn(jobs: Job[], absences: Absence[], day: Date): Segment[] {
  return [
    ...absences.map((a) => absenceSegmentOn(a, day)).filter((s): s is AbsenceSegment => s !== null),
    ...jobs.flatMap((job) => jobSegmentsOn(job, day)),
  ]
}

export interface Placed<S extends Segment = Segment> {
  segment: S
  /** Position und Breite in der Spalte als Anteil (0–1). */
  left: number
  width: number
}

/**
 * Verteilt sich ueberschneidende Eintraege nebeneinander auf Spuren – so
 * bleiben gleichzeitige Auftraege sichtbar und anklickbar. Eintraege ohne
 * Ueberschneidung bekommen die volle Breite.
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

    const busy = new Set(cluster.filter((c) => c.item.segment.to > segment.from).map((c) => c.lane))
    let lane = 0
    while (busy.has(lane)) lane++

    const item: Placed = { segment, left: 0, width: 1 }
    cluster.push({ item, lane })
    placed.push(item)
    clusterEnd = Math.max(clusterEnd, segment.to)
  }
  closeCluster()

  return placed
}

/**
 * Wochenansicht: jede Person, die an dem Tag etwas zu tun hat (oder abwesend
 * ist), bekommt eine feste Spur, damit ihre Eintraege untereinander stehen.
 */
export function layoutByWorker(segments: Segment[], workerOrder: number[]): Placed[] {
  const rank = (s: Segment) => {
    const index = workerOrder.indexOf(s.personId ?? -1)
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

/**
 * Neues Ende beim Verschieben: die Dauer (Arbeitszeit) bleibt erhalten,
 * Pausen und Feierabend werden uebersprungen. Aufgaben ohne Termin bekommen
 * die Standardlaenge.
 */
export function endAfterMove(job: Job, startsAt: Date): Date {
  const config = workingHours.value
  const minutes =
    job.startsAt && job.endsAt && config
      ? durationMinutes(config, new Date(job.startsAt), new Date(job.endsAt))
      : DEFAULT_SLOT_MINUTES

  if (config && minutes > 0) return endFor(config, startsAt, minutes)
  const clockMs =
    job.startsAt && job.endsAt
      ? new Date(job.endsAt).getTime() - new Date(job.startsAt).getTime()
      : DEFAULT_SLOT_MINUTES * 60_000
  return new Date(startsAt.getTime() + clockMs)
}

/** Stunde am Tag -> Abstand von oben in Pixel */
export function hourToPx(hour: number): number {
  return (hour - gridStartHour()) * HOUR_PX
}

/** Klickposition in der Spalte -> Startzeit, auf halbe Stunden gerundet */
export function pxToTime(day: Date, offsetY: number): Date {
  const halfHours = Math.floor(offsetY / (HOUR_PX / 2))
  const hour = Math.min(gridEndHour() - 1, gridStartHour() + halfHours / 2)
  const result = startOfDay(day)
  result.setHours(Math.floor(hour), (hour % 1) * 60)
  return result
}
