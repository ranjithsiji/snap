<script setup>
import { onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import {
  CdxButton,
  CdxDialog,
  CdxField,
  CdxInfoChip,
  CdxMessage,
  CdxProgressBar,
  CdxTextInput,
} from '@wikimedia/codex'
import CommonsLookup from '@/components/CommonsLookup.vue'
import { api } from '@/api'
import { formatNumber } from '@/format'

/**
 * One project: its campaigns, and the lead who runs it.
 */
const props = defineProps({ id: { type: String, required: true } })
const router = useRouter()

const project = ref(null)
const leads = ref([])
const canManage = ref(false)
const canAppointLead = ref(false)

const loading = ref(true)
const busy = ref(false)
const error = ref(null)
const notice = ref(null)

const leadOpen = ref(false)
const leadName = ref('')

const campaignOpen = ref(false)
const campaign = ref({ name: '', year: new Date().getFullYear(), sourceCategory: '' })

async function load() {
  loading.value = true

  try {
    const data = await api.get(`/projects/${props.id}`)
    project.value = data.project
    leads.value = data.leads
    canManage.value = data.canManage
    canAppointLead.value = data.canAppointLead
  } catch (e) {
    error.value = e.message
  } finally {
    loading.value = false
  }
}

onMounted(load)

async function appointLead() {
  busy.value = true
  error.value = null

  try {
    const data = await api.post(`/projects/${props.id}/leads`, { username: leadName.value })
    leads.value = data.leads
    leadOpen.value = false
    leadName.value = ''
    notice.value = 'Lead appointed.'
  } catch (e) {
    error.value = e.message
  } finally {
    busy.value = false
  }
}

async function removeLead(lead) {
  busy.value = true
  error.value = null

  try {
    const data = await api.delete(`/projects/${props.id}/leads/${lead.userId}`)
    leads.value = data.leads
    notice.value = `${lead.username} is no longer lead. Their account is unaffected.`
  } catch (e) {
    error.value = e.message
  } finally {
    busy.value = false
  }
}

async function createCampaign() {
  busy.value = true
  error.value = null

  try {
    const data = await api.post('/campaigns', {
      ...campaign.value,
      projectId: Number(props.id),
      sourceType: 'category',
      importNow: false,
    })

    campaignOpen.value = false
    router.push({ name: 'campaign', params: { id: data.campaign.id } })
  } catch (e) {
    error.value = e.message
  } finally {
    busy.value = false
  }
}
</script>

<template>
  <CdxProgressBar v-if="loading" aria-label="Loading project" />

  <template v-else-if="project">
    <div class="page-head">
      <div>
        <h1 class="page-title">{{ project.name }}</h1>
        <p class="page-subtitle">
          {{ formatNumber(project.campaignCount) }} campaign(s)
          <template v-if="leads.length"> · led by {{ project.leads.join(', ') }}</template>
          <template v-else> · no lead appointed</template>
        </p>
      </div>

      <div class="row">
        <CdxButton v-if="canAppointLead" @click="leadOpen = true">Appoint lead</CdxButton>
        <CdxButton
          v-if="canManage"
          action="progressive"
          weight="primary"
          @click="campaignOpen = true"
        >
          New campaign
        </CdxButton>
      </div>
    </div>

    <CdxMessage v-if="error" type="error">{{ error }}</CdxMessage>
    <CdxMessage v-if="notice" type="success">{{ notice }}</CdxMessage>

    <CdxMessage v-if="leads.length === 0" type="warning">
      This project has no lead. An admin must appoint one before campaigns can be created.
    </CdxMessage>

    <div class="grid-2">
      <div>
        <h2 class="section-title">Campaigns</h2>

        <div v-if="project.campaigns.length === 0" class="card empty">
          <p>No campaigns yet.</p>
        </div>

        <div v-else class="stack">
          <div
            v-for="c in project.campaigns"
            :key="c.id"
            class="card"
            style="cursor: pointer"
            @click="router.push({ name: 'campaign', params: { id: c.id } })"
          >
            <div class="row wrap">
              <div>
                <strong>{{ c.name }}</strong>
                <p class="muted" style="margin: 0.25rem 0 0; font-size: 0.875rem">
                  {{ c.sourceSummary || 'No source configured' }} ·
                  {{ formatNumber(c.imageCount) }} image(s)
                </p>
              </div>
              <span class="spacer"></span>
              <CdxInfoChip v-if="c.year">{{ c.year }}</CdxInfoChip>
              <CdxInfoChip v-if="!c.importedAt" status="warning">not imported</CdxInfoChip>
            </div>
          </div>
        </div>
      </div>

      <div>
        <h2 class="section-title">Lead</h2>
        <p class="muted" style="margin-top: 0; font-size: 0.875rem">
          The lead creates this project's campaigns and appoints the people who help run them. A
          person can lead only one project at a time.
        </p>

        <div class="card">
          <div v-if="leads.length === 0" class="muted">Nobody appointed.</div>

          <div v-for="lead in leads" :key="lead.userId" class="row" style="padding: 0.25rem 0">
            <strong>{{ lead.username }}</strong>
            <span v-if="lead.appointedBy" class="muted" style="font-size: 0.8125rem">
              by {{ lead.appointedBy }}
            </span>
            <span class="spacer"></span>
            <CdxButton
              v-if="canAppointLead"
              weight="quiet"
              action="destructive"
              :disabled="busy"
              @click="removeLead(lead)"
            >
              Remove
            </CdxButton>
          </div>
        </div>

        <template v-if="project.homepageUrl">
          <h2 class="section-title" style="margin-top: 1.5rem">Homepage</h2>
          <a :href="project.homepageUrl" target="_blank" rel="noopener">
            {{ project.homepageUrl }}
          </a>
        </template>
      </div>
    </div>

    <CdxDialog
      v-model:open="leadOpen"
      title="Appoint lead"
      subtitle="A person can lead only one project at a time"
      :primary-action="{
        label: 'Appoint',
        actionType: 'progressive',
        disabled: busy || !leadName.trim(),
      }"
      :default-action="{ label: 'Cancel' }"
      @primary="appointLead"
      @default="leadOpen = false"
    >
      <CdxField>
        <template #label>Wikimedia username</template>
        <CommonsLookup
          v-model="leadName"
          kind="users"
          placeholder="Start typing a username…"
        />
      </CdxField>
    </CdxDialog>

    <CdxDialog
      v-model:open="campaignOpen"
      title="New campaign"
      :subtitle="`An edition of ${project.name}`"
      :primary-action="{
        label: 'Create campaign',
        actionType: 'progressive',
        disabled: busy || !campaign.name.trim(),
      }"
      :default-action="{ label: 'Cancel' }"
      @primary="createCampaign"
      @default="campaignOpen = false"
    >
      <CdxField>
        <template #label>Campaign name</template>
        <CdxTextInput v-model="campaign.name" :placeholder="`${project.name} 2026`" />
      </CdxField>

      <CdxField>
        <template #label>Year</template>
        <CdxTextInput v-model="campaign.year" input-type="number" />
      </CdxField>

      <!-- No category here: each round gathers its own. A campaign such as
           "WLE 2026 in India" is judged as parallel rounds for Trees and
           Rivers, drawing from different categories, so asking once at
           this level could only ever describe one of them. -->
      <CdxMessage type="notice" inline>
        Images are imported per round, so each round can draw from its own
        Commons category.
      </CdxMessage>
    </CdxDialog>
  </template>
</template>
