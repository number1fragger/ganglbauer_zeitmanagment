import { computed, ref } from 'vue'
import { defineStore } from 'pinia'
import { api, onUnauthorized, readToken, TOKEN_KEY } from '@/api/client'

/** Rangfolge: eine hoehere Rolle hat alle Rechte der niedrigeren. */
const RANK = { ROLE_USER: 0, ROLE_FOREMAN: 1, ROLE_ADMIN: 2 }

export const useAuthStore = defineStore('auth', () => {
  const token = ref(readToken())
  const user = ref(null)
  const loading = ref(false)

  const isAuthenticated = computed(() => token.value !== null)
  const role = computed(() => user.value?.role ?? 'ROLE_USER')
  const isAdmin = computed(() => hasRole('ROLE_ADMIN'))
  /** Chef oder Vorarbeiter – darf planen, auswerten und zuteilen. */
  const isForeman = computed(() => hasRole('ROLE_FOREMAN'))
  const homeRoute = computed(() => (isForeman.value ? '/kalender' : '/meine-arbeit'))

  function hasRole(required) {
    return user.value !== null && RANK[user.value.role] >= RANK[required]
  }

  function setToken(value, remember = true) {
    token.value = value
    localStorage.removeItem(TOKEN_KEY)
    sessionStorage.removeItem(TOKEN_KEY)
    if (value) (remember ? localStorage : sessionStorage).setItem(TOKEN_KEY, value)
  }

  async function login(email, password, remember = true) {
    loading.value = true
    try {
      const data = await api.post('/api/login', { email, password })
      setToken(data.token, remember)
      await loadUser()
    } finally {
      loading.value = false
    }
  }

  async function loadUser() {
    if (token.value) user.value = await api.get('/api/me')
  }

  function logout() {
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
