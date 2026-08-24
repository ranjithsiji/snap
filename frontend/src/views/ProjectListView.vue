<script setup>
import { onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import {
  CdxButton,
  CdxDialog,
  CdxField,
  CdxIcon,
  CdxInfoChip,
  CdxMessage,
  CdxProgressBar,
  CdxTextArea,
  CdxTextInput,
} from '@wikimedia/codex'
import { cdxIconNext } from '@wikimedia/codex-icons'
import CommonsLookup from '@/components/CommonsLookup.vue'
import { api } from '@/api'
import { formatNumber } from '@/format'

/**
 * Projects are the contest families — Wiki Loves Folklore, Wiki Loves
 * Earth. Admins create them and appoint a lead; the lead runs everything
 * beneath.
 */
const router = useRouter()

const projects = ref([])
const canCreate = ref(false)
const loading = ref(true)
const busy = ref(false)
const error = ref(null)

const createOpen = ref(false)
const form = ref({ name: '', description: '', homepageUrl: '', lead: '' })

async function load() {
  try {
    const data = await api.get('/projects')
    projects.value = data.projects
    canCreate.value = data.canCreate
  } catch (e) {
    error.value = e.message
  } finally {
    loading.value = false
  }
}

onMounted(load)

async function create() {
  busy.value = true
  error.value = null

  try {
    const data = await api.post('/projects', form.value)
    createOpen.value = false
    form.value = { name: '', description: '', homepageUrl: '', lead: '' }
    router.push({ name: 'project', params: { id: data.project.id } })
  } catch (e) {
    error.value = e.message
  } finally {
    busy.value = false
  }
}
</script>

<template>
  <div class="page-head">
    <div>
      <h1 class="page-title">Projects</h1>
      <p class="page-subtitle">Contest families and their yearly editions</p>
    </div>
    <CdxButton
      v-if="canCreate"
      action="progressive"
      weight="primary"
      @click="createOpen = true"
    >
      New project
    </CdxButton>
  </div>

  <CdxMessage v-if="error" type="error">{{ error }}</CdxMessage>
  <CdxProgressBar v-if="loading" aria-label="Loading projects" />

  <div v-else-if="projects.length === 0" class="card empty">
    <p v-if="canCreate">No projects yet. Create one to get started.</p>
    <p v-else>You are not part of any project yet.</p>
    <CdxButton v-if="canCreate" action="progressive" weight="primary" @click="createOpen = true">
      Create the first project
    </CdxButton>
  </div>

  <div v-else class="stack">
    <div
      v-for="project in projects"
      :key="project.id"
      class="card"
      style="cursor: pointer"
      @click="router.push({ name: 'project', params: { id: project.id } })"
    >
      <div class="row wrap">
        <div>
          <h2 class="section-title">{{ project.name }}</h2>
          <p class="muted" style="margin: 0; font-size: 0.875rem">
            {{ formatNumber(project.campaignCount) }} campaign(s)
            <template v-if="project.leads.length">
              · led by {{ project.leads.join(', ') }}
            </template>
            <template v-else> · no lead appointed</template>
          </p>
        </div>

        <span class="spacer"></span>

        <CdxInfoChip v-if="project.canManage" status="success">you lead this</CdxInfoChip>
        <CdxInfoChip v-if="!project.leads.length" status="warning">needs a lead</CdxInfoChip>
        <CdxInfoChip v-if="project.isArchived">archived</CdxInfoChip>

        <!-- The whole card is clickable, but nothing said so — a button
             makes it plain there is somewhere to go, and where. -->
        <CdxButton
          weight="quiet"
          @click.stop="router.push({ name: 'project', params: { id: project.id } })"
        >
          View campaigns <CdxIcon :icon="cdxIconNext" />
        </CdxButton>
      </div>

      <p v-if="project.description" class="muted" style="margin: 0.75rem 0 0; font-size: 0.875rem">
        {{ project.description }}
      </p>
    </div>
  </div>

  <CdxDialog
    v-model:open="createOpen"
    title="New project"
    subtitle="A contest family, such as Wiki Loves Folklore"
    :primary-action="{
      label: 'Create project',
      actionType: 'progressive',
      disabled: busy || !form.name.trim(),
    }"
    :default-action="{ label: 'Cancel' }"
    @primary="create"
    @default="createOpen = false"
  >
    <CdxField>
      <template #label>Project name</template>
      <CdxTextInput v-model="form.name" placeholder="Wiki Loves Folklore" />
    </CdxField>

    <CdxField>
      <template #label>Lead</template>
      <template #description>
        The Wikimedia username of the person who will run this project. They can be appointed
        later, but a project cannot create campaigns without one.
      </template>
      <CommonsLookup v-model="form.lead" kind="users" placeholder="Search Commons users…" />
    </CdxField>

    <CdxField>
      <template #label>Description</template>
      <CdxTextArea v-model="form.description" rows="3" />
    </CdxField>

    <CdxField>
      <template #label>Homepage</template>
      <template #description>Optional link to the project's page on Commons or Meta.</template>
      <CdxTextInput v-model="form.homepageUrl" input-type="url" />
    </CdxField>
  </CdxDialog>
</template>
