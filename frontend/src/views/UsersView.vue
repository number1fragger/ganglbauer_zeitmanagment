<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { usersApi } from '@/api'
import { errorMessage } from '@/api/http'
import type { User } from '@/api/types'
import UserDialog from '@/components/UserDialog.vue'
import { useAuthStore } from '@/stores/auth'
import { permissions, roleOf, roles } from '@/utils/domain'

const auth = useAuthStore()
const users = ref<User[]>([])
const error = ref('')
/** undefined = Dialog zu, null = neuer Benutzer */
const editing = ref<User | null | undefined>(undefined)

async function load(): Promise<void> {
  try {
    users.value = await usersApi.list()
  } catch (e) {
    error.value = errorMessage(e)
  }
}

onMounted(load)

async function onSaved(): Promise<void> {
  editing.value = undefined
  await load()
}
</script>

<template>
  <div class="users">
    <header class="users__header">
      <div>
        <h1 class="page-title">Benutzer &amp; Rechte</h1>
        <p class="page-sub">Nur für die Werkstattleitung sichtbar</p>
      </div>
      <button class="btn btn--primary users__new" @click="editing = null">+ Benutzer anlegen</button>
    </header>

    <main class="users__main">
      <p v-if="error" class="form-error" role="alert">{{ error }}</p>

      <section class="card users__table-wrap">
        <table class="users__table">
          <thead>
            <tr>
              <th scope="col">Benutzer</th>
              <th scope="col">Rolle</th>
              <th v-for="p in permissions" :key="p.key" scope="col" class="users__center">{{ p.label }}</th>
            </tr>
          </thead>
          <tbody>
            <tr
              v-for="user in users"
              :key="user.id"
              :class="{ 'users__row--inactive': !user.active }"
              tabindex="0"
              @click="editing = user"
              @keydown.enter="editing = user"
            >
              <td>
                <span class="users__person">
                  <span class="avatar users__avatar">{{ user.initials }}</span>
                  <span>
                    <strong>{{ user.fullName }}</strong>
                    <small>{{ user.username }}{{ user.active ? '' : ' · deaktiviert' }}</small>
                  </span>
                </span>
              </td>
              <td>
                <span class="users__role" :style="{ '--role': roleOf(user.role).color }">
                  <span class="dot" :style="{ '--dot': 'var(--role)' }" />{{ roleOf(user.role).label }}
                </span>
              </td>
              <td v-for="p in permissions" :key="p.key" class="users__center">
                <span
                  class="users__mark"
                  :class="p.roles.includes(user.role) ? 'users__mark--yes' : 'users__mark--no'"
                  :aria-label="p.roles.includes(user.role) ? 'erlaubt' : 'nicht erlaubt'"
                >
                  {{ p.roles.includes(user.role) ? '✓' : '–' }}
                </span>
              </td>
            </tr>
          </tbody>
        </table>
      </section>

      <section class="card users__roles">
        <h2 class="users__roles-title">Was die Rollen dürfen</h2>
        <div class="users__role-list">
          <div v-for="role in roles" :key="role.value" class="accent users__role-info" :style="{ '--accent': role.color }">
            <strong :style="{ color: role.color }">{{ role.label }}</strong>
            <p>{{ role.description }}</p>
          </div>
        </div>
      </section>
    </main>

    <UserDialog
      v-if="editing !== undefined"
      :user="editing"
      :is-self="editing?.id === auth.user?.id"
      @close="editing = undefined"
      @saved="onSaved"
    />
  </div>
</template>

<style scoped>
.users__header {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  justify-content: space-between;
  gap: 16px;
  padding: 20px 28px 16px;
}



.users__main {
  display: grid;
  gap: 24px;
  max-width: 1080px;
  margin: 0 auto;
  padding: 28px 32px 48px;
}

.users__table-wrap {
  padding: 8px 20px 20px;
  overflow-x: auto;
}

.users__table {
  width: 100%;
  min-width: 860px;
  border-collapse: collapse;
}

.users__table th {
  padding: 12px 0;
  border-bottom: 1px solid var(--border);
  color: var(--muted);
  font-size: 10px;
  font-weight: 600;
  text-align: left;
}

.users__table td {
  padding: 18px 0;
  border-bottom: 1px solid var(--grid);
}

.users__table tbody tr {
  cursor: pointer;
}

.users__table tbody tr:hover td {
  background: var(--surface-alt);
}

.users__row--inactive {
  opacity: 0.55;
}

.users__center {
  text-align: center !important;
}

.users__person {
  display: flex;
  align-items: center;
  gap: 12px;
}

.users__avatar {
  width: 32px;
  height: 32px;
  background: var(--surface-muted);
  color: var(--muted);
}

.users__person strong {
  display: block;
  font-size: 12px;
  font-weight: 600;
}

.users__person small {
  display: block;
  margin-top: 2px;
  color: var(--muted);
  font-size: 10px;
}

.users__role {
  display: inline-flex;
  align-items: center;
  gap: 8px;
  min-width: 108px;
  height: 24px;
  padding: 0 12px;
  border-radius: 12px;
  background: var(--surface-alt);
  color: var(--role);
  font-size: 10px;
  font-weight: 600;
}

.users__mark {
  display: inline-grid;
  place-items: center;
  width: 20px;
  height: 20px;
  border-radius: 50%;
  font-size: 11px;
  font-weight: 700;
}

.users__mark--yes {
  background: var(--success-soft);
  color: var(--success);
}

.users__mark--no {
  background: var(--surface-muted);
  color: var(--faint);
}

.users__roles {
  padding: 18px 20px 20px;
}

.users__roles-title {
  font-size: 12px;
  font-weight: 700;
}

.users__role-list {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
  gap: 28px;
  margin-top: 14px;
}

.users__role-info {
  position: relative;
  padding: 0 0 4px 14px;
}

.users__role-info strong {
  font-size: 11px;
  font-weight: 600;
}

.users__role-info p {
  margin-top: 6px;
  color: var(--muted);
  font-size: 10px;
}
</style>
