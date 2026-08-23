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

  // Mirrors UserRole::impliedRoles() on the server: an administrator can
  // do everything, an organizer only reads results, a juror only votes.
  // The server enforces this regardless; here it drives what is shown.
  const isAdministrator = computed(() => user.value?.role === 'administrator')
  const isOrganizer = computed(
    () => user.value?.role === 'administrator' || user.value?.role === 'organizer',
  )
  const isJuror = computed(
    () => user.value?.role === 'administrator' || user.value?.role === 'juror',
  )

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
    isOrganizer,
    isJuror,
    load,
    login,
    logout,
  }
})
