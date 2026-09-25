<script setup lang="ts">
import { onBeforeUnmount, onMounted, ref } from 'vue'
import { RouterLink, useRouter } from 'vue-router'
import { useAuthStore } from '@/stores/auth'
import { useJobDialogStore } from '@/stores/jobDialog'
import { useWorkshopStore } from '@/stores/workshop'

/**
 * Kopfzeile aus dem Figma-Entwurf: "Werkstatt-Planung", Platz fuer die
 * Kalendersteuerung (#header-center, per Teleport befuellt), Benutzer-Chip
 * mit Menue, "Auswertung" und "+ Neue Arbeit".
 */
const auth = useAuthStore()
const workshop = useWorkshopStore()
const dialog = useJobDialogStore()
const router = useRouter()

const menuOpen = ref(false)
const menu = ref<HTMLElement | null>(null)

function closeOnOutside(event: MouseEvent): void {
  if (menu.value && !menu.value.contains(event.target as Node)) menuOpen.value = false
}

onMounted(() => document.addEventListener('click', closeOnOutside))
onBeforeUnmount(() => document.removeEventListener('click', closeOnOutside))

function logout(): void {
  workshop.reset()
  auth.logout()
  void router.push({ name: 'login' })
}
</script>

<template>
  <header class="header">
    <RouterLink :to="auth.homeRoute" class="brand">
      <strong>Werkstatt-Planung</strong>
      <span>Kundentermine &amp; Arbeitseinteilung</span>
    </RouterLink>

    <div id="header-center" class="center"></div>

    <div ref="menu" class="chip-wrap">
      <button type="button" class="chip" :aria-expanded="menuOpen" @click="menuOpen = !menuOpen">
        <span class="chip__avatar">{{ auth.user?.initials }}</span>
        <span class="chip__text">
          <strong>{{ auth.user?.fullName }}</strong>
          <span>{{ auth.user?.roleLabel }}</span>
        </span>
        <span class="chip__caret">▾</span>
      </button>

      <nav v-if="menuOpen" class="menu" @click="menuOpen = false">
        <RouterLink to="/kalender">Kalender</RouterLink>
        <RouterLink to="/arbeiten">Alle Arbeiten</RouterLink>
        <RouterLink to="/kapazitaet">Kapazität</RouterLink>
        <RouterLink to="/meine-arbeit">Meine Arbeit</RouterLink>
        <RouterLink v-if="auth.isAdmin" to="/benutzer">Benutzer &amp; Rechte</RouterLink>
        <RouterLink to="/einstellungen">Einstellungen</RouterLink>
        <button type="button" class="menu__logout" @click="logout">Abmelden</button>
      </nav>
    </div>

    <RouterLink to="/auswertung" class="btn-grey">Auswertung</RouterLink>
    <button type="button" class="btn-new" @click="dialog.create()">+ Neue Arbeit</button>
  </header>
</template>

<style scoped>
.header {
  position: sticky;
  top: 0;
  z-index: 20;
  height: 72px;
  display: flex;
  align-items: center;
  gap: 1rem;
  padding: 0 1.5rem;
  background: var(--surface);
  border-bottom: 1px solid var(--border);
}

.brand {
  display: flex;
  flex-direction: column;
  text-decoration: none;
  color: var(--text);
  min-width: 250px;
}

.brand strong {
  font-size: 1.25rem;
  font-weight: 700;
  line-height: 1.3;
}

.brand span {
  font-size: 0.75rem;
  color: var(--muted);
}

.center {
  flex: 1;
  display: flex;
  align-items: center;
  min-width: 0;
}

.chip-wrap {
  position: relative;
}

.chip {
  display: flex;
  align-items: center;
  gap: 0.5rem;
  height: 40px;
  padding: 0 0.9rem 0 4px;
  border-radius: 20px;
  background: var(--primary-soft);
  color: var(--text);
  min-width: 178px;
}

.chip__avatar {
  width: 32px;
  height: 32px;
  border-radius: 50%;
  background: var(--surface);
  color: var(--primary);
  display: grid;
  place-items: center;
  font-size: 0.6875rem;
  font-weight: 600;
}

.chip__text {
  display: flex;
  flex-direction: column;
  align-items: flex-start;
  line-height: 1.25;
  flex: 1;
}

.chip__text strong {
  font-size: 0.6875rem;
  font-weight: 600;
}

.chip__text span {
  font-size: 0.625rem;
  font-weight: 500;
  color: var(--primary);
}

.chip__caret {
  font-size: 0.625rem;
  color: var(--muted);
}

.menu {
  position: absolute;
  right: 0;
  top: 46px;
  min-width: 200px;
  background: var(--surface);
  border: 1px solid var(--border);
  border-radius: 10px;
  box-shadow: 0 8px 24px rgba(16, 24, 40, 0.12);
  padding: 0.35rem;
  display: flex;
  flex-direction: column;
}

.menu a,
.menu__logout {
  display: block;
  text-align: left;
  padding: 0.55rem 0.75rem;
  border-radius: 6px;
  font-size: 0.8125rem;
  font-weight: 500;
  color: var(--text);
  text-decoration: none;
  background: none;
  height: auto;
}

.menu a:hover,
.menu__logout:hover {
  background: var(--button);
}

.menu a.router-link-active {
  color: var(--primary);
}

.menu__logout {
  color: var(--danger);
  border-top: 1px solid var(--grid);
  border-radius: 0 0 6px 6px;
  margin-top: 0.25rem;
}

.btn-grey {
  display: grid;
  place-items: center;
  height: 36px;
  width: 150px;
  border-radius: 8px;
  background: var(--button);
  color: var(--text);
  font-size: 0.75rem;
  font-weight: 500;
  text-decoration: none;
}

.btn-grey.router-link-active {
  color: var(--primary);
}

.btn-new {
  width: 152px;
}

@media (max-width: 1100px) {
  .brand {
    min-width: 0;
  }

  .brand span,
  .chip__text,
  .btn-grey {
    display: none;
  }

  .chip {
    min-width: 0;
    padding-right: 0.6rem;
  }
}
</style>
