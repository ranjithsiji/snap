<script setup>
import { RouterLink } from 'vue-router'
import { CdxButton, CdxInfoChip } from '@wikimedia/codex'
import { useSession } from '@/stores/session'

const session = useSession()

// The four judging methods, in the order a campaign normally runs them.
// A round may use any of these; the sequence is a convention, not a rule.
const methods = [
  {
    name: 'Yes / no',
    detail:
      'A first pass over a large intake. Jurors keep or reject each file, ' +
      'cutting thousands of uploads down to a workable shortlist.',
  },
  {
    name: 'Star rating',
    detail:
      'Files are scored on a scale. Useful in the middle of a campaign, ' +
      'where a shortlist is still too long to rank but too good to reject.',
  },
  {
    name: 'Rank order',
    detail:
      'Jurors place the remaining files in order. Duplicate ranks are ' +
      'refused, and a rearrange step renumbers the gallery cleanly.',
  },
  {
    name: 'Jury meeting',
    detail:
      'The finalisation round. Conflicting rankings are shown side by ' +
      'side, jurors argue their case, and a result is agreed and locked.',
  },
]

const features = [
  {
    title: 'Import straight from Commons',
    body:
      'Point a round at a category and Snap pulls in every file. On ' +
      'Toolforge it reads the Wikimedia replica database directly, so a ' +
      'category of thousands arrives in seconds rather than minutes. Off ' +
      'Toolforge it falls back to the Commons API automatically. Imports ' +
      'that fail partway can be retried and resume where they stopped.',
  },
  {
    title: 'Images are never copied',
    body:
      'Snap stores metadata and URLs only. Every photograph is loaded ' +
      'from Wikimedia servers at the moment it is displayed, so the tool ' +
      'holds no copies and adds nothing to what Commons already serves.',
  },
  {
    title: 'Rounds that build on rounds',
    body:
      'A round can inherit its images from the one before it by ' +
      'threshold — everything above three stars, or the top half by score. ' +
      'The chain is shown as a sequence, so it stays obvious which round ' +
      'fed which. Nothing follows a jury meeting.',
  },
  {
    title: 'Work shared out fairly',
    body:
      'Set a quorum and Snap divides the images among the jurors so each ' +
      'file receives the required number of independent votes, with the ' +
      'load spread evenly and no juror ever shown the same image twice.',
  },
  {
    title: 'Disqualification rules',
    body:
      'Exclude files below a resolution, outside an upload window, or ' +
      'uploaded by the people running the campaign — organizers, jurors, ' +
      'and maintainers. Rules are applied at import and recorded, so an ' +
      'excluded file can always be traced to the rule that removed it.',
  },
  {
    title: 'Jury seats, not fixed people',
    body:
      'A juror occupies a seat. If someone cannot continue, the seat is ' +
      'reassigned to another editor, who inherits the work and can revise ' +
      'the earlier votes — with clear notice of what they have taken on.',
  },
  {
    title: 'Conflicts recorded, not flattened',
    body:
      'Where jurors rank a file differently, every ranking is kept and ' +
      'shown. Jurors add opinions against a specific image, endorse each ' +
      "other's arguments, and settle it on the record.",
  },
  {
    title: 'Log in with your Wikimedia account',
    body:
      'Authentication is Wikimedia OAuth 2.0 — no separate password, and ' +
      'jurors are identified by the username they already edit under.',
  },
]

// Mirrors UserRole on the server. Authority is scoped: a lead's powers
// apply to their own project only.
const roles = [
  { name: 'Admin', scope: 'Everywhere', body: 'Creates projects and appoints leads. Can override any role below.' },
  { name: 'Lead', scope: 'One project', body: 'Creates campaigns within their project and appoints organizers. A lead leads a single project.' },
  { name: 'Organizer', scope: 'One campaign', body: 'Creates rounds, imports images, invites jurors, and sees results.' },
  { name: 'Jury', scope: 'One round', body: 'Judges the images allotted to them and takes part in the final meeting.' },
]
</script>

