<script setup>
import { computed, onBeforeUnmount, onMounted, reactive, ref } from 'vue'
import { api } from '@/api/client'
import { useAuthStore } from '@/stores/auth'

/** Benutzer & Rechte – nur fuer den Chef. Das Backend prueft das ebenfalls. */
const auth = useAuthStore()

const users = ref([])
const error = ref('')
const message = ref('')
const busy = ref(false)
const formOpen = ref(false)
const menuFor = ref(null)

const roles = [
  {
    value: 'ROLE_ADMIN',
    label: 'Chef',
    color: 'var(--primary)',
    description:
      'Alle Arbeiten planen und zuteilen, Zeiten ändern, Auswertung einsehen, Benutzer verwalten.',
  },
  {
    value: 'ROLE_FOREMAN',
    label: 'Vorarbeiter',
    color: 'var(--warning)',
    description:
      'Arbeiten planen und Zeiten ändern, Auswertung einsehen – keine Benutzerverwaltung.',
  },
  {
    value: 'ROLE_USER',
    label: 'Arbeiter',
    color: 'var(--success)',
    description: 'Nur eigene Arbeiten sehen, abhaken und Arbeit anfordern.',
  },
]

/** Dieselbe Matrix wie im Backend (JobVoter, access_control). */
const permissions = [
  { label: 'Planung', minRole: 'ROLE_FOREMAN' },
  { label: 'Eigene Arbeiten', minRole: 'ROLE_USER' },
  { label: 'Zeit ändern', minRole: 'ROLE_USER' },
  { label: 'Auswertung', minRole: 'ROLE_FOREMAN' },
  { label: 'Benutzer', minRole: 'ROLE_ADMIN' },
]

const RANK = { ROLE_USER: 0, ROLE_FOREMAN: 1, ROLE_ADMIN: 2 }
const allowed = (role, minRole) => RANK[role] >= RANK[minRole]
const roleOf = (value) => roles.find((r) => r.value === value) ?? roles[2]

const emptyForm = () => ({
  firstName: '',
  lastName: '',
  email: '',
  password: '',
  role: 'ROLE_USER',
  weeklyHours: 38.5,
})
const form = reactive(emptyForm())

const sorted = computed(() =>
  [...users.value].sort(
    (a, b) =>
      b.active - a.active || RANK[b.role] - RANK[a.role] || a.lastName.localeCompare(b.lastName),
  ),
)

async function load() {
  try {
    users.value = await api.get('/api/users')
  } catch (e) {
    error.value = e.message
  }
}

const closeMenu = () => (menuFor.value = null)
onMounted(() => {
  load()
  document.addEventListener('click', closeMenu)
})
onBeforeUnmount(() => document.removeEventListener('click', closeMenu))

async function run(action, success) {
  error.value = ''
  message.value = ''
  busy.value = true
  try {
    await action()
    message.value = success
  } catch (e) {
    error.value = e.message
  } finally {
    busy.value = false
    load()
  }
}

const create = () =>
  run(async () => {
    await api.post('/api/users', form)
    Object.assign(form, emptyForm())
    formOpen.value = false
  }, 'Benutzer angelegt.')

const changeRole = (user, role) =>
  run(
    () => api.patch(`/api/users/${user.id}`, { role }),
    `${user.fullName} ist jetzt ${roleOf(role).label}.`,
  )

const toggleActive = (user) =>
  run(
    () => api.patch(`/api/users/${user.id}`, { active: !user.active }),
    user.active ? `${user.fullName} wurde deaktiviert.` : `${user.fullName} ist wieder aktiv.`,
  )

function changeHours(user) {
  const value = window.prompt(
    `Wochenstunden für ${user.fullName}:`,
    String(user.weeklyHours).replace('.', ','),
  )
  if (value) {
    run(
      () => api.patch(`/api/users/${user.id}`, { weeklyHours: Number(value.replace(',', '.')) }),
      'Wochenstunden gespeichert.',
    )
  }
}

function resetPassword(user) {
  const password = window.prompt(`Neues Passwort für ${user.fullName} (mind. 8 Zeichen):`)
  if (password)
    run(
      () => api.patch(`/api/users/${user.id}`, { password }),
      `Passwort für ${user.fullName} geändert.`,
    )
}
</script>

