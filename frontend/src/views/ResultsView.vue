<script setup>
import { computed, onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import { CdxButton, CdxCheckbox, CdxMessage, CdxProgressBar, CdxTabs, CdxTab } from '@wikimedia/codex'
import { api } from '@/api'
import { formatNumber, formatPixels } from '@/format'

const props = defineProps({ id: { type: String, required: true } })
const router = useRouter()

const round = ref(null)
const results = ref([])
const loading = ref(true)
const error = ref(null)
const includeDisqualified = ref(false)
const view = ref('gallery')

const isRanking = computed(() => round.value?.votingMethod === 'rank')
const maxStars = computed(() => round.value?.maxRating ?? 5)

async function load() {
  loading.value = true

  try {
    const data = await api.get(
      `/rounds/${props.id}/results?includeDisqualified=${includeDisqualified.value}`,
    )
    round.value = data.round
    results.value = data.results
  } catch (e) {
    error.value = e.message
  } finally {
    loading.value = false
  }
}

onMounted(load)

/**
 * Star fill for an average score. Ranking rounds have no star scale, and
 * yes/no averages read as a percentage rather than stars.
 */
function starsFor(average) {
  if (average === null) return 0

  return round.value.votingMethod === 'rating' ? average : average * maxStars.value
}

function download(format) {
  window.location.href = `/api/rounds/${props.id}/export?format=${format}`
}
</script>

<template>
  <CdxProgressBar v-if="loading" aria-label="Loading results" />

  <template v-else-if="round">
    <div class="page-head">
      <div>
        <h1 class="page-title">{{ round.name }} — results</h1>
        <p class="page-subtitle">
          {{ round.campaignName }} · {{ round.votingMethodLabel }} ·
          {{ formatNumber(results.length) }} image(s)
        </p>
      </div>

      <div class="row">
        <CdxButton @click="download('csv')">Download results</CdxButton>
        <CdxButton @click="download('txt')">Download entries</CdxButton>
        <CdxButton @click="router.push({ name: 'round', params: { id: round.id } })">
          Back to round
        </CdxButton>
      </div>
    </div>

    <CdxMessage v-if="error" type="error">{{ error }}</CdxMessage>

    <div class="row" style="margin-bottom: 1rem">
      <CdxTabs v-model:active="view" framed>
        <CdxTab name="gallery" label="Gallery" />
        <CdxTab name="table" label="Table" />
      </CdxTabs>

      <span class="spacer"></span>

      <CdxCheckbox v-model="includeDisqualified" @update:model-value="load">
        Include disqualified
      </CdxCheckbox>
    </div>

    <div v-if="results.length === 0" class="card empty">
      <p>No votes have been cast in this round yet.</p>
    </div>

    <!-- Gallery: ranked thumbnails with their scores underneath -->
    <div v-else-if="view === 'gallery'" class="results-gallery">
      <figure v-for="row in results" :key="row.roundImageId" class="result-tile">
        <a :href="row.descriptionUrl" target="_blank" rel="noopener">
          <img :src="row.thumbUrl" :alt="row.title" loading="lazy" />
        </a>

        <figcaption>
          <span class="result-rank">{{ row.position }}.</span>

          <span v-if="!isRanking" class="result-stars" :aria-label="`Score ${row.averageScore}`">
            <span
              v-for="star in maxStars"
              :key="star"
              class="result-star"
              :class="{ on: starsFor(row.averageScore) >= star - 0.5 }"
            >
              ★
            </span>
          </span>

          <span class="result-score">
            {{ row.averageScore ?? '—' }}
            <span class="muted">({{ row.totalScore }} / {{ row.voteCount }})</span>
          </span>
        </figcaption>

        <p v-if="row.isDisqualified" class="result-dq">{{ row.disqualificationReason }}</p>
      </figure>
    </div>

    <!-- Table: the same data, sortable at a glance and easy to scan -->
    <div v-else class="card table-scroll">
      <table>
        <thead>
          <tr>
            <th>#</th>
            <th>Image</th>
            <th>Uploader</th>
            <th>Votes</th>
            <th>{{ isRanking ? 'Avg. rank' : 'Average' }}</th>
            <th>Total</th>
            <th>Resolution</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="row in results" :key="row.roundImageId">
            <td>{{ row.position }}</td>
            <td>
              <a :href="row.descriptionUrl" target="_blank" rel="noopener">{{ row.title }}</a>
              <span v-if="row.isDisqualified" class="muted"> — {{ row.disqualificationReason }}</span>
            </td>
            <td>{{ row.uploader ?? '—' }}</td>
            <td>{{ row.voteCount }}</td>
            <td>{{ row.averageScore ?? '—' }}</td>
            <td>{{ row.totalScore }}</td>
            <td>{{ formatPixels(row.width * row.height) }}</td>
          </tr>
        </tbody>
      </table>
    </div>
  </template>
</template>
