<script setup>
import { onBeforeUnmount, onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import { useAuthStore } from '@/stores/auth'
import { useJobDialogStore } from '@/stores/jobDialog'

/**
 * Kopfzeile: Titel, Platz fuer die Kalendersteuerung (#header-center, per
 * Teleport befuellt), Benutzer-Menue, "Auswertung" und "+ Neue Arbeit".
 * Auf dem Handy wird "+ Neue Arbeit" zum runden Knopf unten rechts.
 */
const auth = useAuthStore()
const dialog = useJobDialogStore()
const router = useRouter()

const menuOpen = ref(false)
const menu = ref(null)

const closeOnOutside = (event) => {
  if (!menu.value?.contains(event.target)) menuOpen.value = false
}
onMounted(() => document.addEventListener('click', closeOnOutside))
onBeforeUnmount(() => document.removeEventListener('click', closeOnOutside))

function logout() {
  auth.logout()
  router.push('/login')
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
      <button
        type="button"
        class="chip"
        :aria-expanded="menuOpen"
        aria-label="Menü"
        @click="menuOpen = !menuOpen"
      >
        <span class="chip-avatar">{{ auth.user?.initials }}</span>
        <span class="chip-text">
          <strong>{{ auth.user?.fullName }}</strong>
          <span>{{ auth.user?.roleLabel }}</span>
        </span>
        <span class="caret">▾</span>
      </button>

      <nav v-if="menuOpen" class="menu" @click="menuOpen = false">
        <RouterLink to="/kalender">Kalender</RouterLink>
        <RouterLink to="/arbeiten">Alle Arbeiten</RouterLink>
        <RouterLink to="/auswertung">Auswertung</RouterLink>
        <RouterLink to="/meine-arbeit">Meine Arbeit</RouterLink>
        <RouterLink v-if="auth.isAdmin" to="/benutzer">Benutzer &amp; Rechte</RouterLink>
        <RouterLink to="/einstellungen">Einstellungen</RouterLink>
        <button type="button" class="logout" @click="logout">Abmelden</button>
      </nav>
    </div>

    <RouterLink to="/auswertung" class="btn-grey">Auswertung</RouterLink>
    <button type="button" class="btn-new" @click="dialog.create()">+ Neue Arbeit</button>
    <button type="button" class="fab" aria-label="Neue Arbeit" @click="dialog.create()">+</button>
  </header>
</template>

<style scoped>
.header {
  position: sticky;
  top: 0;
  z-index: 20;
  display: flex;
  align-items: center;
  gap: 1rem;
  height: 72px;
  padding: 0 1.5rem;
  border-bottom: 1px solid var(--border);
  background: var(--surface);
}

.brand {
  display: flex;
  flex-direction: column;
  min-width: 250px;
  color: var(--text);
  text-decoration: none;
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
  display: flex;
  flex: 1;
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
  min-width: 178px;
  height: 40px;
  padding: 0 0.9rem 0 4px;
  border-radius: 20px;
  background: var(--primary-soft);
  color: var(--text);
}

.chip-avatar {
  display: grid;
  place-items: center;
  width: 32px;
  height: 32px;
  border-radius: 50%;
  background: var(--surface);
  color: var(--primary);
  font-size: 0.6875rem;
  font-weight: 600;
}

.chip-text {
  display: flex;
  flex: 1;
  flex-direction: column;
  align-items: flex-start;
  line-height: 1.25;
}

.chip-text strong {
  font-size: 0.6875rem;
  font-weight: 600;
}

.chip-text span {
  font-size: 0.625rem;
  font-weight: 500;
  color: var(--primary);
}

.caret {
  font-size: 0.625rem;
  color: var(--muted);
}

.menu {
  position: absolute;
  top: 46px;
  right: 0;
  display: flex;
  flex-direction: column;
  min-width: 200px;
  padding: 0.35rem;
  border: 1px solid var(--border);
  border-radius: 10px;
  background: var(--surface);
  box-shadow: 0 8px 24px rgba(16, 24, 40, 0.12);
}

.menu a,
.logout {
  display: block;
  height: auto;
  padding: 0.6rem 0.75rem;
  border-radius: 6px;
  background: none;
  color: var(--text);
  font-size: 0.8125rem;
  font-weight: 500;
  text-align: left;
  text-decoration: none;
}

.menu a:hover,
.logout:hover {
  background: var(--button);
}

.menu a.router-link-active {
  color: var(--primary);
}

.logout {
  margin-top: 0.25rem;
  border-top: 1px solid var(--grid);
  border-radius: 0 0 6px 6px;
  color: var(--danger);
}

.btn-grey {
  display: grid;
  place-items: center;
  width: 150px;
  height: 36px;
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

.fab {
  display: none;
}

@media (max-width: 1100px) {
  .brand {
    min-width: 0;
  }

  .brand span,
  .btn-grey {
    display: none;
  }
}

@media (max-width: 768px) {
  .header {
    flex-wrap: wrap;
    gap: 0.5rem 0.75rem;
    height: auto;
    padding: 0.6rem 1rem;
  }

  .brand {
    flex: 1;
  }

  .brand strong {
    font-size: 1.0625rem;
  }

  .center {
    order: 3;
    flex-basis: 100%;
  }

  .center:empty {
    display: none;
  }

  .chip {
    min-width: 0;
    padding-right: 0.6rem;
  }

  .chip-text,
  .btn-new {
    display: none;
  }

  .fab {
    position: fixed;
    right: 20px;
    bottom: 20px;
    z-index: 30;
    display: grid;
    place-items: center;
    width: 56px;
    height: 56px;
    padding: 0;
    border-radius: 50%;
    font-size: 1.75rem;
    font-weight: 400;
    box-shadow: 0 8px 20px rgba(37, 99, 235, 0.35);
  }
}
</style>
