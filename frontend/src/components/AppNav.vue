<script setup lang="ts">
import { computed } from 'vue'
import { RouterLink, useRouter } from 'vue-router'
import type { Role } from '@/api/types'
import { useAuthStore } from '@/stores/auth'
import { useWorkshopStore } from '@/stores/workshop'

const auth = useAuthStore()
const workshop = useWorkshopStore()
const router = useRouter()

/** Jede Rolle sieht nur die Seiten, die sie auch benutzen darf. */
const allLinks: { to: string; label: string; role?: Role }[] = [
  { to: '/kalender', label: 'Kalender', role: 'ROLE_FOREMAN' },
  { to: '/kapazitaet', label: 'Kapazität', role: 'ROLE_FOREMAN' },
  { to: '/arbeiten', label: 'Arbeiten' },
  { to: '/brauche-arbeit', label: 'Brauche Arbeit' },
  { to: '/auswertung', label: 'Auswertung', role: 'ROLE_FOREMAN' },
  { to: '/benutzer', label: 'Benutzer', role: 'ROLE_ADMIN' },
  { to: '/einstellungen', label: 'Einstellungen' },
]

const links = computed(() =>
  allLinks
    .filter((link) => !link.role || auth.hasRole(link.role))
    .map((link) =>
      // Fuer Arbeiter heisst die Liste "Meine Arbeiten" – sie sehen nur ihre eigenen.
      link.to === '/arbeiten' && !auth.isForeman ? { ...link, label: 'Meine Arbeiten' } : link,
    ),
)

const roleClass = computed(() => `role role--${auth.role.toLowerCase().replace('role_', '')}`)

function logout(): void {
  workshop.reset()
  auth.logout()
  void router.push({ name: 'login' })
}
</script>

<template>
  <header class="nav">
    <div class="nav__inner">
      <RouterLink :to="auth.homeRoute" class="brand">
        <span class="brand__mark">GZ</span>
        <span class="brand__text">Ganglbauer Zeitmanagment</span>
      </RouterLink>

      <nav class="links">
        <RouterLink v-for="link in links" :key="link.to" :to="link.to" class="link">
          {{ link.label }}
        </RouterLink>
      </nav>

      <div class="user">
        <span class="avatar">{{ auth.user?.initials }}</span>
        <span class="user__text">
          <span>{{ auth.user?.fullName }}</span>
          <span :class="roleClass">{{ auth.user?.roleLabel }}</span>
        </span>
        <button class="secondary" type="button" @click="logout">Abmelden</button>
      </div>
    </div>
  </header>
</template>

<style scoped>
.nav {
  background: var(--surface);
  border-bottom: 1px solid var(--border);
  position: sticky;
  top: 0;
  z-index: 10;
}

.nav__inner {
  max-width: 1400px;
  margin: 0 auto;
  padding: 0.75rem 1rem;
  display: flex;
  align-items: center;
  gap: 1rem;
  flex-wrap: wrap;
}

.brand {
  display: flex;
  align-items: center;
  gap: 0.6rem;
  text-decoration: none;
  color: var(--text);
  font-weight: 700;
}

.brand__mark {
  background: var(--primary);
  color: #fff;
  border-radius: 8px;
  width: 30px;
  height: 30px;
  display: grid;
  place-items: center;
  font-size: 0.8125rem;
}

.links {
  display: flex;
  gap: 0.25rem;
  flex-wrap: wrap;
  margin-left: auto;
}

.link {
  text-decoration: none;
  color: var(--muted);
  font-weight: 600;
  font-size: 0.875rem;
  padding: 0.4rem 0.7rem;
  border-radius: 8px;
}

.link:hover {
  background: var(--bg);
  color: var(--text);
}

.link.router-link-exact-active {
  background: var(--primary-soft);
  color: var(--primary);
}

.user {
  display: flex;
  align-items: center;
  gap: 0.6rem;
  font-size: 0.875rem;
}

.avatar {
  width: 32px;
  height: 32px;
  border-radius: 50%;
  display: grid;
  place-items: center;
  background: var(--primary-soft);
  color: var(--primary);
  font-weight: 700;
  font-size: 0.75rem;
}

.user__text {
  display: flex;
  flex-direction: column;
  line-height: 1.2;
  font-weight: 600;
}

.role {
  font-size: 0.75rem;
  font-weight: 600;
}

.role--admin {
  color: var(--primary);
}

.role--foreman {
  color: var(--warning);
}

.role--user {
  color: var(--success);
}

@media (max-width: 720px) {
  .user__text {
    display: none;
  }

  .brand__text {
    display: none;
  }

  .links {
    order: 3;
    width: 100%;
    margin-left: 0;
  }
}
</style>
