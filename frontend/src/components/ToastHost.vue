<script setup lang="ts">
import { dismiss, toasts, type Toast } from '@/composables/useToast'
import AppIcon from './AppIcon.vue'

function runAction(t: Toast): void {
  t.action?.run()
  dismiss(t.id)
}

const icon = { info: 'info', success: 'check-circle', warning: 'alert', error: 'alert' } as const
</script>

<template>
  <div class="toasts" aria-live="polite">
    <TransitionGroup name="toast">
      <div v-for="t in toasts" :key="t.id" class="toast" :class="`toast--${t.tone}`" role="status">
        <AppIcon :name="icon[t.tone]" :size="16" />
        <span class="toast__text">{{ t.message }}</span>
        <button v-if="t.action" class="toast__action" @click="runAction(t)">
          {{ t.action.label }}
        </button>
        <button class="toast__close" aria-label="Meldung schließen" @click="dismiss(t.id)">
          <AppIcon name="x" :size="14" />
        </button>
      </div>
    </TransitionGroup>
  </div>
</template>

<style scoped>
.toasts {
  position: fixed;
  z-index: 1100;
  right: 20px;
  bottom: 20px;
  display: flex;
  flex-direction: column;
  gap: 8px;
  width: min(380px, calc(100vw - 32px));
  pointer-events: none;
}

.toast {
  --tone: var(--primary);
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 10px 10px 10px 14px;
  border: 1px solid var(--border);
  border-left: 3px solid var(--tone);
  border-radius: var(--radius);
  background: var(--surface);
  box-shadow: var(--shadow-lg);
  font-size: 12px;
  font-weight: 500;
  pointer-events: auto;
}

.toast > .icon {
  color: var(--tone);
}

.toast--success {
  --tone: var(--success);
}

.toast--warning {
  --tone: var(--warning);
}

.toast--error {
  --tone: var(--danger);
}

.toast__text {
  flex: 1;
}

.toast__action {
  padding: 4px 8px;
  border: 0;
  border-radius: var(--radius-sm);
  background: var(--primary-soft);
  color: var(--primary);
  font-size: 11px;
  font-weight: 600;
}

.toast__close {
  display: grid;
  place-items: center;
  width: 24px;
  height: 24px;
  border: 0;
  border-radius: var(--radius-sm);
  background: none;
  color: var(--muted);
}

.toast__close:hover {
  background: var(--surface-muted);
}

.toast-enter-active,
.toast-leave-active {
  transition:
    opacity 0.2s,
    transform 0.2s;
}

.toast-enter-from,
.toast-leave-to {
  opacity: 0;
  transform: translateY(8px);
}

@media (max-width: 699px) {
  .toasts {
    right: 16px;
    bottom: calc(76px + env(safe-area-inset-bottom));
  }
}
</style>
