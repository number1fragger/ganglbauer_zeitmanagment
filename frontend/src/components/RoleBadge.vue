<script setup lang="ts">
import { useAuthStore } from '@/stores/auth'
import { roleOf } from '@/utils/domain'

defineProps<{ withLogout?: boolean }>()
const auth = useAuthStore()
</script>

<template>
  <div v-if="auth.user" class="role-badge" :style="{ '--role': roleOf(auth.user.role).color }">
    <span class="dot" :style="{ '--dot': 'var(--role)' }" />
    <strong>Rolle: {{ roleOf(auth.user.role).label }}</strong>
    <button v-if="withLogout" class="role-badge__logout" @click="auth.logout()">Abmelden</button>
  </div>
</template>

<style scoped>
.role-badge {
  display: inline-flex;
  align-items: center;
  gap: 8px;
  height: 36px;
  padding: 0 14px;
  border-radius: 18px;
  background: var(--primary-soft);
  font-size: 11px;
}

.role-badge strong {
  color: var(--role);
  font-weight: 600;
}

.role-badge__logout {
  margin-left: 10px;
  padding: 0;
  border: 0;
  background: none;
  color: var(--muted);
  font-size: 11px;
  font-weight: 500;
}

.role-badge__logout:hover {
  color: var(--text);
}
</style>
