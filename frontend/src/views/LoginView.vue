<script setup lang="ts">
import { ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { errorMessage } from '@/api/client'
import { useAuthStore } from '@/stores/auth'

/** Figma 05 – Login. */
const auth = useAuthStore()
const router = useRouter()
const route = useRoute()

const email = ref('')
const password = ref('')
const remember = ref(true)
const showPassword = ref(false)
const forgotten = ref(false)
const error = ref('')

async function submit(): Promise<void> {
  error.value = ''

  try {
    await auth.login(email.value, password.value, remember.value)
    const redirect =
      typeof route.query.redirect === 'string' ? route.query.redirect : auth.homeRoute
    await router.push(redirect)
  } catch (e) {
    error.value = errorMessage(e)
  }
}
</script>

<template>
  <div class="login">
    <aside class="intro">
      <h1>Werkstatt-Planung</h1>
      <p>Arbeitseinteilung, Zeitplanung und Auswertung für die Werkstätte.</p>

      <ul>
        <li>Kundentermine übersichtlich planen</li>
        <li>Sehen, wer wann wieder Arbeit braucht</li>
        <li>Soll/Ist-Zeiten je Arbeiter auswerten</li>
      </ul>

      <footer>Version 1.0 · Ganglbauer Landtechnik</footer>
    </aside>

    <main class="form-side">
      <form @submit.prevent="submit">
        <h2>Anmelden</h2>
        <p class="sub">Bitte mit dem persönlichen Benutzerkonto anmelden.</p>

        <label for="email">Benutzername (E-Mail)</label>
        <input id="email" v-model="email" type="email" autocomplete="username" required />

        <label for="password">Passwort</label>
        <div class="pw">
          <input
            id="password"
            v-model="password"
            :type="showPassword ? 'text' : 'password'"
            autocomplete="current-password"
            required
          />
          <button type="button" class="show" @click="showPassword = !showPassword">
            {{ showPassword ? 'verbergen' : 'anzeigen' }}
          </button>
        </div>

        <div class="row-opts">
          <label class="check"
            ><input v-model="remember" type="checkbox" /> Angemeldet bleiben</label
          >
          <button type="button" class="link" @click="forgotten = !forgotten">
            Passwort vergessen?
          </button>
        </div>
        <p v-if="forgotten" class="forgot">
          Das Passwort setzt der Chef unter „Benutzer &amp; Rechte“ zurück – bitte kurz Bescheid
          geben.
        </p>

        <p v-if="error" class="error">{{ error }}</p>

        <button type="submit" class="submit" :disabled="auth.loading">
          {{ auth.loading ? 'Anmelden …' : 'Anmelden' }}
        </button>

        <div class="roles">
          <strong>Die Rolle bestimmt die sichtbaren Ansichten</strong>
          <div class="roles__grid">
            <span class="r r--chef">
              <b>Chef / Werkstattleitung</b>
              Volle Planung, Auswertung, Benutzerverwaltung
            </span>
            <span class="r r--worker">
              <b>Arbeiter</b>
              Nur eigene Arbeiten + Arbeit anfordern
            </span>
          </div>
        </div>
      </form>
    </main>
  </div>
</template>

<style scoped>
.login {
  display: grid;
  grid-template-columns: 420px 1fr;
  min-height: 100vh;
}

.intro {
  display: flex;
  flex-direction: column;
  padding: 64px 48px 40px;
  background: #1e293b;
  color: #fff;
}

.intro h1 {
  font-size: 1.375rem;
  margin: 0;
}

.intro p {
  margin: 10px 0 0;
  font-size: 0.75rem;
  color: #94a3b8;
  max-width: 300px;
}

.intro ul {
  list-style: none;
  padding: 0;
  margin: 64px 0 0;
  display: flex;
  flex-direction: column;
  gap: 32px;
}

.intro li {
  position: relative;
  padding-left: 20px;
  font-size: 0.75rem;
  font-weight: 500;
  color: #cbd5e1;
}

.intro li::before {
  content: '';
  position: absolute;
  left: 0;
  top: 5px;
  width: 8px;
  height: 8px;
  border-radius: 50%;
  background: var(--primary);
}

.intro footer {
  margin-top: auto;
  padding-top: 20px;
  border-top: 1px solid #334155;
  font-size: 0.625rem;
  color: #64748b;
}

.form-side {
  display: flex;
  align-items: center;
  padding: 3rem 60px;
  background: var(--surface);
}

form {
  width: 100%;
  max-width: 460px;
}

h2 {
  font-size: 1.5rem;
  margin: 0;
}

.sub {
  margin: 6px 0 36px;
  font-size: 0.75rem;
  color: var(--muted);
}

label {
  margin-top: 20px;
}

input:not([type='checkbox']) {
  height: 44px;
}

.pw {
  position: relative;
}

.show {
  position: absolute;
  right: 12px;
  top: 12px;
  height: 20px;
  padding: 0;
  background: none;
  color: var(--primary);
  font-size: 0.625rem;
  font-weight: 500;
}

.row-opts {
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin-top: 24px;
}

.check {
  display: flex;
  align-items: center;
  gap: 10px;
  margin: 0;
  font-size: 0.6875rem;
  font-weight: 400;
  cursor: pointer;
}

.link {
  height: auto;
  padding: 0;
  background: none;
  color: var(--primary);
  font-size: 0.6875rem;
  font-weight: 500;
}

.forgot {
  margin: 10px 0 0;
  font-size: 0.6875rem;
  color: var(--muted);
}

.error {
  margin-top: 16px;
}

.submit {
  width: 100%;
  height: 48px;
  margin-top: 28px;
  font-size: 0.8125rem;
}

.roles {
  margin-top: 28px;
  padding: 16px 20px;
  border-radius: 10px;
  background: var(--surface-muted);
}

.roles strong {
  font-size: 0.6875rem;
  font-weight: 600;
}

.roles__grid {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 16px;
  margin-top: 12px;
}

.r {
  display: flex;
  flex-direction: column;
  gap: 2px;
  padding-left: 12px;
  border-left: 3px solid;
  font-size: 0.625rem;
  color: var(--muted);
}

.r b {
  font-size: 0.6875rem;
  font-weight: 500;
}

.r--chef {
  border-color: var(--primary);
}

.r--chef b {
  color: var(--primary);
}

.r--worker {
  border-color: var(--success);
}

.r--worker b {
  color: var(--success);
}

@media (max-width: 820px) {
  .login {
    grid-template-columns: 1fr;
  }

  .intro {
    padding: 32px 24px;
  }

  .intro ul {
    margin-top: 24px;
    gap: 14px;
  }

  .intro footer {
    display: none;
  }

  .form-side {
    padding: 2rem 24px;
  }
}
</style>
