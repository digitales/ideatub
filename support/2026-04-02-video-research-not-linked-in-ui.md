# Video research “not linked” to the video item — Support investigation

**Date**: 2026-04-02  
**Status**: Resolved (UI gaps fixed in app)  
**Priority**: Medium  
**Reported By**: Customer / Internal  

## Issue description

Users perceived that **video research** was not linked to the **video thought**: opening the formatted research page showed no path back to the video, and the **home** recent feed did not surface a **View research** action on video cards (unlike Stream).

## Customer impact

- Confusion when navigating from research to the source video thought.
- On the home page, video thoughts with completed research did not show an obvious **View research** link even though `metadata.research_thought_id` was set.

## Root cause analysis

Linkage **in data** was already correct for the video-research pipeline:

- Video root: `metadata.research_thought_id` → latest research thought (see `VideoResearchService::runAndSaveForVideoRoot`).
- Research thought: `source_metadata.video_thought_id` and `metadata.video_thought_id` (see same service).

**Gaps were in the UI:**

1. **Research show** (`idea/research_show.blade.php`) had no block for linked video (unlike “Related email”).
2. **Home feed** (`IdeaIndexCardPresenter` + `index_thought_cards.blade.php`) did not pass or render `buildVideoResearchUrlByThoughtId` results; **Stream** already did via `StreamThoughtCardPresenter`.

## Resolution

- **Research page**: `IdeaController::showResearch` resolves `linkedVideo` via `resolveLinkedVideoForResearchThought()` when `video_thought_id` appears in `source_metadata` or `metadata`, and the target is a `video` thought for the same user. Template shows **Related video** with **Open video thought** (detail page).
- **Home feed**: `buildIdeaIndexCardPresenters` now uses `buildVideoResearchUrlByThoughtId` (same helper as Stream). Index cards for `type=video` show a compact **Video** strip with **Open video**, transcript status, and **View research** when a URL resolves.

## Verification

- Feature: `ResearchShowTest::test_research_show_shows_related_video_when_linked_via_source_metadata`
- Unit: `IdeaIndexCardPresenterTest::it_exposes_video_latest_research_url_when_presenter_is_given_one`

## References

- `app/Services/Video/VideoResearchService.php`
- `app/Http/Controllers/IdeaController.php` — `buildVideoResearchUrlByThoughtId`, `resolveLinkedVideoForResearchThought`
- `resources/views/idea/research_show.blade.php`
- `resources/views/idea/index_thought_cards.blade.php`
- `app/View/Presenters/Thoughts/IdeaIndexCardPresenter.php`

## Lessons learned

Bidirectional navigation should match stored link metadata; “linked in DB” without UI affordances reads as “broken” to users.
