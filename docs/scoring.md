# Scoring engine

This chapter explains exactly how CycleAudit turns a surveyor's answers into a score. Every number below was read from the code and, where a number is shown as a result, produced by running the real functions (PHP 8.3, against `_computeScoreFromData`). If you change scoring, read [How to change scoring safely](#how-to-change-scoring-safely) first.

**Scale.** Scores run from **0 (best) to 100 (worst)**. A higher score means a worse cycle track. Every "score" in this chapter is really a penalty, which is why some functions are still named `penalty...`.

Source files:

| File | What it holds |
| --- | --- |
| `services/ScoreHelpers.php` | The ten parameter functions, the missing-track adjustment, the condition bands and colours. Pure functions, no database. |
| `services/ScoreService.php` | The category weights, `_computeScoreFromData()` (the engine), the database loaders, and backward-compatible function wrappers. |
| `tests/ScoreServiceTest.php`, `tests/ScoreHelpersTest.php`, `tests/ScoringEngineTest.php` | The unit tests that pin the behaviour. |

---

## 1. The formula

Each segment audit is scored in three categories. Each category is the average of its parameters, and each parameter scores 0 to 100.

```
final = (safety x 1.00 + comfort x 1.25 + continuity x 1.50) / 3.75
```

The weights are constants in `services/ScoreService.php`:

```php
const WEIGHT_SAFETY     = 1.0;
const WEIGHT_COMFORT    = 1.25;
const WEIGHT_CONTINUITY = 1.5;
const WEIGHT_TOTAL      = 3.75; // 1.0 + 1.25 + 1.5
```

The comment beside them says the weights "must match PDF". The repository does not contain that PDF and nothing checks the constants against it, so if Parisar changes its method, the constants must be changed by hand.

The result is clamped to the range 0 to 100 and rounded to two decimals:

```php
$finalScore = round(max(0.0, min(100.0, $finalScore)), 2);
```

### Condition bands

`ScoreHelpers::scoreToCondition()` turns a number into a label:

| Score | Condition | Colour (`conditionColor`) |
| --- | --- | --- |
| 0 up to and including 20 | Good | `#27ae60` |
| above 20, up to and including 40 | OK | `#f1c40f` |
| above 40, up to and including 60 | Poor | `#e67e22` |
| above 60, up to and including 80 | Bad | `#e74c3c` |
| above 80 | Very Bad | `#8e1010` |
| (unknown label) | | `#95a5a6` (grey) |

The upper bound of each band belongs to that band: exactly 20 is Good, 20.01 is OK, exactly 80 is Bad, 80.01 is Very Bad. This was confirmed by running the function at each boundary.

---

## 2. The ten parameters

| # | Parameter | Category | Fed by | Rule (score) | Function |
| --- | --- | --- | --- | --- | --- |
| P1 | Buffer zone / segregation | Safety | `segment_audits.buffer_zone` | `Segregated` or `Buffer Zone` = 0. Anything else, including blank, null or `None` = **100** | `bufferZone()` |
| P2 | Light after dark | Safety | `segment_audits.light_after_sunset` | `Yes` = 0, `Partial` = 50. Anything else, including blank or null = **100** | `lightAfterDark()` |
| P3 | Traffic calming at intersections | Safety | count of `intersections` rows with `traffic_calming` = `absent` | 0 absent = 0, 1 = 50, 2 = 75, 3 or more = 100 | `trafficCalming()` |
| P4 | Partial obstructions | Safety | `SUM(obstructions.partial_obstructions)` | under 5 = 0, 5 to 10 = 50, over 10 = 100 | `partialObstructions()` |
| P5 | Missing ramps | Continuity | count of `intersections` rows where `off_ramp` or `on_ramp` = `no ramp` | 0 = 0, 1 or 2 = 25, 3 or 4 = 50, 5 or more = 100 | `missingRamps()` |
| P6 | Missing signage and markings | Continuity | count of `intersections` rows where `markings` or `signage` = `absent` | 0 = 0, 1 = 50, 2 = 75, 3 or more = 100 | `missingSignage()` |
| P7 | Total obstructions | Continuity | `SUM(obstructions.total_obstructions)` | under 5 = 0, 5 to 10 = 50, over 10 = 100 | `totalObstructions()` |
| P8 | Track surface | Comfort | `segment_audits.surface_material` | `Interlock Blocks` or `Interblocks` = 100. Anything else (Concrete, Asphalt, blank, null) = **0** | `surface()` |
| P9 | Cyclist slowed down | Comfort | `SUM(obstructions.cyclist_slowed)` | under 5 = 0, 5 to 10 = 50, 11 to 20 = 75, over 20 = 100 | `cyclistSlowed()` |
| P10 | Shade | Comfort | `segment_audits.shade` | `No` = 100, `Partial` = 50. Anything else, including `Yes`, blank or null = **0** | `shade()` |

