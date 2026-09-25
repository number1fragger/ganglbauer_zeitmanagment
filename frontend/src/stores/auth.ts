import { computed, ref } from 'vue'
import { defineStore } from 'pinia'
import { http, onUnauthorized, readToken, TOKEN_KEY } from '@/api/client'
import type { Role, User } from '@/api/types'

/** Rangfolge der Rollen – eine hoehere Rolle hat alle Rechte der niedrigeren. */
const RANK: Record<Role, number> = { ROLE_USER: 0, ROLE_FOREMAN: 1, ROLE_ADMIN: 2 }

export const useAuthStore = defineStore('auth', () => {
  const token = ref<string | null>(readToken())
  const user = ref<User | null>(null)
  const loading = ref(false)

  const isAuthenticated = computed(() => token.value !== null)
  const role = computed<Role>(() => user.value?.role ?? 'ROLE_USER')
  /** Chef */
  const isAdmin = computed(() => hasRole('ROLE_ADMIN'))
  /** Chef oder Vorarbeiter – darf planen, auswerten und zuteilen. */
  const isForeman = computed(() => hasRole('ROLE_FOREMAN'))

  /** Die Startseite haengt von der Rolle ab. */
  const homeRoute = computed(() => (isForeman.value ? '/kalender' : '/meine-arbeit'))

  function hasRole(required: Role): boolean {
    if (!user.value) return false

    return RANK[user.value.role] >= RANK[required]
  }

  function setToken(value: string | null, remember = true): void {
    token.value = value
    localStorage.removeItem(TOKEN_KEY)
    sessionStorage.removeItem(TOKEN_KEY)

    if (value) {
      ;(remember ? localStorage : sessionStorage).setItem(TOKEN_KEY, value)
    }
  }

  async function login(email: string, password: string, remember = true): Promise<void> {
    loading.value = true

    try {
      const { data } = await http.post<{ token: string }>('/api/login', { email, password })
      setToken(data.token, remember)
      await loadUser()
    } finally {
      loading.value = false
    }
  }

  async function loadUser(): Promise<void> {
    if (!token.value) return

    const { data } = await http.get<User>('/api/me')
    user.value = data
  }

  function logout(): void {
    setToken(null)
    user.value = null
  }

  onUnauthorized(logout)

  return {
    token,
    user,
    loading,
    isAuthenticated,
    role,
    isAdmin,
    isForeman,
    homeRoute,
    hasRole,
    login,
    loadUser,
    logout,
  }
})
