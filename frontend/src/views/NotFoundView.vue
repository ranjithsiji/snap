<script setup>
import { useRouter } from 'vue-router'
import { CdxButton } from '@wikimedia/codex'

const router = useRouter()
</script>

<template>
  <div class="card empty">
    <h1 class="page-title">Page not found</h1>
    <p>That page does not exist.</p>
    <CdxButton action="progressive" weight="primary" @click="router.push({ name: 'home' })">
      Go home
    </CdxButton>
  </div>
</template>
