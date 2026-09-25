<script setup lang="ts">
import { RouterLink, useRouter } from 'vue-router'
import { useAuthStore } from '@/stores/auth'
import { useWorkshopStore } from '@/stores/workshop'

const auth = useAuthStore()
const workshop = useWorkshopStore()
const router = useRouter()

const links = [
  { to: '/', label: 'Übersicht' },
  { to: '/arbeiten', label: 'Arbeiten' },
  { to: '/brauche-arbeit', label: 'Brauche Arbeit' },
  { to: '/auswertung', label: 'Auswertung' },
  { to: '/einstellungen', label: 'Einstellungen' },
]

function logout(): void {
  workshop.reset()
  auth.logout()
  void router.push({ name: 'login' })
}
</script>

<template>
  <header class="nav">
    <div class="nav__inner">
      <RouterLink to="/" class="brand">
        <span class="brand__mark">GZ</span>
        <span class="brand__text">Ganglbauer Zeitmanagment</span>
      </RouterLink>

      <nav class="links">
        <RouterLink v-for="link in links" :key="link.to" :to="link.to" class="link">
          {{ link.label }}
        </RouterLink>
      </nav>

      <div class="user">
        <span class="muted">{{ auth.user?.fullName }}</span>
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
  max-width: 1080px;
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

@media (max-width: 720px) {
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
