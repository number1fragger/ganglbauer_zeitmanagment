<script setup lang="ts">
import { ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { errorMessage } from '@/api/http'
import { useAuthStore } from '@/stores/auth'

const auth = useAuthStore()
const router = useRouter()
const route = useRoute()

const username = ref('')
const password = ref('')
const remember = ref(true)
const showPassword = ref(false)
const showForgotHint = ref(false)
const error = ref('')
const busy = ref(false)

async function submit(): Promise<void> {
  error.value = ''
  busy.value = true

  try {
    await auth.login(username.value, password.value, remember.value)
    const redirect = typeof route.query.redirect === 'string' ? route.query.redirect : '/'
    await router.replace(redirect)
  } catch (e) {
    error.value = errorMessage(e)
  } finally {
    busy.value = false
  }
}
</script>

<template>
  <div class="login">
    <aside class="login__intro">
      <h1 class="login__brand">Werkstatt-Planung</h1>
      <p class="login__claim">Arbeitseinteilung, Zeitplanung und Auswertung für die Werkstätte.</p>

      <ul class="login__features">
        <li>Kundentermine übersichtlich planen</li>
        <li>Sehen, wer wann wieder Arbeit braucht</li>
        <li>Soll/Ist-Zeiten je Arbeiter auswerten</li>
      </ul>

      <p class="login__footer">Version 1.0 · Ganglbauer Landtechnik</p>
    </aside>

    <main class="login__main">
      <form class="login__form" @submit.prevent="submit">
        <h2 class="login__title">Anmelden</h2>
        <p class="page-sub">Bitte mit dem persönlichen Benutzerkonto anmelden.</p>

        <label class="field login__field">
          <span class="field__label">Benutzername</span>
          <input
            v-model="username"
            class="input input--large"
            autocomplete="username"
            autocapitalize="none"
            spellcheck="false"
            placeholder="z. B. p.hofer"
            required
          />
        </label>

        <label class="field login__field">
          <span class="field__label">Passwort</span>
          <span class="login__password">
            <input
              v-model="password"
              class="input input--large"
              :type="showPassword ? 'text' : 'password'"
              autocomplete="current-password"
              required
            />
            <button type="button" class="link login__toggle" @click="showPassword = !showPassword">
              {{ showPassword ? 'verbergen' : 'anzeigen' }}
            </button>
          </span>
        </label>

        <div class="login__row">
          <label class="login__remember">
            <input v-model="remember" type="checkbox" />
            Angemeldet bleiben
          </label>
          <button type="button" class="link" @click="showForgotHint = !showForgotHint">
            Passwort vergessen?
          </button>
        </div>

        <p v-if="showForgotHint" class="login__hint">
          Die Werkstattleitung kann dein Passwort unter „Benutzer &amp; Rechte“ neu setzen.
        </p>

        <p v-if="error" class="form-error" role="alert">{{ error }}</p>

        <button class="btn btn--primary login__submit" :disabled="busy">
          {{ busy ? 'Anmelden …' : 'Anmelden' }}
        </button>

        <section class="login__roles">
          <h3 class="login__roles-title">Die Rolle bestimmt die sichtbaren Ansichten</h3>
          <div class="login__role-list">
            <div class="login__role accent" style="--accent: var(--primary)">
              <strong style="color: var(--primary)">Chef / Werkstattleitung</strong>
              <span>Volle Planung, Auswertung, Benutzerverwaltung</span>
            </div>
            <div class="login__role accent" style="--accent: var(--success)">
              <strong style="color: var(--success)">Arbeiter</strong>
              <span>Nur eigene Arbeiten + Arbeit anfordern</span>
            </div>
          </div>
        </section>
      </form>
    </main>
  </div>
</template>

<style scoped>
.login {
  display: grid;
  grid-template-columns: minmax(300px, 420px) 1fr;
  min-height: 100vh;
}

.login__intro {
  display: flex;
  flex-direction: column;
  padding: 64px 48px 48px;
  background: var(--night);
  color: #fff;
}

.login__brand {
  font-size: 22px;
  font-weight: 700;
}

.login__claim {
  max-width: 300px;
  margin-top: 8px;
  color: var(--night-muted);
  font-size: 12px;
}

.login__features {
  display: grid;
  gap: 36px;
  margin: 64px 0 0;
  padding: 0;
  list-style: none;
}

.login__features li {
  display: flex;
  align-items: center;
  gap: 12px;
  color: var(--night-text);
  font-size: 12px;
  font-weight: 500;
}

.login__features li::before {
  content: '';
  width: 8px;
  height: 8px;
  border-radius: 50%;
  background: var(--primary);
}

.login__footer {
  margin-top: auto;
  padding-top: 20px;
  border-top: 1px solid var(--night-line);
  color: #64748b;
  font-size: 10px;
}

.login__main {
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 48px 24px;
  background: var(--surface);
}

.login__form {
  display: flex;
  flex-direction: column;
  width: 100%;
  max-width: 460px;
}

.login__title {
  font-size: 24px;
  font-weight: 700;
}

.login__field {
  margin-top: 20px;
}

.login__field:first-of-type {
  margin-top: 36px;
}

.input--large {
  height: 44px;
  padding: 0 16px;
}

.login__password {
  position: relative;
}

.login__password .input {
  padding-right: 80px;
}

.login__toggle {
  position: absolute;
  top: 50%;
  right: 16px;
  font-size: 10px;
  transform: translateY(-50%);
}

.login__row {
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin-top: 24px;
}

.login__remember {
  display: flex;
  align-items: center;
  gap: 10px;
  color: var(--muted);
  font-size: 11px;
}

.login__remember input {
  width: 18px;
  height: 18px;
  margin: 0;
  accent-color: var(--primary);
}

.login__hint {
  margin-top: 12px;
  color: var(--muted);
  font-size: 11px;
}

.login__form .form-error {
  margin-top: 16px;
}

.login__submit {
  height: 48px;
  margin-top: 30px;
  font-size: 13px;
}

.login__roles {
  margin-top: 28px;
  padding: 16px 20px;
  border-radius: var(--radius-lg);
  background: var(--surface-alt);
}

.login__roles-title {
  font-size: 11px;
  font-weight: 600;
}

.login__role-list {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 16px;
  margin-top: 12px;
}

.login__role {
  position: relative;
  display: flex;
  flex-direction: column;
  gap: 4px;
  padding-left: 14px;
  font-size: 10px;
  color: var(--muted);
}

.login__role strong {
  font-size: 11px;
  font-weight: 500;
}

@media (max-width: 760px) {
  .login {
    grid-template-columns: 1fr;
  }

  .login__intro {
    padding: 32px 24px;
  }

  .login__features {
    display: none;
  }
}
</style>
