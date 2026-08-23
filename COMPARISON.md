# Comparison with WLX Jury Tool

Notes from reading [wlxjury](https://commons.wikimedia.org/wiki/Commons:WLX_Jury_Tool)
(Scala/Play, `intracer/wlxjury`) — the tool this project's screenshots came
from. Recorded so the design choices here are traceable to something real.

## Where the designs agree

**Round-robin distribution.** Their `DistributeImages.newSelection` deals
image *i* to jurors `(i + j) % jurorCount` for `j` in `0 until distribution`.
`AssignmentService::allocate` does the same rotation, then breaks ties by
current load so an uneven `images × quorum ÷ jurors` split stays balanced.

**"Everyone sees everything" mode.** Their `distribution = 0` assigns every
image to every juror. Our `quorum = 0` resolves to the active juror count,
which produces the same allocation.

**Round chaining by criteria.** Their `prevSelectedBy`, `prevMinAvgRate` and
`topImages` map onto our `DerivationCriteria` fields `minAcceptCount`,
`minAverageScore` and `topN`.

**Import-time filters.** Their `minMpx` / `minImageSize` correspond to our
`disqualifyByResolution` + `minResolutionPixels`.

## Where this project differs, and why

**Votes are separate from assignments.** In wlxjury a `Selection` row is
both the assignment and the vote: `rate = 0` means "assigned, not yet
judged", `-1` rejected, `1` selected. Here `ImageAssignment` and `Vote` are
distinct tables.

The split costs one extra table but buys three things: an unjudged image is
the *absence* of a vote rather than a magic zero, so `COUNT(votes)` is
directly the quorum check; rating rounds do not have to reserve 0 as a
sentinel inside their 1..N scale; and a juror seat can be handed to someone
else by transferring votes without disturbing the allocation.

**Disqualification is recorded, not filtered.** wlxjury filters images out
of a round before they are ever stored. We store them with
`isDisqualified` and a human-readable reason, so a coordinator can see
exactly what was excluded and why, and re-run the rules after editing them.

**Campaign-level image pool.** wlxjury fetches from Commons per round. Here
the campaign imports once into `campaign_image` and rounds select from that
pool, so a multi-round contest hits the Commons API once rather than per
round. Verified against a real category: 6,658 images in ~2m37s.

**Spillover.** wlxjury's allocation is fixed. Ours prefers a juror's own
assignments but lets them pick up images still short of quorum once their
list is done, so a round is not blocked by one juror going quiet.

## Ideas worth taking from wlxjury

Not implemented here yet; noted as genuinely useful:

- **Comments-only rounds** (`Rates(0, "Comments only")`) — a discussion pass
  with no scoring at all.
- **Per-criteria rating** (`hasCriteria`, `CriteriaRate`) — scoring an image
  on several axes rather than one number.
- **Half-star ratings** (`halfStar`).
- **`excludeCategory`** — subtracting a Commons category from the source, a
  neat way to drop previously-used images.
- **Rating scales up to 20** (`rateRounds = 3 to 20`); we cap at 10.
- **Region and monument filters** — specific to WLM's monument database.
- **`optionalRate`** — whether unrated images count against a juror's
  average, which changes how `numberOfJurorsForAverageRate` is computed.
