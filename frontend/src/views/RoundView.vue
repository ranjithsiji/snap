<script setup>
import { computed, onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import {
  CdxButton,
  CdxDialog,
  CdxField,
  CdxIcon,
  CdxInfoChip,
  CdxMessage,
  CdxProgressBar,
  CdxTextInput,
} from '@wikimedia/codex'
import { cdxIconDownload, cdxIconTrash } from '@wikimedia/codex-icons'
import CommonsLookup from '@/components/CommonsLookup.vue'
import { api } from '@/api'
import { formatDeadline, formatNumber, formatPixels } from '@/format'

const props = defineProps({ id: { type: String, required: true } })
const router = useRouter()

const round = ref(null)
const stats = ref(null)
const jurors = ref([])
const loading = ref(true)
const error = ref(null)
const notice = ref(null)
const busy = ref(false)

const preview = ref(null)
const importing = ref(false)

/**
 * A round that draws from its own category but holds nothing yet.
 *
 * Only its own category counts: a round filled from the campaign pool has
 * nothing to import here, and one that genuinely imported zero files is
 * still unimported as far as the coordinator is concerned — it needs the
 * button, not an empty progress bar.
 */
const needsImport = computed(
  () => Boolean(round.value?.sourceCategory) && (stats.value?.files ?? 0) === 0,
)

/** The category's size, asked before importing so the job is legible. */
async function loadPreview() {
  if (!needsImport.value) {
    return
  }

  try {
    preview.value = await api.get(`/rounds/${props.id}/import/preview`)
  } catch {
    // A count is a courtesy, not a precondition: the import still runs.
    preview.value = { total: null }
  }
}

async function runImport() {
  error.value = null
  notice.value = null
  importing.value = true

  try {
    const data = await api.post(`/rounds/${props.id}/import`)

    // Reloads rather than patching state: the import changes the image
    // counts, the qualified set and the source list at once.
    await load()

    const added = data.import?.added ?? 0
    const seen = data.import?.processed ?? 0

    notice.value = added === seen
      ? `Imported ${formatNumber(added)} image(s).`
      : `Imported ${formatNumber(added)} image(s) from ${formatNumber(seen)} read.`

    if ((data.warnings ?? []).length > 0) {
      notice.value += ` ${data.warnings.join(' ')}`
    }
  } catch (e) {
    error.value = e.message
  } finally {
    importing.value = false
  }
}

const deriveOpen = ref(false)
const derive = ref({ name: '', minAcceptCount: '', minAverageScore: '', topN: '' })
const derivePreview = ref(null)

const replaceOpen = ref(false)
const replaceTarget = ref(null)
const replaceUsername = ref('')

function openReplace(juror) {
  replaceTarget.value = juror
  replaceUsername.value = ''
  replaceOpen.value = true
}

async function submitReplacement() {
  if (!replaceUsername.value.trim()) return

  error.value = null
  busy.value = true

  try {
    const data = await api.post(
      `/rounds/${props.id}/jurors/${replaceTarget.value.id}/replace`,
      { username: replaceUsername.value.trim() },
    )

    jurors.value = data.jurors
    notice.value = data.message
    replaceOpen.value = false
  } catch (e) {
    error.value = e.message
  } finally {
    busy.value = false
  }
}

const settings = computed(() => round.value?.fileSettings ?? {})

const canManage = ref(false)
const deleteOpen = ref(false)

async function load() {
  loading.value = true

  try {
    const data = await api.get(`/rounds/${props.id}`)
    round.value = data.round
    stats.value = data.statistics
    jurors.value = data.jurors
    canManage.value = data.canManage
  } catch (e) {
    error.value = e.message
  } finally {
    loading.value = false
  }
}

async function deleteRound() {
  busy.value = true
  error.value = null

  try {
    await api.delete(`/rounds/${props.id}`)
    router.push({ name: 'campaign', params: { id: round.value.campaignId } })
  } catch (e) {
    error.value = e.message
    deleteOpen.value = false
  } finally {
    busy.value = false
  }
}

// The count is asked after the round is known, since whether to ask at all
// depends on its source and image count.
onMounted(async () => {
  await load()
  await loadPreview()
})

async function transition(state) {
  error.value = null
  notice.value = null
  busy.value = true

  try {
    const data = await api.post(`/rounds/${props.id}/state/${state}`)
    round.value = data.round
    stats.value = data.statistics
    notice.value = `Round is now ${data.round.state}.`
  } catch (e) {
    error.value = e.message
  } finally {
    busy.value = false
  }
}

function download(format) {
  window.location.href = `/api/rounds/${props.id}/export?format=${format}`
}

/** Criteria payload with blank fields stripped, as the API treats absent as "no filter". */
function deriveCriteria() {
  const criteria = {}

  for (const key of ['minAcceptCount', 'minAverageScore', 'topN']) {
    if (derive.value[key] !== '') criteria[key] = Number(derive.value[key])
  }

  return criteria
}

async function previewDerivation() {
  try {
    const data = await api.post(`/rounds/${props.id}/derive/preview`, deriveCriteria())
    derivePreview.value = data
  } catch (e) {
    error.value = e.message
  }
}

async function submitDerivation() {
  busy.value = true

  try {
    const data = await api.post(`/rounds/${props.id}/derive`, {
      name: derive.value.name,
      ...deriveCriteria(),
    })

    deriveOpen.value = false
    // The campaign page, not the new round itself: it lists every round in
    // the campaign, which is the useful view right after adding one — the
    // organizer can see it took its place alongside the others.
    router.push({ name: 'campaign', params: { id: data.round.campaignId } })
  } catch (e) {
    error.value = e.message
  } finally {
    busy.value = false
  }
}
</script>

<template>
  <CdxProgressBar v-if="loading" aria-label="Loading round" />

  <template v-else-if="round">
    <div class="page-head">
      <div>
        <h1 class="page-title">{{ round.name }}</h1>
        <p class="page-subtitle">
          {{ round.votingMethodLabel }} · {{ round.state }}
          <template v-if="round.derivedFromRoundName">
            · from {{ round.derivedFromRoundName }}
          </template>
        </p>
      </div>
      <div class="row" style="gap: 0.5rem">
        <CdxButton @click="router.push({ name: 'round-edit', params: { id: round.id } })">
          Edit round
        </CdxButton>
        <!-- Only for someone who organizes this round's campaign or leads
             its project — the same check the server enforces, so this
             never offers an action the API would refuse. Active rounds
             have jurors voting right now; pause first is the deliberate
             step that says the round is really meant to stop. -->
        <CdxButton
          v-if="canManage"
          action="destructive"
          :disabled="round.state === 'active'"
          :title="round.state === 'active' ? 'Pause the round before deleting it.' : undefined"
          @click="deleteOpen = true"
        >
          <CdxIcon :icon="cdxIconTrash" /> Delete round
        </CdxButton>
      </div>
    </div>

    <!-- The round's lifecycle in one place at the top of the page, rather
         than buried below the file-settings details at the bottom of the
         card: these are the actions someone visiting this page is most
         likely here to take, and they used to require scrolling past
         everything else to reach. -->
    <div class="card round-actions-panel">
      <div class="row row-end wrap">
        <!-- Disabled rather than hidden while the round is empty: the
             server refuses this anyway, and offering a button that only
             returns an error is worse than showing why it is not ready. -->
        <CdxButton
          v-if="round.state === 'draft' || round.state === 'paused'"
          action="progressive"
          :disabled="busy || needsImport"
          :title="needsImport ? 'Import the round\'s images first.' : undefined"
          @click="transition('active')"
        >
          Activate
        </CdxButton>

        <CdxButton v-if="round.state === 'active'" :disabled="busy" @click="transition('paused')">
          Pause
        </CdxButton>

        <CdxButton
          v-if="round.state === 'active' || round.state === 'paused'"
          action="progressive"
          :disabled="busy"
          @click="transition('finalized')"
        >
          Finalize round
        </CdxButton>

        <CdxButton @click="router.push({ name: 'round-results', params: { id: round.id } })">
          View results
        </CdxButton>

        <CdxButton @click="download('csv')">Download results</CdxButton>
        <CdxButton @click="download('txt')">Download entries</CdxButton>

        <CdxButton
          v-if="round.state === 'finalized'"
          action="progressive"
          weight="primary"
          @click="deriveOpen = true"
        >
          Create next round
        </CdxButton>
      </div>
    </div>

    <CdxMessage v-if="error" type="error">{{ error }}</CdxMessage>
    <CdxMessage v-if="notice" type="success">{{ notice }}</CdxMessage>

    <!-- Before anything is imported there is no progress to report, so the
         page leads with the one action that matters. Saving a round no
         longer reads its category: a category of thousands took long
         enough that the save looked hung, and a failure lost the round
         along with the import. -->
    <div v-if="needsImport" class="card import-panel">
      <h2 class="section-title" style="margin-top: 0">Import images</h2>

      <p class="muted import-lede">
        This round has no images yet. They are read from
        <strong>{{ round.sourceCategory }}</strong> on Commons.
      </p>

      <!-- The total is one replica query, so it can be shown before
           committing to the import rather than after. -->
      <p v-if="preview && preview.total !== null" class="import-total">
        <strong>{{ formatNumber(preview.total) }}</strong>
        <span class="muted"> file(s) in this category</span>
      </p>
      <p v-else-if="preview" class="muted import-total">
        This deployment reads Commons through the API, which cannot count a
        category without fetching it.
      </p>

      <!-- A spinner alone leaves the coordinator guessing whether a long
           import is working; naming the category and the total it is
           working through is what makes it legible. -->
      <div v-if="importing" class="import-running">
        <CdxProgressBar aria-label="Importing files from Commons" />
        <p class="import-running-text">
          Importing files from Commons…
          <span v-if="preview && preview.total !== null" class="muted">
            {{ formatNumber(preview.total) }} to read. This can take a few
            minutes for a large category — leaving this page does not stop it.
          </span>
        </p>
      </div>

      <div v-else class="row import-actions">
        <CdxButton
          action="progressive"
          weight="primary"
          :disabled="busy"
          @click="runImport"
        >
          <CdxIcon :icon="cdxIconDownload" /> Import images
        </CdxButton>
        <span v-if="preview === null" class="muted">Checking the category…</span>
      </div>
    </div>

    <!-- How far the round has got, above its configuration: it is the
         question anyone opening this page came to answer. -->
    <div v-else-if="stats" class="round-summary">
      <div class="card progress-card">
        <h2 class="section-title" style="margin-top: 0">Round progress</h2>

        <div class="progress-figure">
          <span class="progress-number">{{ stats.percentComplete }}%</span>
          <span class="muted">judged</span>
        </div>

        <div class="meter progress-meter">
          <span :style="{ width: `${stats.percentComplete}%` }"></span>
        </div>

        <div class="row progress-legend">
          <span>{{ formatNumber(stats.completedTasks) }} votes cast</span>
          <span class="spacer"></span>
          <span>{{ formatNumber(stats.openTasks) }} remaining</span>
        </div>
      </div>

      <div class="card stat-cards">
        <div class="stat-cell">
          <span class="stat-label">Images</span>
          <span class="stat-value">{{ formatNumber(stats.qualifiedFiles) }}</span>
          <span v-if="stats.disqualifiedFiles" class="muted stat-note">
            {{ formatNumber(stats.disqualifiedFiles) }} disqualified
          </span>
        </div>

        <div class="stat-cell">
          <span class="stat-label">Jurors</span>
          <span class="stat-value">{{ formatNumber(stats.jurors) }}</span>
          <span class="muted stat-note">{{ stats.quorum }} per image</span>
        </div>

        <div class="stat-cell">
          <span class="stat-label">At quorum</span>
          <span class="stat-value">{{ formatNumber(stats.imagesAtQuorum) }}</span>
          <span class="muted stat-note">
            {{ formatNumber(stats.imagesRemaining) }} short
          </span>
        </div>

        <div class="stat-cell">
          <span class="stat-label">Uploaders</span>
          <span class="stat-value">{{ formatNumber(stats.uploaders) }}</span>
          <span class="muted stat-note">{{ (stats.fileTypes ?? []).join(', ') }}</span>
        </div>
      </div>
    </div>

    <div class="card">
      <div class="grid-2">
        <!-- Left: configuration and jury progress -->
        <div>
          <h2 class="section-title">Voting deadline</h2>
          <p class="muted" style="margin-top: 0">{{ formatDeadline(round.votingDeadline) }}</p>

          <template v-if="round.details">
            <h2 class="section-title">Directions</h2>
            <p class="muted" style="margin-top: 0">{{ round.details }}</p>
          </template>

          <h2 class="section-title">Quorum</h2>
          <p class="muted" style="margin-top: 0">
            {{ round.effectiveQuorum }} juror(s) per photo
          </p>

          <template v-if="round.derivationCriteria">
            <h2 class="section-title">Selection criteria</h2>
            <p class="muted" style="margin-top: 0">{{ round.derivationCriteria }}</p>
          </template>

          <h2 class="section-title">Jurors</h2>
          <div v-if="jurors.length === 0" class="muted">No jurors assigned yet.</div>

          <div v-else class="juror-grid">
            <div v-for="juror in jurors" :key="juror.id">
              <div class="row" style="gap: 0.375rem">
                <strong>{{ juror.username }}</strong>
                <CdxInfoChip v-if="!juror.isActive">withdrawn</CdxInfoChip>
                <CdxInfoChip v-if="juror.replacedUsername" status="warning">
                  replaced {{ juror.replacedUsername }}
                </CdxInfoChip>
              </div>

              <div class="juror-progress">
                {{ juror.expected > 0 ? `${juror.percentComplete}%` : 'N/A' }}
              </div>

              <div class="meter">
                <span :style="{ width: `${Math.min(100, juror.percentComplete)}%` }"></span>
              </div>

              <div class="muted" style="font-size: 0.8125rem">
                {{ formatNumber(juror.votesCast) }} out of {{ formatNumber(juror.expected) }}<br />
                {{ formatNumber(juror.remaining) }} image(s) remaining
              </div>

              <CdxButton
                v-if="round.state !== 'finalized'"
                weight="quiet"
                style="margin-top: 0.25rem"
                @click="openReplace(juror)"
              >
                Replace
              </CdxButton>
            </div>
          </div>
        </div>

        <!-- Right: file information and settings -->
        <div>
          <!-- Only what the summary above does not already say. Repeating
               the same eight figures twice on one page made neither
               reading authoritative. -->
          <h2 class="section-title">Judging tasks</h2>

          <dl class="stat-list">
            <div><dt>Total tasks</dt><dd>{{ formatNumber(stats.tasks) }}</dd></div>
            <div><dt>Completed</dt><dd>{{ formatNumber(stats.completedTasks) }}</dd></div>
            <div><dt>Open</dt><dd>{{ formatNumber(stats.openTasks) }}</dd></div>
          </dl>

          <details class="disclosure" style="margin-top: 1rem">
            <summary>Round file settings</summary>
            <dl class="stat-list">
              <div>
                <dt>Disqualify jurors</dt>
                <dd>{{ settings.disqualifyJurors ? 'Yes' : 'No' }}</dd>
              </div>
              <div>
                <dt>Min. resolution</dt>
                <dd>
                  {{
                    settings.disqualifyByResolution
                      ? formatPixels(settings.minResolutionPixels)
                      : 'Not enforced'
                  }}
                </dd>
              </div>
              <div>
                <dt>Upload date window</dt>
                <dd>
                  {{
                    settings.disqualifyByUploadDate
                      ? `${settings.uploadDateFrom?.slice(0, 10) ?? '—'} → ${settings.uploadDateTo?.slice(0, 10) ?? '—'}`
                      : 'Not enforced'
                  }}
                </dd>
              </div>
              <div>
                <dt>Disqualify coordinators</dt>
                <dd>{{ settings.disqualifyCoordinators ? 'Yes' : 'No' }}</dd>
              </div>
              <div>
                <dt>Disqualify maintainers</dt>
                <dd>{{ settings.disqualifyMaintainers ? 'Yes' : 'No' }}</dd>
              </div>
              <div>
                <dt>Disqualify organizers</dt>
                <dd>{{ settings.disqualifyOrganizers ? 'Yes' : 'No' }}</dd>
              </div>
              <div>
                <dt>Shown to jurors</dt>
                <dd>
                  {{
                    [
                      settings.showFilename && 'filename',
                      settings.showLink && 'link',
                      settings.showResolution && 'resolution',
                    ]
                      .filter(Boolean)
                      .join(', ') || 'image only'
                  }}
                </dd>
              </div>
            </dl>
          </details>
        </div>
      </div>

    </div>

    <CdxDialog
      v-model:open="deriveOpen"
      title="Create next round"
      subtitle="Carry forward the images that met your criteria"
      :primary-action="{ label: 'Create round', actionType: 'progressive', disabled: busy }"
      :default-action="{ label: 'Cancel' }"
      @primary="submitDerivation"
      @default="deriveOpen = false"
    >
      <CdxField>
        <template #label>New round name</template>
        <CdxTextInput v-model="derive.name" placeholder="Round 2" />
      </CdxField>

      <CdxField v-if="round.votingMethod === 'yesno'">
        <template #label>Minimum accept votes</template>
        <template #description>Keep images at least this many jurors accepted.</template>
        <CdxTextInput v-model="derive.minAcceptCount" input-type="number" min="1" />
      </CdxField>

      <CdxField v-else>
        <template #label>Minimum average score</template>
        <CdxTextInput v-model="derive.minAverageScore" input-type="number" step="0.1" />
      </CdxField>

      <CdxField>
        <template #label>Keep only the top N</template>
        <template #description>Leave blank to keep everything that met the criteria.</template>
        <CdxTextInput v-model="derive.topN" input-type="number" min="1" />
      </CdxField>

      <CdxButton @click="previewDerivation">Preview selection</CdxButton>

      <CdxMessage v-if="derivePreview" type="notice" style="margin-top: 0.75rem">
        {{ formatNumber(derivePreview.count) }} image(s) would carry over
        ({{ derivePreview.criteria }}).
      </CdxMessage>
    </CdxDialog>

    <CdxDialog
      v-model:open="replaceOpen"
      title="Replace juror"
      :subtitle="replaceTarget ? `Hand ${replaceTarget.username}'s seat to someone else` : ''"
      :primary-action="{
        label: 'Replace juror',
        actionType: 'progressive',
        disabled: busy || !replaceUsername.trim(),
      }"
      :default-action="{ label: 'Cancel' }"
      @primary="submitReplacement"
      @default="replaceOpen = false"
    >
      <CdxMessage v-if="replaceTarget" type="warning" inline>
        The {{ formatNumber(replaceTarget.votesCast) }} vote(s) already cast on this seat will be
        transferred to the new juror and will continue to count towards the quorum. The new juror
        can review and change any of them.
      </CdxMessage>

      <CdxField style="margin-top: 0.75rem">
        <template #label>New juror's Wikimedia username</template>
        <CommonsLookup
          v-model="replaceUsername"
          kind="users"
          placeholder="Start typing a username…"
        />
      </CdxField>
    </CdxDialog>

    <CdxDialog
      v-model:open="deleteOpen"
      title="Delete round?"
      :primary-action="{ label: 'Delete permanently', actionType: 'destructive', disabled: busy }"
      :default-action="{ label: 'Cancel' }"
      @primary="deleteRound"
      @default="deleteOpen = false"
    >
      <p style="margin-top: 0"><strong>{{ round.name }}</strong></p>
      <CdxMessage type="warning" inline>
        This also deletes the images imported into the round and every vote, ranking and comment
        the jury recorded against them. This cannot be undone.
      </CdxMessage>
    </CdxDialog>
  </template>
</template>
