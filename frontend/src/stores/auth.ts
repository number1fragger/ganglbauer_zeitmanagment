import { computed, ref } from 'vue'
import { defineStore } from 'pinia'
import { authApi } from '@/api'
import { configureHttp } from '@/api/http'
import type { User } from '@/api/types'
import { canPlan } from '@/utils/domain'
import { useThemeStore } from './theme'

const TOKEN_KEY = 'werkstatt_token'

/** "Angemeldet bleiben" legt das Token in den localStorage, sonst nur fuer die Sitzung. */
function readToken(): string | null {
  return localStorage.getItem(TOKEN_KEY) ?? sessionStorage.getItem(TOKEN_KEY)
}

export const useAuthStore = defineStore('auth', () => {
  const theme = useThemeStore()
  theme.connect((value) => (token.value ? authApi.savePreferences(value) : Promise.resolve()))

  const token = ref<string | null>(readToken())
  const user = ref<User | null>(null)

  const isAuthenticated = computed(() => token.value !== null)
  const role = computed(() => user.value?.role)
  const isPlanner = computed(() => canPlan(role.value))
  const isChef = computed(() => role.value === 'chef')

  function storeToken(value: string | null, remember = false): void {
    localStorage.removeItem(TOKEN_KEY)
    sessionStorage.removeItem(TOKEN_KEY)
    if (value) (remember ? localStorage : sessionStorage).setItem(TOKEN_KEY, value)
    token.value = value
  }

  async function login(username: string, password: string, remember: boolean): Promise<void> {
    const { token: newToken } = await authApi.login(username.trim().toLowerCase(), password)
    storeToken(newToken, remember)
    await loadUser()
  }

  async function loadUser(): Promise<void> {
    user.value = await authApi.me()
    // Die im Konto gespeicherte Farbwahl gilt auf allen Geraeten. Steht dort
    // noch der Standard ("system"), wird eine lokale Wahl ins Konto uebernommen.
    if (user.value.theme && user.value.theme !== 'system') theme.set(user.value.theme, false)
    else if (theme.preference !== 'system') void authApi.savePreferences(theme.preference).catch(() => undefined)
  }

  function logout(): void {
    storeToken(null)
    user.value = null
  }

  configureHttp({ token: () => token.value, unauthorized: logout })

  return { user, isAuthenticated, role, isPlanner, isChef, login, loadUser, logout }
})
