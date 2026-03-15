# IdeaTub — Research prompt from file (system-level)

**Date:** 2026-03-15  
**Status:** Draft  
**Scope:** Make the research agent’s user-message prompt configurable via a single Markdown file in the repo, with optional config path override. No end-user customisation.

---

## 1. Summary

- **Goal:** Replace the hardcoded research prompt in `OpenRouterService::researchNote()` with content read from a file. You (or any developer) can change behaviour by editing the file and deploying; no code change required.
- **Scope:** System-level only. No per-user overrides, no UI for editing the prompt, no audit of overrides.
- **Mechanism:** One Markdown (or plain text) file contains the user message template. Placeholders `{{idea}}` and optionally `{{existing_research}}` are replaced at runtime. Config provides the file path so it can be overridden per environment if needed.

---

## 2. File location and loading

### 2.1 Default path

- **Default file:** `resources/prompts/research.md` (in repo, versioned).
- **Config key:** Add to existing `config/research.php`: `'prompt_path' => env('RESEARCH_PROMPT_PATH', resource_path('prompts/research.md'))`.
- At runtime, the app resolves the path via `config('research.prompt_path')` and reads the file contents. No end-user or app UI can change this path; only config/env and code can.

### 2.2 When the file is missing or unreadable

- **Behaviour:** If the path is missing, empty, or the file cannot be read, fall back to the current hardcoded prompt (same string as today in `OpenRouterService::researchNote()`). Log a warning so operators know the file was not used.
- **Rationale:** Safe default; existing behaviour preserved if the file is not yet added or is misconfigured.

---

## 3. File content and placeholders

### 3.1 Content

- The file contains **plain text or Markdown**: the exact user message to send to the model. No YAML frontmatter or structured sections in v1; the whole file (trimmed) is the template.
- **Required placeholder:** `{{idea}}` — replaced with the idea content (trimmed) when building the message.
- **Optional placeholder:** `{{existing_research}}` — replaced with the existing research text when the “extend/refresh” flow is used; if the caller does not pass existing research, replace with empty string. If the template does not mention `{{existing_research}}`, the implementation may still replace it (no-op when absent) for consistency.

### 3.2 No system message

- OpenRouter is called with a single user message (current behaviour). The file defines that user message only. No system message or role customisation in the file for v1.

### 3.3 Backward compatibility

- The **default content** of `resources/prompts/research.md` (when first added) must match current behaviour. So the initial file body should be the same as the current hardcoded prompt, with `{{idea}}` and `{{existing_research}}` in the right places (see Example below).

---

## 4. Runtime behaviour

### 4.1 Where it runs

- **Service:** Prompt loading and placeholder replacement live in `OpenRouterService::researchNote()` (or a small dedicated helper used by it). Flow:
  1. Resolve path from `config('research.prompt_path')`.
  2. If file exists and is readable, read contents and use as template; otherwise fall back to hardcoded prompt and log warning.
  3. Replace `{{idea}}` with the given idea content (trimmed).
  4. Replace `{{existing_research}}` with the given existing research string (or empty if null/not provided).
  5. Send the resulting string as the single user message in the OpenRouter request.

### 4.2 Caching (optional)

- **v1:** Read the file on each research request. No caching. If performance becomes an issue, a later change can cache file contents (e.g. by path) with invalidation on deploy or TTL.

### 4.3 Model and max_tokens

- Unchanged: still taken from config (e.g. `config('services.openrouter.metadata_model')`, `max_tokens` in code). Not read from the file in v1.

---

## 5. Config

### 5.1 Add to `config/research.php`

- `prompt_path`: string, path to the prompt template file. Default `resource_path('prompts/research.md')`, overridable via `RESEARCH_PROMPT_PATH` env.

### 5.2 Existing keys

- `rate_limit_enabled` remains as-is; no change to rate limiting in this feature.

---

## 6. Example default prompt file

Initial content of `resources/prompts/research.md` (matches current behaviour):

```markdown
Given this idea: {{idea}}. Produce a short research note: 2–4 sentences on what's relevant, key considerations, and 2–3 concrete next steps. Be concise.
Existing research: {{existing_research}}. You may extend or refresh it.
```

**Note:** When there is no existing research, `{{existing_research}}` is replaced with an empty string. The second sentence may then read “Existing research: . You may extend…” — the implementation should omit the “Existing research” sentence when the value is empty so the prompt stays clean.

---

## 7. Testing

- **Unit:** Given a temp file with a known template (e.g. “Idea: {{idea}}.”), assert that `researchNote($idea, $existing)` returns a response and that the HTTP request body contains the replaced text (e.g. mock HTTP and assert request payload).
- **Fallback:** When path is invalid or file missing, assert that the hardcoded prompt is used (e.g. assert request contains “Given this idea” and the idea text) and that a warning is logged (or assert fallback path is hit).
- **Integration/feature:** Optional: one test that runs research with the default file and asserts a thought is created (same as existing research feature tests).

---

## 8. Out of scope (v1)

- Per-user or app-level content override (UI or API).
- Audit of who changed the prompt or which prompt was used per run.
- Guardrails on prompt content (e.g. moderation API).
- Reading `max_tokens` or model from the file (still from config/code).
- System message or multi-message templates in the file.
