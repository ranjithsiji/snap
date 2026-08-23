<script setup>
import { ref } from 'vue'
import { useRouter } from 'vue-router'
import {
  CdxButton,
  CdxCheckbox,
  CdxField,
  CdxMessage,
  CdxRadio,
  CdxTextArea,
  CdxTextInput,
} from '@wikimedia/codex'
import { api } from '@/api'

const router = useRouter()

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
const error = ref(null)
const progress = ref(null)

async function submit() {
  error.value = null
  busy.value = true
  progress.value = form.value.importNow
    ? 'Importing images from Commons. Large categories can take a few minutes…'
    : null

  try {
    const data = await api.post('/campaigns', {
      ...form.value,
      year: Number(form.value.year) || null,
    })

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
      <h1 class="page-title">New campaign</h1>
      <p class="page-subtitle">
        The source is set once here; every round draws its images from it.
      </p>
    </div>
  </div>

  <CdxMessage v-if="error" type="error">{{ error }}</CdxMessage>
  <CdxMessage v-if="progress" type="notice">{{ progress }}</CdxMessage>

  <form class="card" style="max-width: 46rem" @submit.prevent="submit">
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
      <CdxTextInput v-model="form.sourceCategory" placeholder="Enter category" />
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

    <CdxField is-fieldset>
      <CdxCheckbox v-model="form.importNow">
        Import images from Commons now
        <template #description>
          Leave this on unless you want to configure the campaign first and import later.
        </template>
      </CdxCheckbox>
    </CdxField>

    <div class="row row-end" style="margin-top: 1rem">
      <CdxButton type="button" @click="router.back()">Cancel</CdxButton>
      <CdxButton action="progressive" weight="primary" type="submit" :disabled="busy">
        {{ busy ? 'Creating…' : 'Create campaign' }}
      </CdxButton>
    </div>
  </form>
</template>
