<script setup lang="ts">
import { computed, ref } from 'vue'
import { RouterLink, RouterView, useRoute } from 'vue-router'
import AppIcon from '@/components/AppIcon.vue'
import type { IconName } from '@/components/icons'
import PasswordDialog from '@/components/PasswordDialog.vue'
import ThemeToggle from '@/components/ThemeToggle.vue'
import { useAuthStore } from '@/stores/auth'
import { roleOf } from '@/utils/domain'

/**
 * Rahmen der angemeldeten App: Seitenleiste am Desktop, schmale
 * Icon-Leiste am Tablet, obere Leiste + Navigation unten am Smartphone.
 */
const auth = useAuthStore()
const route = useRoute()

interface NavItem {
  name: string
  label: string
  icon: IconName
  show: boolean
  mobile: boolean
}

const items = computed<NavItem[]>(() =>
  [
    {
      name: 'dashboard',
      label: 'Dashboard',
      icon: 'dashboard' as const,
      show: auth.isPlanner,
      mobile: true,
    },
    { name: 'planning', label: 'Kalender', icon: 'calendar' as const, show: true, mobile: true },
    { name: 'tasks', label: 'Aufgaben', icon: 'board' as const, show: true, mobile: true },
    {
      name: 'my-work',
      label: 'Meine Arbeiten',
      icon: 'briefcase' as const,
      show: auth.user?.role !== 'chef',
      mobile: true,
    },
    {
      name: 'report',
      label: 'Auswertung',
      icon: 'chart' as const,
      show: auth.isPlanner,
      mobile: false,
    },
    {
      name: 'users',
      label: 'Benutzer & Rechte',
      icon: 'users' as const,
      show: auth.isChef,
      mobile: false,
    },
  ].filter((item) => item.show),
)

const mobileItems = computed(() => items.value.filter((item) => item.mobile).slice(0, 4))
const extraItems = computed(() => items.value.filter((item) => !mobileItems.value.includes(item)))

const menuOpen = ref(false)
const changingPassword = ref(false)

function openPasswordDialog(): void {
  menuOpen.value = false
  changingPassword.value = true
}

const isActive = (name: string) => route.name === name
</script>

<template>
  <div class="shell">
    <a class="shell__skip" href="#main">Zum Inhalt springen</a>

    <!-- Desktop / Tablet -->
    <aside class="sidebar" aria-label="Hauptnavigation">
      <RouterLink :to="{ name: 'home' }" class="sidebar__brand">
        <span class="sidebar__logo" aria-hidden="true"><AppIcon name="wrench" :size="18" /></span>
        <span class="sidebar__brand-text">
          <strong>Werkstatt</strong>
          <small>Ganglbauer Landtechnik</small>
        </span>
      </RouterLink>

      <nav class="sidebar__nav">
        <RouterLink
          v-for="item in items"
          :key="item.name"
          :to="{ name: item.name }"
          class="nav-link"
          :class="{ 'nav-link--active': isActive(item.name) }"
          :aria-current="isActive(item.name) ? 'page' : undefined"
          :data-tip="item.label"
        >
          <AppIcon :name="item.icon" :size="18" />
          <span class="nav-link__label">{{ item.label }}</span>
        </RouterLink>
      </nav>

      <div class="sidebar__footer">
        <div class="sidebar__theme sidebar__theme--full"><ThemeToggle /></div>
        <div class="sidebar__theme sidebar__theme--compact"><ThemeToggle compact /></div>

        <div v-if="auth.user" class="account">
          <button
            class="account__chip"
            :aria-expanded="menuOpen"
            aria-haspopup="menu"
            @click="menuOpen = !menuOpen"
          >
            <span class="avatar">{{ auth.user.initials }}</span>
            <span class="account__text">
              <strong>{{ auth.user.fullName }}</strong>
              <small>{{ roleOf(auth.user.role).label }}</small>
            </span>
            <AppIcon class="account__caret" name="chevron-down" :size="14" />
          </button>
          <div v-if="menuOpen" class="account__menu" role="menu" @click.stop>
            <button role="menuitem" @click="openPasswordDialog">
              <AppIcon name="key" :size="15" /> Passwort ändern
            </button>
            <button role="menuitem" @click="auth.logout()">
              <AppIcon name="logout" :size="15" /> Abmelden
            </button>
          </div>
          <div v-if="menuOpen" class="account__backdrop" @click="menuOpen = false" />
        </div>
      </div>
    </aside>

    <!-- Smartphone: obere Leiste -->
    <header class="topbar">
      <RouterLink :to="{ name: 'home' }" class="topbar__brand">
        <span class="sidebar__logo" aria-hidden="true"><AppIcon name="wrench" :size="16" /></span>
        <strong>Werkstatt</strong>
      </RouterLink>
      <ThemeToggle compact />
      <div v-if="auth.user" class="account account--top">
        <button
          class="avatar topbar__avatar"
          :aria-expanded="menuOpen"
          aria-label="Konto-Menü"
          @click="menuOpen = !menuOpen"
        >
          {{ auth.user.initials }}
        </button>
        <div v-if="menuOpen" class="account__menu account__menu--top" role="menu" @click.stop>
          <p class="account__who">
            <strong>{{ auth.user.fullName }}</strong>
            <small>{{ roleOf(auth.user.role).label }}</small>
          </p>
          <RouterLink
            v-for="item in extraItems"
            :key="item.name"
            role="menuitem"
            :to="{ name: item.name }"
            @click="menuOpen = false"
          >
            <AppIcon :name="item.icon" :size="15" /> {{ item.label }}
          </RouterLink>
          <button role="menuitem" @click="openPasswordDialog">
            <AppIcon name="key" :size="15" /> Passwort ändern
          </button>
          <button role="menuitem" @click="auth.logout()">
            <AppIcon name="logout" :size="15" /> Abmelden
          </button>
        </div>
        <div v-if="menuOpen" class="account__backdrop" @click="menuOpen = false" />
      </div>
    </header>

    <main id="main" class="shell__main">
      <RouterView />
    </main>

    <!-- Smartphone: Navigation unten -->
    <nav class="bottom-nav" aria-label="Hauptnavigation">
      <RouterLink
        v-for="item in mobileItems"
        :key="item.name"
        :to="{ name: item.name }"
        class="bottom-nav__link"
        :class="{ 'bottom-nav__link--active': isActive(item.name) }"
        :aria-current="isActive(item.name) ? 'page' : undefined"
      >
        <AppIcon :name="item.icon" :size="20" />
        <span>{{ item.label === 'Meine Arbeiten' ? 'Meine' : item.label }}</span>
      </RouterLink>
    </nav>

    <PasswordDialog v-if="changingPassword" @close="changingPassword = false" />
  </div>
