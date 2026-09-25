import { computed, ref } from 'vue'
import { defineStore } from 'pinia'
import { http, onUnauthorized, TOKEN_KEY } from '@/api/client'
import type { User } from '@/api/types'

export const useAuthStore = defineStore('auth', () => {
  const token = ref<string | null>(localStorage.getItem(TOKEN_KEY))
  const user = ref<User | null>(null)
  const loading = ref(false)

  const isAuthenticated = computed(() => token.value !== null)
  const isAdmin = computed(() => user.value?.roles.includes('ROLE_ADMIN') ?? false)

  function setToken(value: string | null): void {
    token.value = value

    if (value) {
      localStorage.setItem(TOKEN_KEY, value)
    } else {
      localStorage.removeItem(TOKEN_KEY)
    }
  }

  async function login(email: string, password: string): Promise<void> {
    loading.value = true

    try {
      const { data } = await http.post<{ token: string }>('/api/login', { email, password })
      setToken(data.token)
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

  return { token, user, loading, isAuthenticated, isAdmin, login, loadUser, logout }
})
