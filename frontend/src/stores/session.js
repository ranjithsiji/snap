import { defineStore } from 'pinia'
import { ref, computed } from 'vue'
import { api } from '@/api'

/**
 * Who is logged in, and which login methods this deployment offers.
 */
export const useSession = defineStore('session', () => {
  const user = ref(null)
  const methods = ref({ wikimedia: false, local: true })
  const loaded = ref(false)

  const isAuthenticated = computed(() => user.value !== null)

  // Mirrors UserRole::impliedRoles() on the server. These drive what the
  // menu offers; the server enforces the real, scoped permissions, since a
  // lead's authority covers only their own project.
  const rank = { admin: 4, lead: 3, organizer: 2, jury: 1 }
  const level = computed(() => rank[user.value?.role] ?? 0)

  const isAdministrator = computed(() => level.value >= rank.admin)
  const isLead = computed(() => level.value >= rank.lead)
  const isOrganizer = computed(() => level.value >= rank.organizer)
  const isJuror = computed(() => level.value >= rank.jury)

  async function load() {
    try {
      const data = await api.get('/auth/me')
      user.value = data.user
      methods.value = data.methods
    } catch {
      user.value = null
    } finally {
      loaded.value = true
    }
  }

  async function login(username, password) {
    const data = await api.post('/auth/login', { username, password })
    user.value = data.user
  }

  async function logout() {
    await api.post('/auth/logout')
    user.value = null
  }

  return {
    user,
    methods,
    loaded,
    isAuthenticated,
    isAdministrator,
    isLead,
    isOrganizer,
    isJuror,
    load,
    login,
    logout,
  }
})