<template>
  <div class="page-head">
    <div>
      <h1>Benutzer &amp; Rechte</h1>
      <p>Nur für die Werkstattleitung sichtbar</p>
    </div>
    <div class="head-actions">
      <span class="role-chip"><i></i>Rolle: {{ auth.user?.roleLabel }}</span>
      <button type="button" @click="formOpen = true">+ Benutzer anlegen</button>
    </div>
  </div>

  <div class="page-body">
    <p v-if="error" class="error">{{ error }}</p>
    <p v-if="message" class="success">{{ message }}</p>

    <section class="panel table-wrap">
      <table>
        <thead>
          <tr>
            <th>Benutzer</th>
            <th>Rolle</th>
            <th v-for="p in permissions" :key="p.label" class="center">{{ p.label }}</th>
            <th></th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="user in sorted" :key="user.id" :class="{ inactive: !user.active }">
            <td>
              <div class="who">
                <span class="avatar grey">{{ user.initials }}</span>
                <span>
                  <strong>{{ user.fullName }}</strong>
                  <small
                    >{{ user.email }}<template v-if="!user.active"> · deaktiviert</template></small
                  >
                </span>
              </div>
            </td>
            <td>
              <label class="pill" :style="{ '--c': roleOf(user.role).color }">
                <i></i>
                <select
                  :value="user.role"
                  :disabled="busy || user.id === auth.user?.id"
                  :title="
                    user.id === auth.user?.id
                      ? 'Die eigene Rolle kann man nicht ändern'
                      : 'Rolle ändern'
                  "
                  @change="changeRole(user, $event.target.value)"
                >
                  <option v-for="r in roles" :key="r.value" :value="r.value">{{ r.label }}</option>
                </select>
              </label>
            </td>
            <td v-for="p in permissions" :key="p.label" class="center">
              <span class="perm" :class="allowed(user.role, p.minRole) ? 'yes' : 'no'">
                {{ allowed(user.role, p.minRole) ? '✓' : '–' }}
              </span>
            </td>
            <td class="more-cell">
              <button
                type="button"
                class="more"
                aria-label="Weitere Aktionen"
                @click.stop="menuFor = menuFor === user.id ? null : user.id"
              >
                ⋯
              </button>
              <div v-if="menuFor === user.id" class="menu" @click.stop="menuFor = null">
                <button type="button" @click="changeHours(user)">
                  Wochenstunden ({{ String(user.weeklyHours).replace('.', ',') }} h)
                </button>
                <button type="button" @click="resetPassword(user)">Passwort zurücksetzen</button>
                <button
                  v-if="user.id !== auth.user?.id"
                  type="button"
                  class="red"
                  @click="toggleActive(user)"
                >
                  {{ user.active ? 'Deaktivieren' : 'Aktivieren' }}
                </button>
              </div>
            </td>
          </tr>
        </tbody>
      </table>
    </section>

    <section class="panel">
      <h2>Was die Rollen dürfen</h2>
      <div class="roles">
        <div v-for="r in roles" :key="r.value" class="role" :style="{ '--c': r.color }">
          <strong>{{ r.label }}</strong>
          <span>{{ r.description }}</span>
        </div>
      </div>
    </section>
  </div>

  <div v-if="formOpen" class="overlay" @click.self="formOpen = false">
    <form class="dialog" @submit.prevent="create">
      <h1>Benutzer anlegen</h1>
      <p class="sub">Zugang für einen neuen Mitarbeiter</p>
      <hr />
      <div class="two">
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
          <label for="u-pass">Passwort (mind. 8 Zeichen)</label>
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
      </div>
      <hr />
      <div class="actions">
        <button type="button" class="secondary" @click="formOpen = false">Abbrechen</button>
        <button type="submit" :disabled="busy">Anlegen</button>
      </div>
    </form>
  </div>
</template>

<style scoped>
.head-actions {
  display: flex;
  align-items: center;
  gap: 20px;
}

.role-chip {
  display: inline-flex;
  align-items: center;
  gap: 8px;
  height: 26px;
  padding: 0 14px;
  border-radius: 13px;
  background: var(--primary-soft);
  color: var(--primary);
  font-size: 0.6875rem;
  font-weight: 600;
}

.role-chip i {
  width: 8px;
  height: 8px;
  border-radius: 50%;
  background: currentColor;
}

