# Prompt 4: Quick Capture Templates

**Job:** Five copy-paste sentence starters optimized for clean metadata extraction. Each one is designed to trigger the right classification in your Open Brain's processing pipeline.

**When to use:** Keep these handy as a reference. After a week of capturing, you won't need them — you'll develop your own natural patterns. But they're useful for building the habit early.

**What you'll get:** Five starter patterns with explanations of why each one works.

**Output feeds into:** Reference tool. These are templates for what you type into your Open Brain capture channel or say directly to any MCP-connected AI using "save this" or "remember this."

## 1. Decision Capture

```
Decision: [what was decided]. Context: [why]. Owner: [who].
```

**Example:** `Decision: Moving the launch to March 15. Context: QA found three blockers in the payment flow. Owner: Rachel.`

**Why it works:** "Decision" triggers the `task` type. Naming an owner triggers people extraction. The context gives the embedding meaningful content to match against later.

## 2. Person Note

```
[Name] — [what happened or what you learned about them].
```

**Example:** `Marcus — mentioned he's overwhelmed since the reorg. Wants to move to the platform team. His wife just had a baby.`

**Why it works:** Leading with a name triggers `person_note` classification and people extraction. Everything after the dash becomes searchable context about that person.

## 3. Insight Capture

```
Insight: [the thing you realized]. Triggered by: [what made you think of it].
```

**Example:** `Insight: Our onboarding flow assumes users already understand permissions. Triggered by: watching a new hire struggle for 20 minutes with role setup.`

**Why it works:** "Insight" triggers `idea` type. Including the trigger gives the embedding richer semantic content and helps you remember the original context months later.

## 4. Meeting Debrief

```
Meeting with [who] about [topic]. Key points: [the important stuff]. Action items: [what happens next].
```

**Example:** `Meeting with design team about the dashboard redesign. Key points: they want to cut three panels, keep the revenue chart, add a trend line. Action items: I send them the API spec by Thursday, they send revised mocks by Monday.`

**Why it works:** Hits multiple extraction targets at once — people, topics, action items, dates. Dense captures like this are the highest-value entries in your brain.

## 5. The AI Save

```
Saving from [AI tool]: [the key takeaway or output worth keeping].
```

**Example:** `Saving from Claude: Framework for evaluating vendor proposals — score on integration effort (40%), maintenance burden (30%), and switching cost (30%). Weight integration highest because that's where every past vendor has surprised us.`

**Why it works:** "Saving from [tool]" creates a natural `reference` classification. The content itself becomes searchable across every AI you use. This is how you stop losing good AI output to chat history graveyards.