Categories:

```
safety_raw     = (P1 + P2 + P3 + P4) / 4
continuity_raw = (P5 + P6 + P7)      / 3
comfort_raw    = (P8 + P9 + P10)     / 3
```

### Edge cases (checked by running the code)

| Input | Result |
| --- | --- |
| Partial or total obstructions: 4 / 5 / 10 / 11 | 0 / 50 / 50 / 100 |
| Cyclist slowed: 4 / 5 / 10 / 11 / 20 / 21 | 0 / 50 / 50 / 75 / 75 / 100 |
| Missing ramps: 0 / 1 / 2 / 3 / 4 / 5 / 6 | 0 / 25 / 25 / 50 / 50 / 100 / 100 |

So "exactly 5" and "exactly 10" both fall in the **middle** band (50), and for cyclist slowed "exactly 20" falls in the 75 band. The boundaries are written as `< 5`, `<= 10`, `<= 20` in code.

**Blank and unknown handling is not symmetrical.** This is the most important thing to know when reading a score:

- A blank **buffer zone** or **light** scores 100 (worst). "We did not record it" is treated as "it is not there".
- A blank **surface** or **shade** scores 0 (best). "We did not record it" is treated as "no problem".
- A blank obstruction or intersection list counts as zero, so those parameters score 0.

The form should make these fields required so a blank is rare. If the form ever allows a blank, the engine silently picks a side as listed above.

### How intersections are counted

Intersection text is lower-cased and trimmed before comparing, so `No ramp`, `no ramp` and ` NO RAMP ` are the same (test `test_case_insensitive_intersection_matching`). The counting is **per intersection row, not per field**:

- An intersection counts once toward P5 if **either** its off-ramp **or** its on-ramp is `no ramp`. Two missing ramps at one intersection still count as 1.
- An intersection counts once toward P6 if **either** `markings` **or** `signage` is `absent`.
- An intersection counts once toward P3 if `traffic_calming` is `absent`.

### How obstructions are aggregated

The `obstructions` table can hold several rows per audit. The engine adds them up with `SUM(...)` for each of the three columns (`partial_obstructions`, `total_obstructions`, `cyclist_slowed`) and then applies the band to the total. Bands are never applied to individual rows.

---

## 3. Missing track

A segment can be partly or fully without a cycle track. The audit stores `cycle_track_missing` (`Yes` or not) and `missing_length` (metres).

```
totalLen   = max(1, segment_length)            (500 if the value is absent)
missingLen = missing_length  only when cycle_track_missing = 'Yes', else 0
missingLen = min(missingLen, totalLen)         (cannot exceed the segment)
presentLen = totalLen - missingLen
```

Rules:

1. If `presentLen` is 0 or less (the whole segment is missing), **all three categories are 100**, so the final score is 100.
2. Otherwise each category is scored on the track that exists, then blended with the missing part, which counts as 100:

```
category = (100 x missingLen + raw x presentLen) / totalLen
```

3. If `cycle_track_missing` is `Yes` but `missing_length` is blank, `missingLen` is 0 and the segment is scored as if the track were fully present. This was confirmed by running it (example E below). Treat a `Yes` with no length as a data-entry gap.
4. `ScoreHelpers::applyMissingLength()` also returns 100 if `totalLen` or `presentLen` is 0 or less.