</template>

<style scoped>
.shell {
  --sidebar-width: 240px;
  display: grid;
  grid-template-columns: var(--sidebar-width) minmax(0, 1fr);
  min-height: 100dvh;
}

.shell__skip {
  position: absolute;
  z-index: 200;
  top: -40px;
  left: 12px;
  padding: 8px 12px;
  border-radius: var(--radius);
  background: var(--primary);
  color: var(--on-primary);
}

.shell__skip:focus {
  top: 12px;
}

.shell__main {
  min-width: 0;
}

/* ---- Seitenleiste ----------------------------------------------------- */

.sidebar {
  position: sticky;
  top: 0;
  display: flex;
  flex-direction: column;
  height: 100dvh;
  padding: 16px 12px;
  border-right: 1px solid var(--border);
  background: var(--sidebar);
}

.sidebar__brand {
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 4px 8px 20px;
  color: var(--text);
  text-decoration: none;
}

.sidebar__logo {
  display: grid;
  flex: none;
  place-items: center;
  width: 32px;
  height: 32px;
  border-radius: 9px;
  background: var(--primary);
  color: var(--on-primary);
}

.sidebar__brand-text {
  display: flex;
  flex-direction: column;
  min-width: 0;
}

.sidebar__brand-text strong {
  font-size: 14px;
  font-weight: 700;
}