<template>
  <div class="page-head">
    <div>
      <h1 class="page-title">About Snap</h1>
      <p class="page-subtitle">
        A judging tool for Wiki Loves photography campaigns on Wikimedia Commons
      </p>
    </div>
    <CdxButton
      v-if="!session.isAuthenticated"
      action="progressive"
      weight="primary"
      @click="$router.push({ name: 'login' })"
    >
      Log in
    </CdxButton>
  </div>

  <div class="card about-lede">
    <p>
      Wiki Loves campaigns gather tens of thousands of photographs, and a
      jury has to turn that into a shortlist and then a result. Snap runs
      that process: it imports the entries from Commons, divides them fairly
      among the jurors, collects the votes round by round, and gives the
      jury a place to settle the final order together.
    </p>
    <p class="muted">
      Snap runs at
      <a href="https://snap.toolforge.org/">snap.toolforge.org</a>, hosted on
      Wikimedia Toolforge.
    </p>
  </div>

  <h2 class="section-title">Judging methods</h2>
  <p class="muted about-intro">
    A campaign narrows its field over several rounds. Each round picks the
    method that suits how many images are left.
  </p>

  <div class="about-methods">
    <div v-for="(method, index) in methods" :key="method.name" class="card about-method">
      <span class="about-step">{{ index + 1 }}</span>
      <div>
        <h3 class="about-method-name">{{ method.name }}</h3>
        <p class="muted about-method-detail">{{ method.detail }}</p>
      </div>
    </div>
  </div>

  <h2 class="section-title">Features</h2>

  <div class="grid-2 about-features">
    <div v-for="feature in features" :key="feature.title" class="card">
      <h3 class="about-feature-title">{{ feature.title }}</h3>
      <p class="muted about-feature-body">{{ feature.body }}</p>
    </div>
  </div>

  <h2 class="section-title">Who does what</h2>
  <p class="muted about-intro">
    Snap mirrors how a campaign is actually organised: a project such as Wiki
    Loves Earth runs in many countries, each with its own campaign and its own
    rounds.
  </p>

  <div class="card">
    <div v-for="role in roles" :key="role.name" class="about-role">
      <div class="about-role-head">
        <strong>{{ role.name }}</strong>
        <CdxInfoChip>{{ role.scope }}</CdxInfoChip>
      </div>
      <p class="muted about-role-body">{{ role.body }}</p>
    </div>
    <p class="muted about-role-note">
      Roles accumulate rather than replace one another. Someone who judged
      last year may organize this year, and a lead or organizer can hold a
      jury seat at the same time — all under one Wikimedia login.
    </p>
  </div>

  <div class="card about-footer">
    <p class="muted">
      Snap is free software built for the Wikimedia community. Photographs
      shown in the tool remain on Wikimedia Commons under the licences their
      authors chose.
    </p>
    <RouterLink v-if="session.isAuthenticated" :to="{ name: 'my-rounds' }">
      Back to my rounds
    </RouterLink>
  </div>
</template>

<style scoped>
.about-lede {
  max-width: 55rem;
}

.about-lede p {
  margin: 0 0 var(--spacing-75);
}

.about-lede p:last-child {
  margin-bottom: 0;
}

.about-intro {
  max-width: 45rem;
  margin: calc(var(--spacing-50) * -1) 0 var(--spacing-100);
}

.about-methods {
  display: grid;
  gap: var(--spacing-75);
}

.about-method {
  display: flex;
  align-items: flex-start;
  gap: var(--spacing-100);
}

/* Numbered because the methods run in a usual order, even though a round
   may use any of them. */
.about-step {
  flex: none;
  display: grid;
  place-items: center;
  width: 1.75rem;
  height: 1.75rem;
  border-radius: var(--border-radius-circle);
  background-color: var(--background-color-progressive-subtle);
  color: var(--color-progressive);
  font-size: var(--font-size-x-small);
  font-weight: var(--font-weight-bold);
}

.about-method-name {
  margin: 0 0 var(--spacing-25);
  font-size: var(--font-size-medium);
  font-weight: var(--font-weight-bold);
}

.about-method-detail,
.about-feature-body,
.about-role-body {
  margin: 0;
  font-size: var(--font-size-small);
}

.about-features {
  align-items: start;
}

.about-feature-title {
  margin: 0 0 var(--spacing-50);
  font-size: var(--font-size-medium);
  font-weight: var(--font-weight-bold);
}

.about-role {
  padding: var(--spacing-75) 0;
  border-bottom: var(--border-subtle);
}

.about-role-head {
  display: flex;
  align-items: center;
  gap: var(--spacing-50);
  margin-bottom: var(--spacing-25);
}

.about-role-note {
  margin: var(--spacing-100) 0 0;
  font-size: var(--font-size-small);
}

.about-footer {
  display: flex;
  align-items: center;
  gap: var(--spacing-100);
  flex-wrap: wrap;
  margin-top: var(--spacing-150);
}

.about-footer p {
  margin: 0;
  flex: 1 1 20rem;
  font-size: var(--font-size-small);
}
</style>
