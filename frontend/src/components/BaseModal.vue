<script setup lang="ts">
import { onMounted, ref } from 'vue'

defineProps<{ title: string; subtitle?: string; width?: number }>()
const emit = defineEmits<{ close: [] }>()

const dialog = ref<HTMLDialogElement>()

// Natives <dialog>: Fokus, Esc und Hintergrund-Sperre kommen vom Browser.
onMounted(() => dialog.value?.showModal())
</script>

<template>
  <dialog
    ref="dialog"
    class="modal"
    :style="{ width: `${width ?? 560}px` }"
    @cancel.prevent="emit('close')"
    @click.self="emit('close')"
  >
    <div class="modal__body">
      <header class="modal__head">
        <h2 class="page-title">{{ title }}</h2>
        <p v-if="subtitle" class="page-sub">{{ subtitle }}</p>
      </header>
      <slot />
    </div>
  </dialog>
</template>

<style scoped>
.modal {
  max-width: calc(100vw - 24px);
  max-height: calc(100vh - 24px);
  padding: 0;
  border: 0;
  border-radius: 12px;
  background: var(--surface);
  color: var(--text);
  box-shadow: 0 20px 50px rgb(15 23 42 / 0.2);
}

.modal::backdrop {
  background: rgb(15 23 42 / 0.35);
}

.modal__body {
  padding: 32px;
}

.modal__head {
  padding-bottom: 20px;
  margin-bottom: 22px;
  border-bottom: 1px solid var(--border);
}

@media (max-width: 560px) {
  .modal__body {
    padding: 22px 18px;
  }
}
</style>
