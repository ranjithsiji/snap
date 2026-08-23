<script setup>
import { computed, onMounted, ref, watch } from 'vue'
import { useRouter } from 'vue-router'
import {
  CdxButton,
  CdxCheckbox,
  CdxChipInput,
  CdxField,
  CdxMessage,
  CdxRadio,
  CdxSelect,
  CdxTextArea,
  CdxTextInput,
} from '@wikimedia/codex'
import CommonsLookup from '@/components/CommonsLookup.vue'
import { api } from '@/api'

const props = defineProps({
  id: { type: String, default: null },
  campaignId: { type: String, default: null },
})

const router = useRouter()
const isEdit = computed(() => props.id !== null)

const votingMethods = [
  { label: 'Yes/No', value: 'yesno' },
  { label: 'Rating', value: 'rating' },
  { label: 'Ranking', value: 'rank' },
]

const form = ref({
  name: 'Round 1',
  votingDeadline: '',
  votingMethod: 'yesno',
  maxRating: 5,
  // Each round gathers its own images: a campaign is judged as parallel
  // rounds drawing from different categories, so the source belongs here
  // rather than once at the campaign.
  sourceType: 'category',
  sourceCategory: '',
  details: '',
  showOwnStatistics: false,
  quorum: 1,
  fileSettings: {
    disqualifyJurors: false,
    disqualifyByResolution: false,
    minResolutionPixels: 2000000,
    disqualifyByUploadDate: false,
    uploadDateFrom: '',
    uploadDateTo: '',
    disqualifyCoordinators: false,
    disqualifyMaintainers: false,
    disqualifyOrganizers: false,
    showFilename: false,
    showLink: false,
    showResolution: false,
  },
})

// CdxChipInput works with {value} objects; the API takes plain usernames.
const jurorChips = ref([])
const jurorSuggestions = ref([])
const jurorSearch = ref('')

const busy = ref(false)
const error = ref(null)
const targetCampaignId = ref(props.campaignId)

let debounce = null

watch(jurorSearch, (value) => {
  clearTimeout(debounce)

  if (value.trim().length < 2) {
    jurorSuggestions.value = []
    return
  }

  // Commons is a shared resource: query on a pause, not every keystroke.
  debounce = setTimeout(async () => {
    try {
      const data = await api.get(`/commons/users?q=${encodeURIComponent(value.trim())}`)
      const chosen = jurorChips.value.map((chip) => chip.value)

      jurorSuggestions.value = data.users
        .filter((name) => !chosen.includes(name))
        .map((name) => ({ label: name, value: name }))
    } catch {
      jurorSuggestions.value = []
    }
  }, 250)
})

onMounted(async () => {
  if (!isEdit.value) return

  try {
    const data = await api.get(`/rounds/${props.id}`)
    const round = data.round

    targetCampaignId.value = String(round.campaignId)

    form.value = {
      name: round.name,
      votingDeadline: round.votingDeadline ? round.votingDeadline.slice(0, 10) : '',
      votingMethod: round.votingMethod,
      maxRating: round.maxRating,
      sourceType: round.sourceType ?? 'category',
      sourceCategory: round.sourceCategory ?? '',
      details: round.details ?? '',
      showOwnStatistics: round.showOwnStatistics,
      quorum: round.quorum,
      fileSettings: {
        ...round.fileSettings,
        uploadDateFrom: round.fileSettings.uploadDateFrom?.slice(0, 10) ?? '',
        uploadDateTo: round.fileSettings.uploadDateTo?.slice(0, 10) ?? '',
      },
    }

    jurorChips.value = data.jurors.map((juror) => ({ label: juror.username, value: juror.username }))
  } catch (e) {
    error.value = e.message
  }
})

async function submit() {
  error.value = null
  busy.value = true

  const payload = {
    ...form.value,
    quorum: Number(form.value.quorum) || 0,
    maxRating: Number(form.value.maxRating) || 5,
    votingDeadline: form.value.votingDeadline || null,
    jurors: jurorChips.value.map((chip) => chip.value),
    fileSettings: {
      ...form.value.fileSettings,
      minResolutionPixels: Number(form.value.fileSettings.minResolutionPixels) || 2000000,
      uploadDateFrom: form.value.fileSettings.uploadDateFrom || null,
      uploadDateTo: form.value.fileSettings.uploadDateTo || null,
    },
  }

  try {
    const data = isEdit.value
      ? await api.patch(`/rounds/${props.id}`, payload)
      : await api.post(`/campaigns/${targetCampaignId.value}/rounds`, payload)

    router.push({ name: 'round', params: { id: data.round.id } })
  } catch (e) {
    error.value = e.message
  } finally {
    busy.value = false
  }
}
</script>

