import { computed, onBeforeUnmount, ref } from 'vue'

const width = ref(typeof window !== 'undefined' ? window.innerWidth : 1280)
let listeners = 0
const update = () => (width.value = window.innerWidth)

/** Reaktive Bildschirmbreite: mobile < 700 px, tablet < 1100 px. */
export function useBreakpoint() {
  if (listeners++ === 0) window.addEventListener('resize', update, { passive: true })
  onBeforeUnmount(() => {
    if (--listeners === 0) window.removeEventListener('resize', update)
  })

  return {
    width,
    isMobile: computed(() => width.value < 700),
    isTablet: computed(() => width.value >= 700 && width.value < 1100),
    isDesktop: computed(() => width.value >= 1100),
  }
}
