<script setup lang="ts">
import { reactive, ref, watch } from 'vue'
import { RouterLink } from 'vue-router'
import { errorMessage, http } from '@/api/client'
import { useAuthStore } from '@/stores/auth'

const auth = useAuthStore()

const profile = reactive({
  firstName: auth.user?.firstName ?? '',
  lastName: auth.user?.lastName ?? '',
  weeklyHours: auth.user?.weeklyHours ?? 38.5,
})

const passwords = reactive({
  currentPassword: '',
  newPassword: '',
})

const profileMessage = ref('')
const passwordMessage = ref('')
const error = ref('')

watch(
  () => auth.user,
  (user) => {
    if (!user) return

    profile.firstName = user.firstName
    profile.lastName = user.lastName
    profile.weeklyHours = user.weeklyHours
  },
  { immediate: true },
)

async function saveProfile(): Promise<void> {
  error.value = ''
  profileMessage.value = ''

  try {
    await http.patch('/api/me', profile)
    await auth.loadUser()
    profileMessage.value = 'Profil gespeichert.'
  } catch (e) {
    error.value = errorMessage(e)
  }
}

async function changePassword(): Promise<void> {
  error.value = ''
  passwordMessage.value = ''

  try {
    await http.put('/api/me/password', passwords)
    passwords.currentPassword = ''
    passwords.newPassword = ''
    passwordMessage.value = 'Passwort geaendert.'
  } catch (e) {
    error.value = errorMessage(e)
  }
}
</script>

<template>
  <div class="page-head">
    <div>
      <h1>Einstellungen</h1>
      <p>Profil und Passwort</p>
    </div>
  </div>

  <div class="page-body">
    <p class="back"><RouterLink :to="auth.homeRoute">← zurück</RouterLink></p>

    <p v-if="error" class="error">{{ error }}</p>

    <section class="card">
      <h2>Profil</h2>

      <form class="form" @submit.prevent="saveProfile">
        <div>
          <label for="firstName">Vorname</label>
          <input id="firstName" v-model="profile.firstName" type="text" required />
        </div>

        <div>
          <label for="lastName">Nachname</label>
          <input id="lastName" v-model="profile.lastName" type="text" required />
        </div>

        <div>
          <label for="hours">Wochenstunden</label>
          <input id="hours" v-model.number="profile.weeklyHours" type="number" step="0.5" min="1" />
        </div>

        <div class="actions">
          <button type="submit">Speichern</button>
        </div>
      </form>

      <p v-if="profileMessage" class="ok">{{ profileMessage }}</p>
    </section>

    <section class="card">
      <h2>Passwort aendern</h2>

      <form class="form" @submit.prevent="changePassword">
        <div>
          <label for="current">Aktuelles Passwort</label>
          <input
            id="current"
            v-model="passwords.currentPassword"
            type="password"
            autocomplete="current-password"
            required
          />
        </div>

        <div>
          <label for="new">Neues Passwort</label>
          <input
            id="new"
            v-model="passwords.newPassword"
            type="password"
            autocomplete="new-password"
            minlength="8"
            required
          />
        </div>

        <div class="actions">
          <button type="submit">Aendern</button>
        </div>
      </form>

      <p v-if="passwordMessage" class="ok">{{ passwordMessage }}</p>
    </section>

    <section class="card">
      <h2>Konto</h2>
      <p class="muted">
        Angemeldet als <strong>{{ auth.user?.email }}</strong>
        <span class="badge">{{ auth.user?.roleLabel }}</span>
      </p>
    </section>
  </div>
</template>

<style scoped>
section {
  margin-bottom: 1.25rem;
}

.form {
  display: grid;
  grid-template-columns: repeat(2, 1fr);
  gap: 0.9rem;
  align-items: end;
}

.actions {
  grid-column: 1 / -1;
}

.ok {
  color: var(--success);
  font-size: 0.875rem;
  margin-bottom: 0;
}

@media (max-width: 720px) {
  .form {
    grid-template-columns: 1fr;
  }
}
.back {
  margin: 0 0 1rem;
  font-size: 0.75rem;
}

section + section {
  margin-top: 1rem;
}
</style>