.page-body {
  display: flex;
  flex-direction: column;
  gap: 24px;
}

.panel {
  background: var(--surface);
  border-radius: 10px;
  padding: 8px 20px 12px;
}

.table-wrap {
  overflow-x: auto;
}

th {
  padding-top: 20px;
}

tr:last-child td {
  border-bottom: none;
}

.who {
  display: flex;
  align-items: center;
  gap: 12px;
}

.avatar.grey {
  width: 32px;
  height: 32px;
  background: var(--button);
  color: var(--muted);
}

.who strong {
  display: block;
  font-size: 0.75rem;
  font-weight: 600;
}

.who small {
  font-size: 0.625rem;
  color: var(--muted);
}

.pill {
  position: relative;
  display: inline-flex;
  align-items: center;
  margin: 0;
  width: 116px;
  height: 24px;
  border-radius: 12px;
  background: var(--surface-muted);
}

.pill i {
  position: absolute;
  left: 12px;
  width: 8px;
  height: 8px;
  border-radius: 50%;
  background: var(--c);
  pointer-events: none;
}

.pill select {
  height: 24px;
  padding: 0 8px 0 26px;
  background: transparent;
  color: var(--c);
  font-size: 0.625rem;
  font-weight: 600;
  cursor: pointer;
  appearance: none;
}

.pill select:disabled {
  cursor: default;
  opacity: 1;
}

.center {
  text-align: center;
}

.perm {
  display: inline-grid;
  place-items: center;
  width: 20px;
  height: 20px;
  border-radius: 50%;
  font-size: 0.6875rem;
  font-weight: 700;
}

.perm.yes {
  background: var(--success-soft);
  color: var(--success);
}

.perm.no {
  background: var(--button);
  color: var(--faint);
}

tr.inactive td {
  opacity: 0.45;
}

tr.inactive td.more-cell {
  opacity: 1;
}

.more-cell {
  position: relative;
  width: 40px;
  text-align: right;
}

.more {
  width: 28px;
  height: 28px;
  padding: 0;
  background: none;
  color: var(--muted);
  font-size: 1rem;
}

.more:hover {
  background: var(--button);
}

.menu {
  position: absolute;
  right: 8px;
  top: 44px;
  z-index: 5;
  min-width: 210px;
  padding: 4px;
  background: var(--surface);
  border: 1px solid var(--border);
  border-radius: 10px;
  box-shadow: 0 8px 24px rgba(16, 24, 40, 0.12);
}

.menu button {
  display: block;
  width: 100%;
  height: auto;
  padding: 8px 12px;
  background: none;
  color: var(--text);
  font-weight: 500;
  text-align: left;
}

.menu button:hover {
  background: var(--button);
}

.menu .red {
  color: var(--danger);
}

.panel h2 {
  margin: 12px 0 16px;
  font-size: 0.75rem;
}

.roles {
  display: grid;
  grid-template-columns: repeat(3, 1fr);
  gap: 24px;
  padding-bottom: 12px;
}

.role {
  display: flex;
  flex-direction: column;
  gap: 4px;
  padding-left: 14px;
  border-left: 3px solid var(--c);
}

.role strong {
  font-size: 0.6875rem;
  font-weight: 600;
  color: var(--c);
}

.role span {
  font-size: 0.625rem;
  color: var(--muted);
}

/* Dialog im Stil von "Arbeit anlegen" */
.overlay {
  position: fixed;
  inset: 0;
  z-index: 50;
  background: rgba(31, 36, 48, 0.4);
  display: flex;
  justify-content: center;
  align-items: flex-start;
  padding: 4rem 1rem;
}

.dialog {
  width: 560px;
  max-width: 100%;
  padding: 2rem;
  border-radius: 12px;
  background: var(--surface);
}

.dialog h1 {
  margin: 0;
}

.sub {
  margin: 2px 0 0;
  font-size: 0.75rem;
  color: var(--muted);
}

hr {
  border: none;
  border-top: 1px solid var(--border);
  margin: 1.5rem 0;
}

.two {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 1.1rem 1.25rem;
}

.actions {
  display: flex;
  justify-content: flex-end;
  gap: 12px;
}

.actions button {
  height: 44px;
  min-width: 104px;
}

@media (max-width: 720px) {
  .roles,
  .two {
    grid-template-columns: 1fr;
  }
}
</style>
