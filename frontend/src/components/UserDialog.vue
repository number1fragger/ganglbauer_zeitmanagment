<script setup lang="ts">
import { reactive, ref } from 'vue'
import { usersApi } from '@/api'
import { errorMessage } from '@/api/http'
import type { User, UserInput } from '@/api/types'
import { roles } from '@/utils/domain'
import BaseModal from './BaseModal.vue'

const props = defineProps<{ user?: User | null; isSelf?: boolean }>()
const emit = defineEmits<{ close: []; saved: [] }>()

const form = reactive({
  username: props.user?.username ?? '',
  firstName: props.user?.firstName ?? '',
  lastName: props.user?.lastName ?? '',
  role: props.user?.role ?? 'arbeiter',
  weeklyHours: props.user?.weeklyHours ?? 38.5,
  active: props.user?.active ?? true,
  password: '',
})

const error = ref('')
const busy = ref(false)

async function save(): Promise<void> {
  const input: UserInput = {
    firstName: form.firstName,
    lastName: form.lastName,
    role: form.role,
    weeklyHours: Number(form.weeklyHours),
    active: form.active,
    ...(form.password ? { password: form.password } : {}),
  }

  error.value = ''
  busy.value = true
  try {
    if (props.user) await usersApi.update(props.user.id, input)
    else await usersApi.create({ ...input, username: form.username })
    emit('saved')
  } catch (e) {
    error.value = errorMessage(e)
  } finally {
    busy.value = false
  }
}
</script>

<template>
  <BaseModal
    :title="user ? 'Benutzer bearbeiten' : 'Benutzer anlegen'"
    :subtitle="user ? `Angemeldet als ${user.username}` : 'Zugang für eine neue Person in der Werkstatt'"
    :width="480"
    @close="emit('close')"
  >
    <form class="user-form" @submit.prevent="save">
      <label v-if="!user" class="field">
        <span class="field__label">Benutzername (z. B. a.huber)</span>
        <input v-model="form.username" class="input" autocapitalize="none" spellcheck="false" required />
      </label>

      <div class="user-form__pair">
        <label class="field">
          <span class="field__label">Vorname</span>
          <input v-model="form.firstName" class="input" required />
        </label>
        <label class="field">
          <span class="field__label">Nachname</span>
          <input v-model="form.lastName" class="input" required />
        </label>
      </div>

      <div class="user-form__pair">
        <label class="field">
          <span class="field__label">Rolle</span>
          <select v-model="form.role" class="input" :disabled="isSelf">
            <option v-for="role in roles" :key="role.value" :value="role.value">{{ role.label }}</option>
          </select>
        </label>
        <label class="field">
          <span class="field__label">Wochenstunden</span>
          <input v-model.number="form.weeklyHours" class="input" type="number" min="1" max="60" step="0.5" required />
        </label>
      </div>

      <label class="field">
        <span class="field__label">{{ user ? 'Neues Passwort (leer lassen = unverändert)' : 'Passwort (mindestens 8 Zeichen)' }}</span>
        <input
          v-model="form.password"
          class="input"
          type="password"
          autocomplete="new-password"
          minlength="8"
          :required="!user"
        />
      </label>

      <label v-if="user && !isSelf" class="user-form__active">
        <input v-model="form.active" type="checkbox" />
        Konto aktiv (deaktivierte Konten können sich nicht anmelden)
      </label>

      <p v-if="error" class="form-error" role="alert">{{ error }}</p>

      <footer class="user-form__actions">
        <button type="button" class="btn btn--large" @click="emit('close')">Abbrechen</button>
        <button class="btn btn--primary btn--large" :disabled="busy">Speichern</button>
      </footer>
    </form>
  </BaseModal>
</template>

<style scoped>
.user-form {
  display: grid;
  gap: 16px;
}

.user-form__pair {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 16px;
}

.user-form__active {
  display: flex;
  align-items: center;
  gap: 10px;
  color: var(--muted);
  font-size: 12px;
}

.user-form__active input {
  width: 18px;
  height: 18px;
  margin: 0;
  accent-color: var(--primary);
}

.user-form__actions {
  display: flex;
  justify-content: flex-end;
  gap: 12px;
  padding-top: 20px;
  border-top: 1px solid var(--border);
}
</style>
