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

async function submit() {
  error.value = null
  busy.value = true

  try {
    await session.login(username.value, password.value)
    router.push(route.query.redirect || { name: 'home' })
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
        @click="location.href = '/api/auth/wikimedia'"
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
