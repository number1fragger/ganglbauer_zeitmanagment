<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { pendingConfirm } from '@/composables/useConfirm'
import BaseModal from './BaseModal.vue'

const typed = ref('')
watch(pendingConfirm, () => (typed.value = ''))

const blocked = computed(
  () => !!pendingConfirm.value?.requireText && typed.value.trim() !== pendingConfirm.value.requireText,
)
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
    <ul v-if="pendingConfirm.details?.length" class="confirm__details">
      <li v-for="line in pendingConfirm.details" :key="line">{{ line }}</li>
    </ul>
    <label v-if="pendingConfirm.requireText" class="field confirm__type">
      <span class="field__label"
        >Zur Bestätigung <strong>{{ pendingConfirm.requireText }}</strong> eingeben</span
      >
      <input
        v-model="typed"
        class="input"
        autocomplete="off"
        autocapitalize="none"
        spellcheck="false"
        @keydown.enter.prevent="!blocked && pendingConfirm.resolve(true)"
      />
    </label>
    <footer class="confirm__actions">
      <button class="btn btn--outline" @click="pendingConfirm.resolve(false)">Abbrechen</button>
      <button
        class="btn"
        :class="{
          'btn--primary': (pendingConfirm.tone ?? 'primary') === 'primary',
          'btn--danger': pendingConfirm.tone === 'danger',
          'btn--success': pendingConfirm.tone === 'success',
        }"
        :autofocus="!pendingConfirm.requireText"
        :disabled="blocked"
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

.confirm__details {
  display: grid;
  gap: 4px;
  margin: 12px 0 0;
  padding-left: 18px;
  color: var(--muted);
  font-size: 12px;
  line-height: 1.45;
}

.confirm__type {
  margin-top: 16px;
}

.confirm__actions {
  display: flex;
  justify-content: flex-end;
  gap: 10px;
  margin-top: 22px;
}
</style>
