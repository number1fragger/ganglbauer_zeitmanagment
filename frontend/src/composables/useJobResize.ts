import { reactive } from 'vue'
import type { Job } from '@/api/types'
import { gridEndHour, gridStartHour, HOUR_PX, type ResizeEvent } from '@/utils/calendar'
import { fromDateKey, startOfDay } from '@/utils/time'

const SNAP = 15
const MIN_MINUTES = 15
const PAD = 3

/**
 * Termin verlaengern bzw. verkuerzen durch Ziehen am oberen oder unteren Rand.
 * Gilt fuer den Tag, an dem gezogen wird (15-Minuten-Raster); ueber mehrere
 * Tage geht es im Auftrag selbst. Esc bricht ab.
 */
export const resize = reactive({
  job: null as Job | null,
  edge: 'end' as 'start' | 'end',
  active: false,
  startsAt: null as Date | null,
  endsAt: null as Date | null,
})

let column: HTMLElement | null = null
let day: Date | null = null
let originY = 0
let onDone: ((event: ResizeEvent) => void) | null = null

export function beginResize(
  event: PointerEvent,
  job: Job,
  edge: 'start' | 'end',
  callback: (event: ResizeEvent) => void,
): void {
  if (event.button !== 0 || resize.job || !job.startsAt || !job.endsAt) return
  event.stopPropagation()
  event.preventDefault()

  column = (event.currentTarget as HTMLElement).closest<HTMLElement>('[data-drop-day]')
  day = column ? fromDateKey(column.dataset.dropDay) : null
  if (!column || !day) return

  originY = event.clientY
  onDone = callback
  Object.assign(resize, {
    job,
    edge,
    active: false,
    startsAt: new Date(job.startsAt),
    endsAt: new Date(job.endsAt),
  })
  window.addEventListener('pointermove', onMove)
  window.addEventListener('pointerup', onUp)
  window.addEventListener('pointercancel', cancel)
  window.addEventListener('keydown', onKey)
}

function onMove(event: PointerEvent): void {
  const job = resize.job
  if (!job?.startsAt || !job.endsAt || !column || !day) return
  if (!resize.active && Math.abs(event.clientY - originY) < 3) return
  resize.active = true

  const rect = column.getBoundingClientRect()
  const rawMinutes = ((event.clientY - rect.top - PAD) / HOUR_PX) * 60
  const maxMinutes = (gridEndHour() - gridStartHour()) * 60
  const minutes = Math.min(Math.max(Math.round(rawMinutes / SNAP) * SNAP, 0), maxMinutes)
  const moment = startOfDay(day)
  moment.setHours(gridStartHour(), minutes)

  const start = new Date(job.startsAt)
  const end = new Date(job.endsAt)
  const min = MIN_MINUTES * 60_000
  if (resize.edge === 'end') {
    resize.startsAt = start
    resize.endsAt = new Date(Math.max(moment.getTime(), start.getTime() + min))
  } else {
    resize.startsAt = new Date(Math.min(moment.getTime(), end.getTime() - min))
    resize.endsAt = end
  }
}

function onUp(): void {
  const { job, active, startsAt, endsAt } = resize
  const callback = onDone
  removeListeners()
  reset()
  if (!active || !job?.startsAt || !job.endsAt || !startsAt || !endsAt) return

  // Klick nach dem Loslassen nicht als "Auftrag oeffnen" werten.
  const swallow = (event: Event) => event.stopPropagation()
  window.addEventListener('click', swallow, { capture: true, once: true })
  setTimeout(() => window.removeEventListener('click', swallow, true), 0)

  const unchanged =
    startsAt.getTime() === new Date(job.startsAt).getTime() &&
    endsAt.getTime() === new Date(job.endsAt).getTime()
  if (!unchanged) callback?.({ job, startsAt, endsAt })
}

function cancel(): void {
  removeListeners()
  reset()
}

const onKey = (event: KeyboardEvent) => event.key === 'Escape' && cancel()

function removeListeners(): void {
  window.removeEventListener('pointermove', onMove)
  window.removeEventListener('pointerup', onUp)
  window.removeEventListener('pointercancel', cancel)
  window.removeEventListener('keydown', onKey)
}

function reset(): void {
  Object.assign(resize, { job: null, active: false, startsAt: null, endsAt: null })
  column = null
  day = null
  onDone = null
}
