# Research prompt from file — Implementation Plan

> **For agentic workers:** REQUIRED: Use superpowers:subagent-driven-development (if subagents available) or superpowers:executing-plans to implement this plan. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace the hardcoded research prompt in `OpenRouterService::researchNote()` with content read from a Markdown file. Config provides the file path; fallback to current prompt if file is missing or unreadable.

**Architecture:** Single prompt template file (default `resources/prompts/research.md`) with placeholders `{{idea}}` and `{{existing_research}}`. At runtime the app reads the file (or uses hardcoded fallback), replaces placeholders, optionally strips the "Existing research" sentence when `{{existing_research}}` is empty, and sends the result as the user message to OpenRouter. No per-user override; system-level only.

**Tech Stack:** Laravel 12, PHP 8.2+, Pest. Existing `OpenRouterService`, `config/research.php`.

**Spec:** `docs/superpowers/specs/2026-03-15-research-prompt-from-file-design.md`

---

## File structure (create/modify overview)

| Responsibility | Files |
|----------------|--------|
| Research config (prompt path) | `config/research.php` |
| Default prompt template | `resources/prompts/research.md` (new) |
| Load template + replace placeholders | `app/Services/OpenRouterService.php` (researchNote + optional private helper) |
| Unit tests (prompt from file, fallback) | `tests/Unit/Services/OpenRouterServiceTest.php` |

---

## Chunk 1: Config and default prompt file

### Task 1.1: Add prompt_path to config

**Files:**
- Modify: `config/research.php`

- [ ] **Step 1: Add prompt_path key**

In `config/research.php`, add after the existing `rate_limit_enabled` key:

```php
/*
 * Path to the research prompt template file (Markdown or plain text).
 * Placeholders: {{idea}}, {{existing_research}}. Override via RESEARCH_PROMPT_PATH.
 */
'prompt_path' => env('RESEARCH_PROMPT_PATH', resource_path('prompts/research.md')),
```

- [ ] **Step 2: Commit**

```bash
git add config/research.php
git commit -m "config(research): add prompt_path for research prompt template"
```

---

### Task 1.2: Create default prompt file

**Files:**
- Create: `resources/prompts/research.md`

- [ ] **Step 1: Create directory and file**

Ensure directory exists and create the file with content that matches current behaviour (spec section 6). When `{{existing_research}}` is empty, the implementation will strip the second sentence (see Task 2.2).

Content for `resources/prompts/research.md`:

```markdown
Given this idea: {{idea}}. Produce a short research note: 2–4 sentences on what's relevant, key considerations, and 2–3 concrete next steps. Be concise.
Existing research: {{existing_research}}. You may extend or refresh it.
```

- [ ] **Step 2: Commit**

```bash
git add resources/prompts/research.md
git commit -m "feat(research): add default research prompt template"
```

---

## Chunk 2: Load template and use in researchNote

### Task 2.1: Failing test — prompt from file with placeholders

**Files:**
- Modify: `tests/Unit/Services/OpenRouterServiceTest.php`

- [ ] **Step 1: Add test that uses a custom template file**

Use a temporary file with a distinct template so we can assert the request contains the replaced text. Configure the test to point at that temp file (e.g. override config in the test).

Add a new test method (after `research_note_sends_prompt_to_chat_url_and_returns_plain_text`):

```php
#[Test]
public function research_note_uses_template_file_when_available(): void
{
    $template = "Idea to research: {{idea}}. Prior: {{existing_research}}.";
    $tempPath = sys_get_temp_path() . '/ideatub_research_prompt_' . uniqid() . '.md';
    file_put_contents($tempPath, $template);

    try {
        config(['research.prompt_path' => $tempPath]);

        Http::fake([
            'https://openrouter.ai/api/v1/chat/completions' => Http::response([
                'choices' => [['message' => ['content' => 'Done.']]],
            ], 200),
        ]);

        $this->service->researchNote('Build a small SaaS.', 'Some prior notes.');

        Http::assertSent(function ($request) {
            $userMessage = $this->getUserMessageContent($request);
            return $userMessage !== null
                && str_contains($userMessage, 'Idea to research: Build a small SaaS.')
                && str_contains($userMessage, 'Prior: Some prior notes.');
        });
    } finally {
        if (file_exists($tempPath)) {
            unlink($tempPath);
        }
    }
}

private function getUserMessageContent($request): ?string
{
    $messages = $request['messages'] ?? [];
    foreach ($messages as $m) {
        if (($m['role'] ?? '') === 'user') {
            return $m['content'] ?? null;
        }
    }
    return null;
}
```

If the test class doesn’t have a shared way to get user message content, inline the logic in the assertSent callback or extract a helper used by both this test and the existing `research_note_sends_prompt...` test.

- [ ] **Step 2: Run test (expect fail — researchNote still uses hardcoded prompt)**

Run: `php artisan test tests/Unit/Services/OpenRouterServiceTest.php --filter=research_note_uses_template_file`

Expected: FAIL (request body still contains "Given this idea" / "research note", not "Idea to research").

- [ ] **Step 3: Implement template loading in OpenRouterService**

In `app/Services/OpenRouterService.php`:

1. In `researchNote()`, resolve the user message as follows:
   - `$path = config('research.prompt_path');`
   - If `$path` is non-empty and `is_readable($path)`, read file contents with `file_get_contents($path)`, trim, and use as template.
   - Else use the current hardcoded prompt string (same as today) and log a warning: `Log::warning('Research prompt file not used.', ['path' => $path ?? 'empty']);`