Note that the missing-track blend is applied to each category separately, and then the weighted formula runs on the blended categories.

---

## 4. Road scores

A road is made of segments. The road score is the **length-weighted average of its segment scores**:

```
road_score = sum(segment_final x segment_length) / sum(segment_length)
```

Details, all from `calculateRoadScore()`:

- Only the **latest audit of each segment** counts (`sa.id = MAX(id)` for that segment). Earlier audits of the same segment are ignored.
- The loader uses an inner join to `segment_audits`, so **a segment with no audit contributes nothing to the road score** (it is not counted as 0 or 100). The pages guard this by only computing a road score when `$allDone` is true, meaning every segment is complete.
- Weights use `segments.length`; a negative length is treated as 0. If the total length is 0, the function returns `null`.
- The road's Safety, Comfort and Continuity scores are weighted the same way, each separately.
- The road figures are rounded to two decimals. The inputs to the road average are the **already-rounded** segment values, so a road figure can differ in the second decimal from a calculation done on unrounded numbers.
- **Query count: 3 queries for the whole road**, however many segments it has (one for audits, one for obstructions, one for intersections), then everything is computed in PHP. The old version ran 3 queries per segment.

`calculateRoadScore()` returns `score`, `condition`, `rating` (same text as `condition`), `safety_score`, `continuity_score`, `comfort_score` and `segment_count`.

### The other loaders

| Function | Purpose | Returns |
| --- | --- | --- |
| `calculateSegmentScore($auditId, $pdo)` | One segment audit (3 queries). Throws `InvalidArgumentException` if the audit id does not exist. | The six-field array from `_computeScoreFromData` |
| `calculateSegmentScoreDetailed($auditId, $pdo)` | Same, plus `parameters` with each of the ten parameter scores grouped by category. Used by the City Leader's segment review page. | Segment array + `parameters` |
| `calculateRoadScore($roadId, $pdo)` | One road, length-weighted. | See above, or `null` |
| `calculateRoadGroupScore(array $roadIds, $pdo)` | Several `roads` rows that are the same real-world road (a road group), weighted by length across all of them. Used by the city Excel export. Does not return a `rating` key. | Same shape without `rating` |
| `loadLatestSegmentScores(array $roadIds, $pdo)` | Per-segment final scores for many roads at once, latest audit only. Segments with no audit are absent. Used by the public stats and the audit lists. | List of `road_id`, `segment_id`, `length`, `final` |
| `calculateScoresForAuditIds(array $auditIds, $pdo)` | Scores for an arbitrary set of audit ids (they may span roads). Used by the city report pages. | Map keyed by audit id |

Every one of these is built on `_computeScoreFromData()`. That is the single place where arithmetic happens, so **every screen, PDF and Excel export agrees**.

### Who calls what

| Caller | Uses |
| --- | --- |
| `pages/report.php`, `pages/road_result.php`, `pages/city_audit.php` | `calculateRoadScore`, `calculateSegmentScore` |
| `pages/view.php` | `calculateSegmentScore` |
| `pages/city_segment_review.php` | `calculateSegmentScoreDetailed` |
| `pages/city_audit_report.php`, `pages/partials/city_detailed_report.php` | `calculateScoresForAuditIds` |
| `pages/published_audits.php`, `api/public/audits.php`, `api/public/stats.php` | `loadLatestSegmentScores` |
| `api/reports/export-pdf.php`, `api/reports/export-excel.php` | `calculateRoadScore`, `calculateSegmentScore` |
| `api/reports/export-city-excel.php` | `calculateRoadGroupScore` |
| `api/reports/session.php` | `calculateSegmentScore` |
| `helpers/CityDashboard.php`, `helpers/PastAudits.php` | `ScoreHelpers::scoreToCondition` |

---

## 5. Cyclist slowed uses one scale

