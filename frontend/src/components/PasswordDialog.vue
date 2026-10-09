<script setup lang="ts">
import { ref } from 'vue'
import { authApi } from '@/api'
import { errorMessage } from '@/api/http'
import BaseModal from './BaseModal.vue'

const emit = defineEmits<{ close: [] }>()

const current = ref('')
const next = ref('')
const error = ref('')
const done = ref(false)
const busy = ref(false)

async function save(): Promise<void> {
  error.value = ''
  busy.value = true
  try {
    await authApi.changePassword(current.value, next.value)
    done.value = true
  } catch (e) {
    error.value = errorMessage(e)
  } finally {
    busy.value = false
  }
}
</script>

<template>
  <BaseModal title="Passwort ändern" :width="420" @close="emit('close')">
    <p v-if="done" class="muted">Dein Passwort wurde geändert.</p>
    <form v-else class="password" @submit.prevent="save">
      <label class="field">
        <span class="field__label">Aktuelles Passwort</span>
        <input v-model="current" class="input" type="password" autocomplete="current-password" required />
      </label>
      <label class="field">
        <span class="field__label">Neues Passwort (mindestens 8 Zeichen)</span>
        <input v-model="next" class="input" type="password" autocomplete="new-password" minlength="8" required />
      </label>
      <p v-if="error" class="form-error" role="alert">{{ error }}</p>
      <div class="password__actions">
        <button type="button" class="btn btn--large" @click="emit('close')">Abbrechen</button>
        <button class="btn btn--primary btn--large" :disabled="busy">Passwort ändern</button>
      </div>
    </form>
    <div v-if="done" class="password__actions">
      <button class="btn btn--primary btn--large" @click="emit('close')">Schließen</button>
    </div>
  </BaseModal>
</template>

<style scoped>
.password {
  display: grid;
  gap: 16px;
}

.password__actions {
  display: flex;
  justify-content: flex-end;
  gap: 12px;
  margin-top: 8px;
}
</style>
