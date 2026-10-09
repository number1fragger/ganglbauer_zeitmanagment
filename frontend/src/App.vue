<script setup lang="ts">
import { watch } from 'vue'
import { RouterView, useRouter } from 'vue-router'
import { useAuthStore } from '@/stores/auth'

const auth = useAuthStore()
const router = useRouter()

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
</template>
