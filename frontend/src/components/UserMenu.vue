<script setup lang="ts">
import { onBeforeUnmount, onMounted, ref } from 'vue'
import { useAuthStore } from '@/stores/auth'
import { roleOf } from '@/utils/domain'
import PasswordDialog from './PasswordDialog.vue'

const auth = useAuthStore()
const open = ref(false)
const changingPassword = ref(false)
const root = ref<HTMLElement>()

function closeOnOutsideClick(event: MouseEvent): void {
  if (!root.value?.contains(event.target as Node)) open.value = false
}

onMounted(() => document.addEventListener('click', closeOnOutsideClick))
onBeforeUnmount(() => document.removeEventListener('click', closeOnOutsideClick))

function openPasswordDialog(): void {
  open.value = false
  changingPassword.value = true
}
</script>

<template>
  <div v-if="auth.user" ref="root" class="user-menu">
    <button class="user-menu__chip" :aria-expanded="open" aria-haspopup="menu" @click="open = !open">
      <span class="avatar user-menu__avatar">{{ auth.user.initials }}</span>
      <span class="user-menu__text">
        <strong>{{ auth.user.fullName }}</strong>
        <small>{{ roleOf(auth.user.role).label }}</small>
      </span>
      <span class="user-menu__caret" aria-hidden="true">▾</span>
    </button>

    <div v-if="open" class="user-menu__list" role="menu">
      <RouterLink role="menuitem" :to="{ name: 'planning' }" v-if="auth.isPlanner">Planung</RouterLink>
      <RouterLink role="menuitem" :to="{ name: 'my-work' }">Meine Arbeiten</RouterLink>
      <RouterLink role="menuitem" :to="{ name: 'report' }" v-if="auth.isPlanner">Auswertung</RouterLink>
      <RouterLink role="menuitem" :to="{ name: 'users' }" v-if="auth.isChef">Benutzer &amp; Rechte</RouterLink>
      <button role="menuitem" @click="openPasswordDialog">Passwort ändern</button>
      <button role="menuitem" @click="auth.logout()">Abmelden</button>
    </div>

    <PasswordDialog v-if="changingPassword" @close="changingPassword = false" />
  </div>
</template>

<style scoped>
.user-menu {
  position: relative;
}

.user-menu__chip {
  display: flex;
  align-items: center;
  gap: 8px;
  height: 40px;
  padding: 4px 16px 4px 4px;
  border: 0;
  border-radius: 20px;
  background: var(--primary-soft);
  text-align: left;
}

.user-menu__avatar {
  width: 32px;
  height: 32px;
  background: var(--surface);
}

.user-menu__text {
  display: flex;
  flex-direction: column;
  min-width: 80px;
  font-size: 11px;
}

.user-menu__text strong {
  font-weight: 600;
}

.user-menu__text small {
  color: var(--primary);
  font-size: 10px;
  font-weight: 500;
}

.user-menu__caret {
  margin-left: 12px;
  color: var(--muted);
  font-size: 10px;
}

.user-menu__list {
  position: absolute;
  z-index: 20;
  top: calc(100% + 6px);
  right: 0;
  display: flex;
  flex-direction: column;
  min-width: 200px;
  padding: 6px;
  border: 1px solid var(--border);
  border-radius: var(--radius-lg);
  background: var(--surface);
  box-shadow: 0 10px 30px rgb(15 23 42 / 0.12);
}

.user-menu__list a,
.user-menu__list button {
  padding: 9px 12px;
  border: 0;
  border-radius: var(--radius-sm);
  background: none;
  color: var(--text);
  font-size: 12px;
  text-align: left;
  text-decoration: none;
}

.user-menu__list a:hover,
.user-menu__list button:hover {
  background: var(--surface-muted);
}
</style>
