<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import { errorMessage, http } from '@/api/client'
import type { Role, User } from '@/api/types'
import { useAuthStore } from '@/stores/auth'

/**
 * Benutzer & Rechte – nur fuer den Chef.
 * Das Backend prueft das ebenfalls (ROLE_ADMIN auf /api/users).
 */
const auth = useAuthStore()

const users = ref<User[]>([])
const error = ref('')
const message = ref('')
const formOpen = ref(false)
const busy = ref(false)

const roles: { value: Role; label: string; description: string }[] = [
  {
    value: 'ROLE_ADMIN',
    label: 'Chef',
    description: 'Alles: planen, auswerten, Benutzer verwalten',
  },
  {
    value: 'ROLE_FOREMAN',
    label: 'Vorarbeiter',
    description: 'Planen, Zeiten ändern, Auswertung – keine Benutzer',
  },
  {
    value: 'ROLE_USER',
    label: 'Arbeiter',
    description: 'Nur eigene Arbeiten, abhaken, Arbeit anfordern',
  },
]

/** Welche Rolle was darf – dieselbe Matrix wie im Backend (JobVoter, access_control). */
const permissions: { label: string; minRole: Role }[] = [
  { label: 'Eigene', minRole: 'ROLE_USER' },
  { label: 'Anfordern', minRole: 'ROLE_USER' },
  { label: 'Planen', minRole: 'ROLE_FOREMAN' },
  { label: 'Auswertung', minRole: 'ROLE_FOREMAN' },
  { label: 'Benutzer', minRole: 'ROLE_ADMIN' },
]

const RANK: Record<Role, number> = { ROLE_USER: 0, ROLE_FOREMAN: 1, ROLE_ADMIN: 2 }
const allowed = (role: Role, minRole: Role) => RANK[role] >= RANK[minRole]

const emptyForm = () => ({
  firstName: '',
  lastName: '',
  email: '',
  password: '',
  role: 'ROLE_USER' as Role,
  weeklyHours: 38.5,
})
const form = reactive(emptyForm())

const sorted = computed(() =>
  [...users.value].sort(
    (a, b) => Number(b.active) - Number(a.active) || RANK[b.role] - RANK[a.role],
  ),
)

async function load(): Promise<void> {
  try {
    const { data } = await http.get<User[]>('/api/users')
    users.value = data
  } catch (e) {
    error.value = errorMessage(e)
  }
}

onMounted(load)

async function run(action: () => Promise<unknown>, success: string): Promise<void> {
  error.value = ''
  message.value = ''
  busy.value = true

  try {
    await action()
    message.value = success
    await load()
  } catch (e) {
    error.value = errorMessage(e)
    await load()
  } finally {
    busy.value = false
  }
}

async function create(): Promise<void> {
  await run(async () => {
    await http.post('/api/users', form)
    Object.assign(form, emptyForm())
    formOpen.value = false
  }, 'Benutzer angelegt.')
}

function changeRole(user: User, role: Role): Promise<void> {
  return run(
    () => http.patch(`/api/users/${user.id}`, { role }),
    `${user.fullName} ist jetzt ${roles.find((r) => r.value === role)?.label}.`,
  )
}

function toggleActive(user: User): Promise<void> {
  return run(
    () => http.patch(`/api/users/${user.id}`, { active: !user.active }),
    user.active ? `${user.fullName} wurde deaktiviert.` : `${user.fullName} ist wieder aktiv.`,
  )
}

function changeHours(user: User, value: string): Promise<void> {
  return run(
    () => http.patch(`/api/users/${user.id}`, { weeklyHours: Number(value) }),
    'Wochenstunden gespeichert.',
  )
}

async function resetPassword(user: User): Promise<void> {
  const password = window.prompt(`Neues Passwort für ${user.fullName} (mind. 8 Zeichen):`)
  if (!password) return

  await run(
    () => http.patch(`/api/users/${user.id}`, { password }),
    `Passwort für ${user.fullName} geändert.`,
  )
}
</script>

