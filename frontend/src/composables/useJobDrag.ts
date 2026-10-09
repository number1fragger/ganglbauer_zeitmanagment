import { reactive } from 'vue'
import type { Job } from '@/api/types'
import { DAY_END_HOUR, DAY_START_HOUR, HOUR_PX, jobDurationMs, type MoveEvent } from '@/utils/calendar'
import { fromDateKey, startOfDay } from '@/utils/time'

const SNAP = 15
const THRESHOLD = 5
const PAD = 3

export interface DropTarget {
  key: string
  assigneeId: number | null
  startsAt: Date
  endsAt: Date
  left: number
  top: number
  width: number
}

export const drag = reactive({
  job: null as Job | null,
  active: false,
  dropping: false,
  x: 0,
  y: 0,
  grabX: 0,
  grabY: 0,
  width: 0,
  height: 0,
  target: null as DropTarget | null,
})

let origin = { x: 0, y: 0 }
let onDrop: ((event: MoveEvent) => void) | null = null

export function beginDrag(event: PointerEvent, job: Job, callback: (event: MoveEvent) => void): void {
  if (event.button !== 0 || drag.job) return
  const rect = (event.currentTarget as HTMLElement).getBoundingClientRect()

  origin = { x: event.clientX, y: event.clientY }
  Object.assign(drag, {
    job,
    active: false,
    dropping: false,
    target: null,
    grabX: event.clientX - rect.left,
    grabY: event.clientY - rect.top,
    x: rect.left,
    y: rect.top,
    width: rect.width,
    height: rect.height,
  })
  onDrop = callback
  window.addEventListener('pointermove', onMove)
  window.addEventListener('pointerup', onUp)
  window.addEventListener('pointercancel', cancel)
  window.addEventListener('keydown', onKey)
}

function updateTarget(event: PointerEvent): void {
  const job = drag.job
  const column = document.elementFromPoint(event.clientX, event.clientY)?.closest<HTMLElement>('[data-drop-day]')
  const day = column ? fromDateKey(column.dataset.dropDay) : null
  if (!job || !column || !day) {
    drag.target = null
    return
  }

  const rect = column.getBoundingClientRect()
  const rawMinutes = ((event.clientY - drag.grabY - rect.top - PAD) / HOUR_PX) * 60
  const maxMinutes = (DAY_END_HOUR - DAY_START_HOUR) * 60 - SNAP
  const minutes = Math.min(Math.max(Math.round(rawMinutes / SNAP) * SNAP, 0), maxMinutes)
  const startsAt = startOfDay(day)
  startsAt.setHours(DAY_START_HOUR, minutes)
  const duration = jobDurationMs(job)
  const owner = column.dataset.dropAssignee

  drag.target = {
    key: column.dataset.dropKey ?? '',
    assigneeId: owner === 'keep' ? (job.assignee?.id ?? null) : owner ? Number(owner) : null,
    startsAt,
    endsAt: new Date(startsAt.getTime() + duration),
    left: rect.left + 4,
    width: rect.width - 8,
    top: rect.top + (minutes / 60) * HOUR_PX + PAD,
  }
}

function onMove(event: PointerEvent): void {
  if (!drag.active) {
    if (Math.hypot(event.clientX - origin.x, event.clientY - origin.y) < THRESHOLD) return
    drag.active = true
  }
  drag.x = event.clientX - drag.grabX
  drag.y = event.clientY - drag.grabY
  updateTarget(event)
}

function onUp(): void {
  const { job, target, active } = drag
  removeListeners()
  if (!active || !job) return reset()

  const swallow = (event: Event) => event.stopPropagation()
  window.addEventListener('click', swallow, { capture: true, once: true })
  setTimeout(() => window.removeEventListener('click', swallow, true), 0)

  if (!target) return reset()
  drag.dropping = true
  const callback = onDrop
  setTimeout(() => {
    callback?.({ job, startsAt: target.startsAt, assigneeId: target.assigneeId })
    reset()
  }, 180)
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
  Object.assign(drag, { job: null, active: false, dropping: false, target: null })
  onDrop = null
}