.sidebar__brand-text small {
  overflow: hidden;
  color: var(--muted);
  font-size: 11px;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.sidebar__nav {
  display: flex;
  flex-direction: column;
  gap: 2px;
}

.nav-link {
  display: flex;
  align-items: center;
  gap: 12px;
  height: 38px;
  padding: 0 12px;
  border-radius: var(--radius);
  color: var(--muted);
  font-size: 13px;
  font-weight: 500;
  text-decoration: none;
  transition:
    background-color 0.15s,
    color 0.15s;
}

.nav-link:hover {
  background: var(--surface-muted);
  color: var(--text);
}

.nav-link--active {
  background: var(--primary-soft);
  color: var(--primary);
  font-weight: 600;
}

.nav-link--active:hover {
  background: var(--primary-soft);
  color: var(--primary);
}

/* Tooltips nur in der schmalen Leiste */
.nav-link[data-tip]:hover::after {
  display: none;
}

.sidebar__footer {
  display: flex;
  flex-direction: column;
  gap: 10px;
  margin-top: auto;
}

.sidebar__theme--compact {
  display: none;
}

.account {
  position: relative;
}

.account__chip {
  display: flex;
  align-items: center;
  gap: 10px;
  width: 100%;
  padding: 6px;
  border: 1px solid var(--border);
  border-radius: var(--radius-lg);
  background: var(--surface);
  text-align: left;
}

.account__chip:hover {
  background: var(--surface-alt);
}

.account__text {
  display: flex;
  flex: 1;
  flex-direction: column;
  min-width: 0;
}

.account__text strong {
  overflow: hidden;
  font-size: 12px;
  font-weight: 600;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.account__text small {
  color: var(--muted);
  font-size: 11px;
}

.account__caret {
  color: var(--muted);
}

.account__backdrop {
  position: fixed;
  z-index: 40;
  inset: 0;
}

.account__menu {
  position: absolute;
  z-index: 41;
  bottom: calc(100% + 6px);
  left: 0;
  display: flex;
  flex-direction: column;
  min-width: 210px;
  padding: 6px;
  border: 1px solid var(--border);
  border-radius: var(--radius-lg);
  background: var(--surface);
  box-shadow: var(--shadow-lg);
}

.account__menu a,
.account__menu button {
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 10px 12px;
  border: 0;
  border-radius: var(--radius-sm);
  background: none;
  color: var(--text);
  font-size: 13px;
  text-align: left;
  text-decoration: none;
}

.account__menu a:hover,
.account__menu button:hover {
  background: var(--surface-muted);
}

.account__who {
  display: flex;
  flex-direction: column;
  padding: 8px 12px 10px;
  margin-bottom: 4px;
  border-bottom: 1px solid var(--border);
}

.account__who small {
  color: var(--muted);
}

/* ---- Smartphone ------------------------------------------------------- */

.topbar,
.bottom-nav {
  display: none;
}

/* Tablet: schmale Icon-Leiste */
@media (max-width: 1099px) and (min-width: 700px) {
  .shell {
    --sidebar-width: 68px;
  }

  .sidebar {
    align-items: center;
    padding: 14px 10px;
  }

  .sidebar__brand {
    padding: 4px 0 18px;
  }

  .sidebar__brand-text,
  .nav-link__label,
  .account__text,
  .account__caret,
  .sidebar__theme--full {
    display: none;
  }

  .sidebar__theme--compact {
    display: block;
  }

  .nav-link {
    justify-content: center;
    width: 44px;
    height: 44px;
    padding: 0;
  }

  .nav-link[data-tip]:hover::after {
    display: block;
    top: 50%;
    bottom: auto;
    left: calc(100% + 10px);
    transform: translateY(-50%);
  }

  .account__chip {
    justify-content: center;
    padding: 4px;
    border: 0;
    background: none;
  }

  .account__menu {
    left: calc(100% + 8px);
    bottom: 0;
  }
}

@media (max-width: 699px) {
  .shell {
    display: block;
    padding-bottom: calc(64px + env(safe-area-inset-bottom));
  }

  .sidebar {
    display: none;
  }

  .topbar {
    position: sticky;
    z-index: 30;
    top: 0;
    display: flex;
    align-items: center;
    gap: 6px;
    height: 56px;
    padding: 0 12px 0 16px;
    border-bottom: 1px solid var(--border);
    background: color-mix(in srgb, var(--surface) 92%, transparent);
    backdrop-filter: blur(8px);
  }

  .topbar__brand {
    display: flex;
    flex: 1;
    align-items: center;
    gap: 10px;
    color: var(--text);
    font-size: 15px;
    text-decoration: none;
  }

  .topbar__brand .sidebar__logo {
    width: 28px;
    height: 28px;
    border-radius: 8px;
  }

  .topbar__avatar {
    width: 34px;
    height: 34px;
    border: 0;
  }

  .account__menu--top {
    top: calc(100% + 8px);
    right: 0;
    bottom: auto;
    left: auto;
  }

  .bottom-nav {
    position: fixed;
    z-index: 30;
    right: 0;
    bottom: 0;
    left: 0;
    display: flex;
    padding: 6px 8px calc(6px + env(safe-area-inset-bottom));
    border-top: 1px solid var(--border);
    background: color-mix(in srgb, var(--surface) 94%, transparent);
    backdrop-filter: blur(8px);
  }

  .bottom-nav__link {
    display: flex;
    flex: 1;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    gap: 3px;
    min-height: 52px;
    border-radius: var(--radius);
    color: var(--muted);
    font-size: 11px;
    font-weight: 500;
    text-decoration: none;
  }

  .bottom-nav__link--active {
    color: var(--primary);
    font-weight: 600;
  }

  .bottom-nav__link--active .icon {
    padding: 2px 10px;
    border-radius: 12px;
    background: var(--primary-soft);
    box-sizing: content-box;
  }
}
</style>
