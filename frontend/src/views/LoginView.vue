<script setup lang="ts">
import { ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { errorMessage } from '@/api/client'
import { useAuthStore } from '@/stores/auth'

const auth = useAuthStore()
const router = useRouter()
const route = useRoute()

const email = ref('')
const password = ref('')
const error = ref('')

async function submit(): Promise<void> {
  error.value = ''

  try {
    await auth.login(email.value, password.value)
    const redirect =
      typeof route.query.redirect === 'string' ? route.query.redirect : auth.homeRoute
    await router.push(redirect)
  } catch (e) {
    error.value = errorMessage(e)
  }
}
</script>

<template>
  <div class="login card">
    <div class="head">
      <span class="mark">GZ</span>
      <div>
        <h1>Anmelden</h1>
        <p class="muted">Ganglbauer Landtechnik</p>
      </div>
    </div>

    <form @submit.prevent="submit">
      <div class="field">
        <label for="email">E-Mail</label>
        <input id="email" v-model="email" type="email" autocomplete="username" required />
      </div>

      <div class="field">
        <label for="password">Passwort</label>
        <input
          id="password"
          v-model="password"
          type="password"
          autocomplete="current-password"
          required
        />
      </div>

      <p v-if="error" class="error">{{ error }}</p>

      <button type="submit" :disabled="auth.loading">
        {{ auth.loading ? 'Anmelden …' : 'Anmelden' }}
      </button>
    </form>

    <div class="hint muted">
      <p>Die Rolle bestimmt die sichtbaren Ansichten. Demo-Zugänge:</p>
      <ul>
        <li><strong>Chef</strong> <code>meister@ganglbauer.at</code> / <code>admin1234</code></li>
        <li>
          <strong>Vorarbeiter</strong> <code>vorarbeiter@ganglbauer.at</code> /
          <code>test1234</code>
        </li>
        <li><strong>Arbeiter</strong> <code>kevin@ganglbauer.at</code> / <code>test1234</code></li>
      </ul>
    </div>
  </div>
</template>

<style scoped>
.login {
  width: 100%;
  max-width: 380px;
}

.head {
  display: flex;
  align-items: center;
  gap: 0.8rem;
  margin-bottom: 1.25rem;
}

.mark {
  background: var(--primary);
  color: #fff;
  border-radius: 10px;
  width: 42px;
  height: 42px;
  display: grid;
  place-items: center;
  font-weight: 700;
}

.head p {
  margin: 0;
  font-size: 0.875rem;
}

.field {
  margin-bottom: 0.9rem;
}

button {
  width: 100%;
  margin-top: 0.3rem;
}

.hint {
  margin: 1.1rem 0 0;
  font-size: 0.75rem;
}

.hint p {
  margin: 0 0 0.3rem;
}

.hint ul {
  margin: 0;
  padding-left: 1rem;
}
</style>
