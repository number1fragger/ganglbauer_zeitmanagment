<script setup lang="ts">
import { onMounted, ref } from 'vue'
import AppIcon from './AppIcon.vue'

defineProps<{ title: string; subtitle?: string; width?: number; compact?: boolean }>()
const emit = defineEmits<{ close: [] }>()
defineSlots<{ default(): unknown; header?(): unknown }>()

const dialog = ref<HTMLDialogElement>()

// Natives <dialog>: Fokus, Esc und Hintergrund-Sperre kommen vom Browser.
onMounted(() => dialog.value?.showModal())

/** Nur schliessen, wenn wirklich auf den Hintergrund geklickt wurde (nicht nach Textauswahl). */
let downOnBackdrop = false
const onDown = (event: MouseEvent) => (downOnBackdrop = event.target === dialog.value)
const onUp = (event: MouseEvent) => {
  if (downOnBackdrop && event.target === dialog.value) emit('close')
  downOnBackdrop = false
}
</script>

<template>
  <dialog
    ref="dialog"
    class="modal"
    :class="{ 'modal--compact': compact }"
    :style="{ '--width': `${width ?? 560}px` }"
    @cancel.prevent="emit('close')"
    @mousedown="onDown"
    @mouseup="onUp"
  >
    <div class="modal__body">
      <header class="modal__head">
        <div class="modal__titles">
          <h2 class="modal__title">{{ title }}</h2>
          <p v-if="subtitle" class="page-sub">{{ subtitle }}</p>
          <slot name="header" />
        </div>
        <button type="button" class="modal__close" aria-label="Schließen" @click="emit('close')">
          <AppIcon name="x" :size="18" />
        </button>
      </header>
      <slot />
    </div>
  </dialog>
</template>

<style scoped>
.modal {
  width: var(--width);
  max-width: calc(100vw - 24px);
  max-height: calc(100dvh - 32px);
  padding: 0;
  border: 1px solid var(--border);
  border-radius: 14px;
  background: var(--surface);
  color: var(--text);
  box-shadow: var(--shadow-lg);
}

.modal[open] {
  animation: modal-in 0.16s ease-out;
}

.modal::backdrop {
  background: var(--overlay);
}

.modal__body {
  padding: 24px 28px 28px;
}

.modal__head {
  display: flex;
  align-items: flex-start;
  gap: 12px;
  padding-bottom: 16px;
  margin-bottom: 20px;
  border-bottom: 1px solid var(--border);
}

.modal--compact .modal__head {
  padding-bottom: 0;
  margin-bottom: 10px;
  border-bottom: 0;
}

.modal__titles {
  flex: 1;
  min-width: 0;
}

.modal__title {
  font-size: 18px;
  font-weight: 700;
}

.modal__close {
  display: grid;
  flex: none;
  place-items: center;
  width: 32px;
  height: 32px;
  margin: -4px -8px 0 0;
  border: 0;
  border-radius: var(--radius);
  background: none;
  color: var(--muted);
}

.modal__close:hover {
  background: var(--surface-muted);
  color: var(--text);
}

@keyframes modal-in {
  from {
    opacity: 0;
    transform: translateY(6px) scale(0.99);
  }
}

/* Smartphone: Dialog als Blatt von unten, volle Breite */
@media (max-width: 699px) {
  .modal:not(.modal--compact) {
    width: 100vw;
    max-width: 100vw;
    max-height: 94dvh;
    margin: auto 0 0;
    border-radius: 16px 16px 0 0;
  }

  .modal__body {
    padding: 18px 16px calc(20px + env(safe-area-inset-bottom));
  }
}
</style>
