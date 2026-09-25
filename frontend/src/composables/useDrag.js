import { onBeforeUnmount, reactive } from 'vue'

const LONG_PRESS_MS = 350
const MOVE_TOLERANCE_PX = 6
const EDGE_SCROLL_PX = 48

/**
 * Drag & Drop mit Pointer Events – funktioniert mit Maus und Finger.
 *
 *  - Maus: ziehen startet nach ein paar Pixeln Bewegung, ein Klick bleibt ein Klick.
 *  - Touch: erst nach kurzem Gedrueckthalten, damit normales Wischen weiter scrollt.
 *
 * resolveTarget(x, y, drag) liefert das Ziel unter dem Zeiger (oder null),
 * onDrop(item, target) wird beim Loslassen gerufen, onClick(item) bei einem Klick.
 */
export function useDrag({ resolveTarget, onDrop, onClick }) {
  const drag = reactive({
    active: false,
    item: null,
    x: 0,
    y: 0,
    offsetY: 0,
    height: 0,
    target: null,
  })
  let pending = null

  function start(event, item, { draggable = true } = {}) {
    if (event.button > 0) return

    const rect = event.currentTarget.getBoundingClientRect()
    pending = {
      item,
      draggable,
      touch: event.pointerType !== 'mouse',
      startX: event.clientX,
      startY: event.clientY,
      offsetY: event.clientY - rect.top,
      height: rect.height,
      scroller: event.currentTarget.closest('[data-scroll]'),
      timer: null,
    }

    if (pending.touch && draggable) {
      pending.timer = setTimeout(() => begin(pending.startX, pending.startY), LONG_PRESS_MS)
    }

    window.addEventListener('pointermove', move)
    window.addEventListener('pointerup', end)
    window.addEventListener('pointercancel', cancel)
    window.addEventListener('keydown', escape)
  }

  function begin(x, y) {
    if (!pending) return

    Object.assign(drag, {
      active: true,
      item: pending.item,
      offsetY: pending.offsetY,
      height: pending.height,
    })
    navigator.vibrate?.(15)
    update(x, y)
  }

  function move(event) {
    if (!pending) return

    if (!drag.active) {
      const distance = Math.hypot(event.clientX - pending.startX, event.clientY - pending.startY)
      if (distance < MOVE_TOLERANCE_PX) return
      // Touch vor Ablauf des Gedrueckthaltens: der Nutzer will scrollen.
      if (pending.touch || !pending.draggable) return cancel()
      begin(event.clientX, event.clientY)
    }

    update(event.clientX, event.clientY)
  }

  function update(x, y) {
    drag.x = x
    drag.y = y
    drag.target = resolveTarget(x, y, drag)
    edgeScroll(x, y)
  }

  /** Am Rand der Kalenderflaeche mitscrollen, z. B. zum naechsten Arbeiter. */
  function edgeScroll(x, y) {
    const box = pending?.scroller?.getBoundingClientRect()
    if (!box) return

    const dx = x < box.left + EDGE_SCROLL_PX ? -12 : x > box.right - EDGE_SCROLL_PX ? 12 : 0
    const dy = y < box.top + EDGE_SCROLL_PX ? -12 : y > box.bottom - EDGE_SCROLL_PX ? 12 : 0
    if (dx || dy) pending.scroller.scrollBy(dx, dy)
  }

  function end() {
    const { item } = pending ?? {}
    const wasDragging = drag.active
    const target = drag.target

    cancel()

    if (wasDragging && target) onDrop(item, target)
    else if (!wasDragging && item) onClick(item)
  }

  function cancel() {
    clearTimeout(pending?.timer)
    pending = null
    Object.assign(drag, { active: false, item: null, target: null })
    window.removeEventListener('pointermove', move)
    window.removeEventListener('pointerup', end)
    window.removeEventListener('pointercancel', cancel)
    window.removeEventListener('keydown', escape)
  }

  function escape(event) {
    if (event.key === 'Escape') cancel()
  }

  // Waehrend des Ziehens darf der Finger die Seite nicht scrollen.
  const blockScroll = (event) => drag.active && event.preventDefault()
  document.addEventListener('touchmove', blockScroll, { passive: false })
  onBeforeUnmount(() => {
    cancel()
    document.removeEventListener('touchmove', blockScroll)
  })

  return { drag, start }
}