<template>
  <div class="page-head">
    <h1 class="page-title">{{ isEdit ? 'Edit round' : 'Add round' }}</h1>
  </div>

  <CdxMessage v-if="error" type="error">{{ error }}</CdxMessage>

  <form class="card" @submit.prevent="submit">
    <div class="grid-2">
      <!-- Left column: identity, source, jury -->
      <div>
        <CdxField>
          <template #label>Round name</template>
          <CdxTextInput v-model="form.name" required />
        </CdxField>

        <div class="grid-2" style="gap: 1rem">
          <CdxField>
            <template #label>Voting deadline</template>
            <CdxTextInput v-model="form.votingDeadline" input-type="date" placeholder="YYYY-MM-DD" />
          </CdxField>

          <CdxField>
            <template #label>Vote method</template>
            <CdxSelect v-model:selected="form.votingMethod" :menu-items="votingMethods" />
          </CdxField>
        </div>

        <CdxField v-if="form.votingMethod === 'rating'">
          <template #label>Maximum rating</template>
          <template #description>Highest number of stars a juror can award (2–10).</template>
          <CdxTextInput v-model="form.maxRating" input-type="number" min="2" max="10" />
        </CdxField>

        <CdxField>
          <template #label>Commons category</template>
          <template #description>
            The category this round draws its images from. Suggestions show
            how many files each holds, so an empty or near-miss category is
            visible before importing. Leave blank to inherit from a previous
            round instead.
          </template>
          <CommonsLookup
            v-model="form.sourceCategory"
            kind="categories"
            placeholder="Images from Wiki Loves…"
          />
        </CdxField>

        <CdxField>
          <template #label>Directions</template>
          <template #description>Shown to jurors before and during voting.</template>
          <CdxTextArea v-model="form.details" rows="4" />
        </CdxField>

        <CdxField is-fieldset>
          <template #label>Show own statistics</template>
          <template #description>
            Whether to show own voting statistics (e.g. number of accepted or declined images) of
            juror for the round.
          </template>
          <CdxRadio v-model="form.showOwnStatistics" name="showOwnStats" :input-value="true">
            Yes
          </CdxRadio>
          <CdxRadio v-model="form.showOwnStatistics" name="showOwnStats" :input-value="false">
            No
          </CdxRadio>
        </CdxField>

        <CdxField>
          <template #label>Quorum</template>
          <template #description>
            The number of jurors that must vote on each image. Use 0 to require every juror.
          </template>
          <CdxTextInput v-model="form.quorum" input-type="number" min="0" />
        </CdxField>

        <CdxField>
          <template #label>Jurors</template>
          <template #description>
            Enter the username of the juror you want to add to this round.
          </template>
          <CdxChipInput
            v-model:input-chips="jurorChips"
            v-model:input-value="jurorSearch"
            :menu-items="jurorSuggestions"
            separate-input
          />
        </CdxField>
      </div>

      <!-- Right column: file settings -->
      <div>
        <h2 class="section-title">Round file settings</h2>

        <CdxField is-fieldset>
          <CdxCheckbox v-model="form.fileSettings.disqualifyJurors">
            Disqualify jurors
          </CdxCheckbox>
          <CdxCheckbox v-model="form.fileSettings.disqualifyByResolution">
            Disqualify by resolution
          </CdxCheckbox>
          <CdxCheckbox v-model="form.fileSettings.disqualifyByUploadDate">
            Disqualify by upload date
          </CdxCheckbox>
          <CdxCheckbox v-model="form.fileSettings.disqualifyCoordinators">
            Disqualify coordinators
          </CdxCheckbox>
          <CdxCheckbox v-model="form.fileSettings.disqualifyMaintainers">
            Disqualify maintainers
          </CdxCheckbox>
          <CdxCheckbox v-model="form.fileSettings.disqualifyOrganizers">
            Disqualify organizers
          </CdxCheckbox>
          <CdxCheckbox v-model="form.fileSettings.showFilename">Show filename</CdxCheckbox>
          <CdxCheckbox v-model="form.fileSettings.showLink">Show link</CdxCheckbox>
          <CdxCheckbox v-model="form.fileSettings.showResolution">Show resolution</CdxCheckbox>
        </CdxField>

        <CdxField v-if="form.fileSettings.disqualifyByResolution">
          <template #label>Min. resolution</template>
          <template #description>
            Minimum image resolution in pixels. Images below this resolution will be automatically
            disqualified. Default is 2,000,000 pixels (2 megapixels).
          </template>
          <CdxTextInput v-model="form.fileSettings.minResolutionPixels" input-type="number" />
        </CdxField>

        <template v-if="form.fileSettings.disqualifyByUploadDate">
          <CdxField>
            <template #label>Uploaded on or after</template>
            <CdxTextInput v-model="form.fileSettings.uploadDateFrom" input-type="date" />
          </CdxField>

          <CdxField>
            <template #label>Uploaded on or before</template>
            <CdxTextInput v-model="form.fileSettings.uploadDateTo" input-type="date" />
          </CdxField>
        </template>
      </div>
    </div>

    <div class="row row-end" style="margin-top: 1.5rem">
      <CdxButton type="button" @click="router.back()">Cancel</CdxButton>
      <CdxButton action="progressive" weight="primary" type="submit" :disabled="busy">
        {{ busy ? 'Saving…' : isEdit ? 'Save round' : 'Add Round' }}
      </CdxButton>
    </div>
  </form>
</template>
