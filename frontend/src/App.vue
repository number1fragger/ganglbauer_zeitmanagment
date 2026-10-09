<script setup lang="ts">
import { watch } from 'vue'
import { RouterView, useRouter } from 'vue-router'
import ConfirmHost from '@/components/ConfirmHost.vue'
import ToastHost from '@/components/ToastHost.vue'
import { useAuthStore } from '@/stores/auth'
import { useThemeStore } from '@/stores/theme'

const auth = useAuthStore()
const router = useRouter()
useThemeStore()

// Abgelaufene Anmeldung (401) oder Abmelden fuehrt zurueck zum Login.
watch(
  () => auth.isAuthenticated,
  (loggedIn) => {
    if (!loggedIn) void router.push({ name: 'login' })
  },
)
</script>

<template>
  <RouterView />
  <ToastHost />
  <ConfirmHost />
</template>
