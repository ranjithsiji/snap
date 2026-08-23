<script setup>
import { RouterLink } from 'vue-router'
import { CdxButton } from '@wikimedia/codex'
import { useSession } from '@/stores/session'

const session = useSession()

// The four round types, in the order a campaign usually runs them.
const stages = [
  { name: 'Yes / no', detail: 'Cut a large intake down to a shortlist.' },
  { name: 'Star rating', detail: 'Score what survives the first pass.' },
  { name: 'Rank order', detail: 'Put the finalists in order.' },
  { name: 'Jury meeting', detail: 'Settle disagreements and agree a result.' },
]

const points = [
  {
    title: 'Import from Commons',
    body: 'Point a round at a category and the entries arrive with their metadata. On Toolforge this reads the Wikimedia replica directly, so a category of thousands takes seconds.',
  },
  {
    title: 'Share the work fairly',
    body: 'Set how many jurors must see each photograph and the images are divided evenly, with no juror shown the same file twice.',
  },
  {
    title: 'Judge round by round',
    body: 'Each round inherits from the one before it by threshold, so a campaign narrows from thousands of entries to a result.',
  },
  {
    title: 'Finish together',
    body: 'Where rankings disagree, every juror’s order is kept and shown side by side, with a place to argue the case and record the outcome.',
  },
]
</script>

<template>
  <div class="landing">
    <section class="landing-hero">
      <h1 class="landing-title">Judging for Wiki Loves campaigns</h1>
      <p class="landing-lede">
        Snap takes a photography competition from a Commons category to a
        final result: importing the entries, dividing them fairly among the
        jury, and collecting the votes round by round.
      </p>

      <div class="landing-actions">
        <!-- Signed in, the useful destination is the juror's own work
             rather than this page. -->
        <CdxButton
          v-if="session.isAuthenticated"
          action="progressive"
          weight="primary"
          @click="$router.push({ name: 'my-rounds' })"
        >
          Go to my rounds
        </CdxButton>
        <CdxButton
          v-else
          action="progressive"
          weight="primary"
          @click="$router.push({ name: 'login' })"
        >
          Sign in
        </CdxButton>

        <CdxButton @click="$router.push({ name: 'about' })">
          What Snap does
        </CdxButton>
      </div>
    </section>

    <section class="landing-section">
      <h2 class="landing-heading">How a campaign runs</h2>
      <ol class="landing-stages">
        <li v-for="(stage, index) in stages" :key="stage.name" class="landing-stage">
          <span class="landing-step">{{ index + 1 }}</span>
          <div>
            <strong>{{ stage.name }}</strong>
            <p class="muted landing-stage-detail">{{ stage.detail }}</p>
          </div>
        </li>
      </ol>
      <p class="muted landing-note">
        Rounds are configured per campaign; the order above is the usual one,
        not a fixed sequence.
      </p>
    </section>

    <section class="landing-section">
      <h2 class="landing-heading">What it handles</h2>
      <div class="grid-2">
        <div v-for="point in points" :key="point.title" class="card">
          <h3 class="landing-point-title">{{ point.title }}</h3>
          <p class="muted landing-point-body">{{ point.body }}</p>
        </div>
      </div>
    </section>

    <section class="card landing-footer-card">
      <div>
        <strong>Taking part as a juror?</strong>
        <p class="muted landing-point-body">
          You need an invitation from the campaign’s organizer. Once invited,
          sign in with your Wikimedia account and the round appears under
          <RouterLink :to="{ name: 'my-rounds' }">my rounds</RouterLink>.
        </p>
      </div>
      <CdxButton
        v-if="!session.isAuthenticated"
        action="progressive"
        @click="$router.push({ name: 'login' })"
      >
        Sign in
      </CdxButton>
    </section>
  </div>
</template>

<style scoped>
.landing {
  display: flex;
  flex-direction: column;
  gap: var(--spacing-200);
}

.landing-hero {
  max-width: 44rem;
  padding: var(--spacing-100) 0 0;
}

.landing-title {
  margin: 0 0 var(--spacing-50);
  font-size: 1.75rem;
  font-weight: var(--font-weight-bold);
  line-height: 1.25;
}

.landing-lede {
  margin: 0 0 var(--spacing-125);
  font-size: var(--font-size-large);
  color: var(--color-subtle);
}

.landing-actions {
  display: flex;
  flex-wrap: wrap;
  gap: var(--spacing-75);
}

.landing-section {
  display: flex;
  flex-direction: column;
  gap: var(--spacing-100);
}

.landing-heading {
  margin: 0;
  font-size: var(--font-size-x-large);
  font-weight: var(--font-weight-bold);
}

.landing-stages {
  display: grid;
  gap: var(--spacing-75);
  margin: 0;
  padding: 0;
  list-style: none;
  grid-template-columns: repeat(auto-fit, minmax(15rem, 1fr));
}

.landing-stage {
  display: flex;
  align-items: flex-start;
  gap: var(--spacing-75);
  padding: var(--spacing-100);
  background-color: var(--background-color-base);
  border: var(--border-base);
  border-radius: var(--border-radius-base);
}

.landing-step {
  flex: none;
  display: grid;
  place-items: center;
  width: 1.5rem;
  height: 1.5rem;
  border-radius: var(--border-radius-circle);
  background-color: var(--background-color-progressive-subtle);
  color: var(--color-progressive);
  font-size: var(--font-size-x-small);
  font-weight: var(--font-weight-bold);
}

.landing-stage-detail,
.landing-point-body {
  margin: var(--spacing-25) 0 0;
  font-size: var(--font-size-small);
}

.landing-note {
  margin: 0;
  font-size: var(--font-size-small);
}

.landing-point-title {
  margin: 0;
  font-size: var(--font-size-medium);
  font-weight: var(--font-weight-bold);
}

.landing-footer-card {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: var(--spacing-100);
  flex-wrap: wrap;
}
</style>
