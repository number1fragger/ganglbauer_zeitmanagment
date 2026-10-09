<script setup lang="ts">
import { pendingConfirm } from '@/composables/useConfirm'
import BaseModal from './BaseModal.vue'
</script>

<template>
  <BaseModal
    v-if="pendingConfirm"
    :title="pendingConfirm.title"
    :width="420"
    compact
    @close="pendingConfirm.resolve(false)"
  >
    <p v-if="pendingConfirm.message" class="confirm__text">{{ pendingConfirm.message }}</p>
    <footer class="confirm__actions">
      <button class="btn btn--outline" @click="pendingConfirm.resolve(false)">Abbrechen</button>
      <button
        class="btn"
        :class="{
          'btn--primary': (pendingConfirm.tone ?? 'primary') === 'primary',
          'btn--danger': pendingConfirm.tone === 'danger',
          'btn--success': pendingConfirm.tone === 'success',
        }"
        autofocus
        @click="pendingConfirm.resolve(true)"
      >
        {{ pendingConfirm.confirmLabel ?? 'Bestätigen' }}
      </button>
    </footer>
  </BaseModal>
</template>

<style scoped>
.confirm__text {
  color: var(--muted);
  font-size: 13px;
  line-height: 1.5;
}

.confirm__actions {
  display: flex;
  justify-content: flex-end;
  gap: 10px;
  margin-top: 22px;
}
</style>