<template>
  <div>
    <div class="head">
      <div>
        <h1>Benutzer & Rechte</h1>
        <p class="muted">
          Die Rolle bestimmt, welche Seiten jemand sieht und was er ändern darf. „Planen“ heißt:
          Arbeiten anlegen, bearbeiten, zuteilen und Anfragen erledigen.
        </p>
      </div>
      <button type="button" @click="formOpen = !formOpen">
        {{ formOpen ? 'Schließen' : '+ Benutzer anlegen' }}
      </button>
    </div>

    <p v-if="error" class="error">{{ error }}</p>
    <p v-if="message" class="success">{{ message }}</p>

    <section v-if="formOpen" class="card">
      <h2>Neuer Benutzer</h2>
      <form class="form" @submit.prevent="create">
        <div>
          <label for="u-first">Vorname</label>
          <input id="u-first" v-model="form.firstName" required />
        </div>
        <div>
          <label for="u-last">Nachname</label>
          <input id="u-last" v-model="form.lastName" required />
        </div>
        <div>
          <label for="u-mail">E-Mail (Anmeldename)</label>
          <input id="u-mail" v-model="form.email" type="email" required />
        </div>
        <div>
          <label for="u-pass">Passwort</label>
          <input
            id="u-pass"
            v-model="form.password"
            type="password"
            minlength="8"
            autocomplete="new-password"
            required
          />
        </div>
        <div>
          <label for="u-role">Rolle</label>
          <select id="u-role" v-model="form.role">
            <option v-for="r in roles" :key="r.value" :value="r.value">{{ r.label }}</option>
          </select>
        </div>
        <div>
          <label for="u-hours">Wochenstunden</label>
          <input
            id="u-hours"
            v-model.number="form.weeklyHours"
            type="number"
            step="0.5"
            min="1"
            required
          />
        </div>
        <div class="actions wide">
          <button type="submit" :disabled="busy">Anlegen</button>
        </div>
      </form>
    </section>

    <section class="card table-wrap">
      <table>
        <thead>
          <tr>
            <th>Benutzer</th>
            <th>Rolle</th>
            <th v-for="p in permissions" :key="p.label" class="center">{{ p.label }}</th>
            <th title="Wochenstunden">Std.</th>
            <th></th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="user in sorted" :key="user.id" :class="{ inactive: !user.active }">
            <td>
              <div class="who">
                <span class="avatar">{{ user.initials }}</span>
                <span>
                  <strong>{{ user.fullName }}</strong>
                  <span class="muted small block">{{ user.email }}</span>
                </span>
              </div>
            </td>
            <td>
              <select
                :value="user.role"
                :disabled="busy || user.id === auth.user?.id"
                :title="user.id === auth.user?.id ? 'Die eigene Rolle kann man nicht ändern' : ''"
                @change="changeRole(user, ($event.target as HTMLSelectElement).value as Role)"
              >
                <option v-for="r in roles" :key="r.value" :value="r.value">{{ r.label }}</option>
              </select>
            </td>
            <td v-for="p in permissions" :key="p.label" class="center">
              <span :class="allowed(user.role, p.minRole) ? 'yes' : 'no'">
                {{ allowed(user.role, p.minRole) ? '✓' : '–' }}
              </span>
            </td>
            <td>
              <input
                class="hours"
                type="number"
                step="0.5"
                min="1"
                :value="user.weeklyHours"
                :disabled="busy"
                @change="changeHours(user, ($event.target as HTMLInputElement).value)"
              />
            </td>
            <td class="right">
              <button
                type="button"
                class="secondary small"
                :disabled="busy"
                @click="resetPassword(user)"
              >
                Passwort
              </button>
              <button
                v-if="user.id !== auth.user?.id"
                type="button"
                class="small"
                :class="user.active ? 'danger' : 'secondary'"
                :disabled="busy"
                @click="toggleActive(user)"
              >
                {{ user.active ? 'Deaktivieren' : 'Aktivieren' }}
              </button>
            </td>
          </tr>
        </tbody>
      </table>
    </section>

    <section class="card roles">
      <div v-for="r in roles" :key="r.value" :class="`role role--${r.value}`">
        <strong>{{ r.label }}</strong>
        <span class="muted small">{{ r.description }}</span>
      </div>
    </section>
  </div>
</template>

<style scoped>
.head {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 1rem;
  margin-bottom: 1rem;
}

section {
  margin-bottom: 1rem;
}

.success {
  background: color-mix(in srgb, var(--success) 12%, transparent);
  color: var(--success);
  border-radius: 8px;
  padding: 0.6rem 0.8rem;
  font-size: 0.875rem;
}

.form {
  display: grid;
  grid-template-columns: repeat(2, 1fr);
  gap: 0.9rem;
}

.wide {
  grid-column: 1 / -1;
}

.table-wrap {
  overflow-x: auto;
  padding: 0.5rem 1rem;
}

.who {
  display: flex;
  align-items: center;
  gap: 0.6rem;
}

.avatar {
  width: 32px;
  height: 32px;
  flex: none;
  border-radius: 50%;
  display: grid;
  place-items: center;
  background: var(--bg);
  font-weight: 700;
  font-size: 0.75rem;
  color: var(--muted);
}

.block {
  display: block;
}

select {
  min-width: 110px;
}

.hours {
  width: 68px;
}

td,
th {
  padding-left: 0.45rem;
  padding-right: 0.45rem;
}

.center {
  text-align: center;
}

.right {
  text-align: right;
  white-space: nowrap;
}

.right button + button {
  margin-left: 0.4rem;
}

.yes {
  color: var(--success);
  font-weight: 700;
}

.no {
  color: var(--muted);
}

tr.inactive {
  opacity: 0.5;
}

.small {
  font-size: 0.75rem;
}

button.small {
  padding: 0.3rem 0.6rem;
}

.roles {
  display: grid;
  grid-template-columns: repeat(3, 1fr);
  gap: 1rem;
}

.role {
  display: flex;
  flex-direction: column;
  padding-left: 0.75rem;
  border-left: 3px solid;
}

.role--ROLE_ADMIN {
  border-color: var(--primary);
}

.role--ROLE_FOREMAN {
  border-color: var(--warning);
}

.role--ROLE_USER {
  border-color: var(--success);
}

@media (max-width: 720px) {
  .form,
  .roles {
    grid-template-columns: 1fr;
  }
}
</style>
