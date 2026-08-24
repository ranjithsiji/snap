<script setup>
import { computed, onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import {
  CdxButton,
  CdxCheckbox,
  CdxField,
  CdxMessage,
  CdxProgressBar,
  CdxRadio,
  CdxTextArea,
  CdxTextInput,
} from '@wikimedia/codex'
import CommonsLookup from '@/components/CommonsLookup.vue'
import { api } from '@/api'

const props = defineProps({ id: { type: String, default: null } })

const router = useRouter()
const isEdit = computed(() => props.id !== null)

const form = ref({
  name: '',
  year: new Date().getFullYear(),
  description: '',
  sourceType: 'category',
  sourceCategory: '',
  sourceUrl: '',
  sourceFileList: '',
  importNow: true,
})

const busy = ref(false)
const loading = ref(false)
const error = ref(null)
const progress = ref(null)

onMounted(async () => {
  if (!isEdit.value) {
    return
  }

  loading.value = true

  try {
    const data = await api.get(`/campaigns/${props.id}`)
    const campaign = data.campaign

    form.value = {
      name: campaign.name,
      year: campaign.year ?? new Date().getFullYear(),
      description: campaign.description ?? '',
      sourceType: campaign.sourceType ?? 'category',
      sourceCategory: campaign.sourceCategory ?? '',
      sourceUrl: campaign.sourceUrl ?? '',
      sourceFileList: campaign.sourceFileList ?? '',
      // Editing settings is not a reason to re-read Commons; the campaign
      // page has its own button for that.
      importNow: false,
    }
  } catch (e) {
    error.value = e.message
  } finally {
    loading.value = false
  }
})

async function submit() {
  error.value = null
  busy.value = true
  progress.value = !isEdit.value && form.value.importNow
    ? 'Importing images from Commons. Large categories can take a few minutes…'
    : null

  try {
    const payload = { ...form.value, year: Number(form.value.year) || null }

    const data = isEdit.value
      ? await api.patch(`/campaigns/${props.id}`, payload)
      : await api.post('/campaigns', payload)

    router.push({ name: 'campaign', params: { id: data.campaign.id } })
  } catch (e) {
    error.value = e.message
  } finally {
    busy.value = false
    progress.value = null
  }
}
</script>

<template>
  <div class="page-head">
    <div>
      <h1 class="page-title">{{ isEdit ? 'Edit campaign' : 'New campaign' }}</h1>
      <p class="page-subtitle">
        The campaign pool is optional: each round imports from its own
        Commons category unless it is left without one.
      </p>
    </div>
  </div>

  <CdxMessage v-if="error" type="error">{{ error }}</CdxMessage>
  <CdxMessage v-if="progress" type="notice">{{ progress }}</CdxMessage>

  <CdxProgressBar v-if="loading" aria-label="Loading campaign" />

  <form v-else class="card" style="max-width: 46rem" @submit.prevent="submit">
    <CdxField>
      <template #label>Campaign name</template>
      <CdxTextInput v-model="form.name" placeholder="Wiki Loves Earth in India 2026" required />
    </CdxField>

    <CdxField>
      <template #label>Year</template>
      <CdxTextInput v-model="form.year" input-type="number" />
    </CdxField>

    <CdxField>
      <template #label>Description</template>
      <CdxTextArea v-model="form.description" rows="3" />
    </CdxField>

    <CdxField is-fieldset>
      <template #label>Source</template>
      <CdxRadio v-model="form.sourceType" name="sourceType" input-value="category">
        Category on Wikimedia Commons
      </CdxRadio>
      <CdxRadio v-model="form.sourceType" name="sourceType" input-value="filelist_url">
        File List URL
      </CdxRadio>
      <CdxRadio v-model="form.sourceType" name="sourceType" input-value="filelist">
        File List
      </CdxRadio>
    </CdxField>

    <CdxField v-if="form.sourceType === 'category'">
      <template #label>Category</template>
      <template #description>
        Category on Wikimedia Commons that gathers all contest images. Example: Images from Wiki
        Loves Monuments 2017 in Ghana.
      </template>
      <CommonsLookup
        v-model="form.sourceCategory"
        kind="categories"
        placeholder="Search Commons categories…"
      />
    </CdxField>

    <CdxField v-else-if="form.sourceType === 'filelist_url'">
      <template #label>File list URL</template>
      <template #description>
        A URL returning one file name per line. Must be http or https.
      </template>
      <CdxTextInput v-model="form.sourceUrl" input-type="url" placeholder="https://…" />
    </CdxField>

    <CdxField v-else>
      <template #label>File list</template>
      <template #description>One file name per line, with or without the "File:" prefix.</template>
      <CdxTextArea v-model="form.sourceFileList" rows="8" placeholder="Example.jpg&#10;Another.jpg" />
    </CdxField>

    <!-- Only on creation: an edit should not silently start a long read of
         Commons, and the campaign page has its own re-import button. -->
    <CdxField v-if="!isEdit" is-fieldset>
      <CdxCheckbox v-model="form.importNow">
        Import images from Commons now
        <template #description>
          Only needed for a shared pool. Rounds import from their own category,
          so this can be left off.
        </template>
      </CdxCheckbox>
    </CdxField>

    <div class="row row-end" style="margin-top: 1rem">
      <CdxButton type="button" @click="router.back()">Cancel</CdxButton>
      <CdxButton action="progressive" weight="primary" type="submit" :disabled="busy">
        {{ busy ? (isEdit ? 'Saving…' : 'Creating…') : isEdit ? 'Save campaign' : 'Create campaign' }}
      </CdxButton>
    </div>
  </form>
</template>