2. Replace `{{idea}}` with `trim($ideaContent)`.
3. Replace `{{existing_research}}` with `$existingResearch !== null && $existingResearch !== '' ? trim($existingResearch) : ''`.
4. If the template contained `{{existing_research}}` and the replacement is empty, strip the sentence that looks like "Existing research: . You may extend or refresh it." (e.g. preg_replace or str_replace so the prompt doesn’t have that fragment). Spec: "omit the 'Existing research' sentence when the value is empty."
5. Use the resulting string as the single user message in the OpenRouter payload.

Add at top of class: `use Illuminate\Support\Facades\Log;` if not already present.

- [ ] **Step 4: Run test (expect pass)**

Run: `php artisan test tests/Unit/Services/OpenRouterServiceTest.php --filter=research_note_uses_template_file`

Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Services/OpenRouterService.php tests/Unit/Services/OpenRouterServiceTest.php
git commit -m "feat(research): load research prompt from file with placeholders"
```

---

### Task 2.2: Fallback and empty existing_research behaviour

**Files:**
- Modify: `tests/Unit/Services/OpenRouterServiceTest.php`
- Modify: `app/Services/OpenRouterService.php` (ensure fallback and strip logic)

- [ ] **Step 1: Test fallback when file missing**

Add test:

```php
#[Test]
public function research_note_falls_back_to_hardcoded_prompt_when_file_missing(): void
{
    config(['research.prompt_path' => '/nonexistent/path/research.md']);

    Http::fake([
        'https://openrouter.ai/api/v1/chat/completions' => Http::response([
            'choices' => [['message' => ['content' => 'OK']]],
        ], 200),
    ]);

    $this->service->researchNote('My idea.');

    Http::assertSent(function ($request) {
        $userMessage = $this->getUserMessageContent($request);
        return $userMessage !== null
            && str_contains($userMessage, 'Given this idea')
            && str_contains($userMessage, 'My idea.')
            && str_contains($userMessage, 'research note');
    });
}
```

Run: `php artisan test tests/Unit/Services/OpenRouterServiceTest.php --filter=research_note_falls_back`

Expected: PASS if fallback is already implemented; otherwise implement fallback (path empty or not readable → use hardcoded string, log warning).

- [ ] **Step 2: Test empty existing_research strips sentence**

Add test that uses the default template (or a temp file with the "Existing research: {{existing_research}}. You may extend..." sentence) and calls `researchNote($idea, null)` or `researchNote($idea, '')`. Assert the outgoing user message does **not** contain "Existing research: ." (the empty fragment). Use the default `resource_path('prompts/research.md')` or a temp file with that second line.

Example:

```php
#[Test]
public function research_note_omits_existing_research_sentence_when_empty(): void
{
    // Use default path so template has "Existing research: {{existing_research}}..."
    Http::fake([
        'https://openrouter.ai/api/v1/chat/completions' => Http::response([
            'choices' => [['message' => ['content' => 'OK']]],
        ], 200),
    ]);

    $this->service->researchNote('An idea.', null);

    Http::assertSent(function ($request) {
        $userMessage = $this->getUserMessageContent($request);
        return $userMessage !== null
            && ! str_contains($userMessage, 'Existing research: .');
    });
}
```

Implement the strip logic in `OpenRouterService::researchNote()` if not already done: after replacing `{{existing_research}}`, if the result is empty, remove the sentence "Existing research: . You may extend or refresh it." (or equivalent) from the message.

- [ ] **Step 3: Run all OpenRouterService tests**

Run: `php artisan test tests/Unit/Services/OpenRouterServiceTest.php`

Expected: All pass, including existing `research_note_sends_prompt_to_chat_url_and_returns_plain_text` (that test doesn’t set a custom path, so it will use the default file once it exists, or fallback if the test environment doesn’t have the file; ensure either default file is present in test or fallback is used so the assertion on "Given this idea" / "research note" / "next steps" still holds).

- [ ] **Step 4: Commit**

```bash
git add app/Services/OpenRouterService.php tests/Unit/Services/OpenRouterServiceTest.php
git commit -m "test(research): fallback and empty existing_research behaviour"
```

---

## Chunk 3: Verification and docs

### Task 3.1: Feature test (optional)

**Files:**
- Modify or create: `tests/Feature/ResearchServiceTest.php` or existing research feature test

- [ ] **Step 1: Ensure research flow still creates a thought**

Run existing research feature tests (e.g. `php artisan test tests/Feature/ResearchServiceTest.php`). If they mock `OpenRouterService::researchNote`, they should still pass. If any test hits the real service with the default prompt file, ensure `resources/prompts/research.md` exists so the file is found. No change required if tests already pass.

- [ ] **Step 2: Commit (if any test changes)**

Only if you had to adjust a test or add one.

---

### Task 3.2: Docs note (optional)

**Files:**
- Modify: `CLAUDE.md` or `docs/` if there is a section on research

- [ ] **Step 1: Document prompt file**

Add a short note that the research agent’s user prompt is configurable via the file at `resources/prompts/research.md` and the `RESEARCH_PROMPT_PATH` env var. Location and wording optional; skip if no central doc exists for this.

- [ ] **Step 2: Commit (if added)**

```bash
git add <doc file>
git commit -m "docs: research prompt configurable via file"
```

---

## Summary

| Task | Description |
|------|-------------|
| 1.1 | Add `prompt_path` to `config/research.php` |
| 1.2 | Create `resources/prompts/research.md` with default template |
| 2.1 | Test template from file + implement load/substitute in `OpenRouterService::researchNote()` |
| 2.2 | Test fallback and empty `existing_research`; implement strip sentence when empty |
| 3.1 | Run feature tests; fix if needed |
| 3.2 | Optional docs update |

Plan complete and saved to `docs/superpowers/plans/2026-03-15-research-prompt-from-file.md`. Ready to execute?