There is exactly one scale for every road: under 5 = 0, 5 to 10 = 50, 11 to 20 = 75, over 20 = 100. `ScoreHelpers::cyclistSlowed(float $count)` takes only the count; it has no road-type argument and the engine passes nothing else. Searching the scoring code finds no road-specific scale or road list. Git history shows how it got here: commit `aa14bea` ("Scoring: one cyclist-slowed scale for every road, remove Group B list", merged as PR #159) replaced an earlier road-specific list.

One leftover: every scoring query still selects `r.name AS road_name`, but no scoring function reads it. It is harmless and can be removed.

The helper's tests pin the four bands (`test_cyclistSlowed_under5_returns_zero` and the three after it).

---

## 6. Worked examples

All numbers below came from running `_computeScoreFromData()`.

**Example A: the best possible segment.** `Segregated`, light `Yes`, shade `Yes`, surface Concrete, no obstructions, no intersections, track fully present. P1 = 0, P2 = 0, P3 = 0, P4 = 0, so safety is 0. Surface 0, slowed 0, shade 0, so comfort is 0. P5 to P7 are 0, so continuity is 0. Final **0, Good**.

**Example B: the worst present track.** Interlock Blocks surface, no buffer zone, no light, no shade, 11 partial and 11 total obstructions, 21 cyclist slowed, five intersections with missing ramps, markings, signage and traffic calming. Every parameter is 100, so every category is 100 and the final is **100, Very Bad**.

**Example C: a mixed segment with 200 m of 500 m missing.** Inputs: buffer `Buffer Zone`, light `Partial`, shade `Partial`, surface Concrete, `cycle_track_missing = Yes`, `missing_length = 200`, partial obstructions 6, total obstructions 3, cyclist slowed 12, one intersection with `No ramp` off-ramp, markings and signage present, traffic calming absent.

Present length is 500 - 200 = 300.

| Category | Parameters | Raw average | Blended with missing track |
| --- | --- | --- | --- |
| Safety | P1 = 0, P2 = 50, P3 = 50 (1 absent), P4 = 50 (6 partial) | 150 / 4 = 37.5 | (100 x 200 + 37.5 x 300) / 500 = **62.5** |
| Continuity | P5 = 25 (1 missing ramp), P6 = 0, P7 = 0 (3 total) | 25 / 3 = 8.33 | (100 x 200 + 8.33 x 300) / 500 = **45** |
| Comfort | P8 = 0, P9 = 75 (12 slowed), P10 = 50 | 125 / 3 = 41.67 | (100 x 200 + 41.67 x 300) / 500 = **65** |

```
final = (62.5 x 1.00 + 65 x 1.25 + 45 x 1.50) / 3.75
      = (62.5 + 81.25 + 67.5) / 3.75
      = 211.25 / 3.75
      = 56.33  ->  Poor
```

**Example D: the whole segment is missing.** `cycle_track_missing = Yes`, `missing_length = 500` on a 500 m segment. All categories are 100, final **100, Very Bad**.

**Example E: `Yes` with no length.** Same as a default segment (buffer `None`, light `No`, shade `No`, Concrete) but `cycle_track_missing = Yes` and `missing_length` blank. Safety 50 (buffer 100, light 100, calming 0, partial 0), continuity 0, comfort 33.33 (shade 100), final **24.44, OK**. The blank length means no track is treated as missing.

**Road example.** Two segments, 300 m scoring 20 and 100 m scoring 60: (20 x 300 + 60 x 100) / 400 = **30, OK**.

---

## 7. How the tests pin behaviour

| File | Methods | What it protects |
| --- | --- | --- |
| `tests/ScoreServiceTest.php` | 52 | Every parameter band and boundary through the legacy `penalty...` wrappers, `applyMissingLength`, the condition labels and colours, and the weighted formula (`test_weighted_score_formula`, `..._all_worst`, `..._continuity_weighs_more`). |
| `tests/ScoringEngineTest.php` | 12 | `_computeScoreFromData` end to end: perfect = 0, worst = 100, fully missing = 100, half missing blends, weighting, case-insensitive intersections, clamping to 0 and 100, band labels, and the length-weighted road average. |
| `tests/ScoreHelpersTest.php` | 12 | Each `ScoreHelpers` method directly. |

All three are pure PHP and need no database.

> **Important: `ScoreHelpersTest.php` does not run in CI.** `phpunit.xml` lists test files one by one under `<testsuite>`, and `tests/ScoreHelpersTest.php` is not in the list, so neither `composer test` nor the GitHub Actions job (`php vendor/bin/phpunit`) runs it. (Fifteen other test files are also missing from the list, 16 files and 134 test methods in all, counted by reading `phpunit.xml`. I did not run PHPUnit myself, so confirm with a real run.) The bands are still covered by `ScoreServiceTest.php`, which is listed, but a change made only to `ScoreHelpers` is not guarded by its own test file. Adding the file to `phpunit.xml` is a one-line fix.

---

## 8. How to change scoring safely

Scoring is a policy decision made by Parisar's methodology, not only a code decision. Confirm a change with them first, then:

1. **Edit one place.**
   - A band or rule for a parameter: the matching method in `services/ScoreHelpers.php`.
   - A category weight: the `WEIGHT_*` constants in `services/ScoreService.php`. Keep `WEIGHT_TOTAL` equal to their sum, because it is a separate constant.
   - A condition label boundary: `ScoreHelpers::scoreToCondition()`.
2. **Do not edit the `penalty...` wrappers** in `ScoreService.php` for logic. They only forward to `ScoreHelpers`.
3. **Update the tests in the same commit.** Change the expected values in `ScoreServiceTest.php`, `ScoringEngineTest.php` and `ScoreHelpersTest.php`. Run all three explicitly (`vendor/bin/phpunit tests/ScoreHelpersTest.php`), since the last one is not in the default run.
4. **Update every place that repeats the numbers.** The repository repeats parts of the scoring text and colours outside the scoring classes:
   - `README.md`, section "How scoring works".
   - `api/reports/export-excel.php`: a printed legend of the five bands (around line 347) and Excel colour maps (around lines 170 and 181).
   - `api/reports/export-pdf.php`: its own colour map (around line 220).
   - `api/reports/export-city-excel.php`: Excel colour maps (around lines 144 and 154).
   - `pages/partials/city_detailed_report.php`: the zone colours (around line 67).
   - `helpers/CityDashboard.php`: the list `CITY_CONDITIONS`.
   - `js/landing_audits.js`: a colour map for the landing page.
   - CSS custom colours in `css/city_dashboard.css`, `css/report_detail.css`, `css/landing.css`, `css/dashboard.css`.
   - The Parisar methodology PDF, which the weights comment refers to.
5. **Remember the constants nobody reads.** `config/constants.php` defines `SCORE_GOOD` (20), `SCORE_OK` (40), `SCORE_POOR` (60), `SCORE_BAD` (80) and a legacy `SCORE_MODERATE` (40). Searching all PHP and JS finds **no code that reads them**. `ScoreHelpers` hard-codes the same numbers. Changing the constants alone changes nothing. Either keep them in step or remove them.
6. **Old audits are re-scored on the fly.** Scores are **never stored**; they are recomputed from the answers every time. A change therefore applies to every past audit the moment it is deployed, including published ones. Tell Parisar before releasing a change that moves historical numbers.
7. **Spot-check with a real audit** by comparing a page, the PDF and the Excel export for the same road. They should show the same score.

---

## 9. Questions to confirm with Parisar

These are choices the code makes that the methodology may not have spelled out. The code and tests do not record any of them as open questions, so they are listed here as observations for a developer to raise:

- A blank buffer zone or light scores 100, but a blank surface or shade scores 0.
- A segment with `cycle_track_missing = Yes` and no `missing_length` is scored as fully present.
- Two missing ramps at one intersection count as one, and an intersection with both markings and signage absent also counts as one.
- Segments with no audit are left out of a road score rather than counted as 100.
- The cyclist-slowed bands use "5 to 10" and "10 to 20" in prose, but the code puts exactly 10 in the 50 band and exactly 20 in the 75 band.
