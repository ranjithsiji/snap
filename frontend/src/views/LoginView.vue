<script setup>
import { ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { CdxButton, CdxField, CdxMessage, CdxTextInput } from '@wikimedia/codex'
import { useSession } from '@/stores/session'

const session = useSession()
const router = useRouter()
const route = useRoute()

const username = ref('')
const password = ref('')
const error = ref(null)
const busy = ref(false)

/**
 * A full page load, not a router navigation: the OAuth handshake starts
 * on the server and ends back here with a session cookie set.
 *
 * Called from a real function rather than inline in the template —
 * template expressions resolve bare identifiers against the component's
 * render context, not the global scope, so neither `location` nor
 * `window` is reachable from there.
 */
function loginWithWikimedia() {
  window.location.href = '/api/auth/wikimedia'
}

async function submit() {
  error.value = null
  busy.value = true

  try {
    await session.login(username.value, password.value)
    router.push(route.query.redirect || { name: 'my-rounds' })
  } catch (e) {
    error.value = e.message
  } finally {
    busy.value = false
  }
}
</script>

<template>
  <div style="max-width: 26rem; margin: 8vh auto">
    <div class="card">
      <h1 class="page-title">Sign in</h1>
      <p class="page-subtitle">Jury tool for Wiki Loves campaigns</p>

      <CdxMessage v-if="error" type="error" style="margin-top: 1rem">{{ error }}</CdxMessage>

      <CdxButton
        v-if="session.methods.wikimedia"
        action="progressive"
        weight="primary"
        style="width: 100%; margin-top: 1.5rem"
        @click="loginWithWikimedia"
      >
        Log in with Wikimedia
      </CdxButton>

      <CdxMessage v-else type="notice" style="margin-top: 1.5rem">
        Wikimedia login is not configured on this server. Use a local account below.
      </CdxMessage>

      <form v-if="session.methods.local" style="margin-top: 1.5rem" @submit.prevent="submit">
        <CdxField>
          <template #label>Username</template>
          <CdxTextInput v-model="username" autocomplete="username" required />
        </CdxField>

        <CdxField>
          <template #label>Password</template>
          <CdxTextInput
            v-model="password"
            input-type="password"
            autocomplete="current-password"
            required
          />
        </CdxField>

        <CdxButton
          action="progressive"
          weight="primary"
          type="submit"
          style="width: 100%; margin-top: 0.5rem"
          :disabled="busy"
        >
          {{ busy ? 'Signing in…' : 'Sign in' }}
        </CdxButton>
      </form>
    </div>
  </div>
</template>
