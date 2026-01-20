# Cursor Agent Configuration

This project uses directory-specific `.cursorrules` files to configure different AI agents for different purposes.

## Agent Types

### 🏛️ Architect Agent (`/decisions`)
**Purpose**: Document architectural decisions and design choices

**Focus**: The "why" behind technical choices

**Use when**:
- Making technology choices
- Designing system architecture
- Evaluating trade-offs
- Planning migrations

**Example**: "Create a decision record for choosing Laravel Queues over direct database writes"

---

### 📋 Specs Agent (`/specs`)
**Purpose**: Document technical specifications and requirements

**Focus**: The "what" that needs to be built

**Use when**:
- Defining features
- Writing requirements
- Creating acceptance criteria
- Documenting API contracts

**Example**: "Create a spec for the vehicle valuation API endpoint"

---

### 💻 Dev Agent (`/dev`)
**Purpose**: Document implementation details and patterns

**Focus**: The "how" it was actually built

**Use when**:
- Documenting implementation patterns
- Recording known issues
- Noting workarounds
- Sharing lessons learned

**Example**: "Document the implementation approach for the MOT reminder system"

---

### 🎧 Support Agent (`/support`)
**Purpose**: Document customer support investigations and issue resolutions

**Focus**: The "what happened, why, and how we fixed it"

**Use when**:
- Investigating customer-reported issues
- Troubleshooting production incidents
- Documenting root cause analysis
- Recording issue resolutions
- Tracking customer communication

**Example**: "Create an investigation record for the API timeout issue reported by customer"

## How to Use

### In Cursor Composer

When working in a specific directory, Cursor will automatically use the `.cursorrules` file in that directory:

1. **For architectural work**: Navigate to `/decisions` or mention "save to decisions"
2. **For specification work**: Navigate to `/specs` or mention "save to specs"
3. **For implementation notes**: Navigate to `/dev` or mention "save to dev"
4. **For support investigations**: Navigate to `/support` or mention "save to support"

### Explicit Agent Selection

You can also explicitly reference the agent type in your prompts:

- "As the architect agent, document the decision to use Redis for caching"
- "As the specs agent, create a spec for user authentication"
- "As the dev agent, document how we implemented the queue system"
- "As the support agent, investigate and document the API timeout issue"

### File Naming Conventions

- **Decisions**: `YYYY-MM-DD-decision-topic.md`
- **Specs**: `feature-name-spec.md` or `component-name-spec.md`
- **Dev Notes**: `feature-name-implementation.md` or `component-name-notes.md`
- **Support Investigations**: `YYYY-MM-DD-issue-description.md` or `CASE-XXXX-issue-description.md`

## Directory Structure

```
/
├── decisions/          # Architectural decisions
│   └── .cursorrules   # Architect agent config
├── specs/              # Technical specifications
│   └── .cursorrules   # Specs agent config
├── dev/                # Implementation notes
│   └── .cursorrules   # Dev agent config
└── support/            # Customer support investigations
    └── .cursorrules   # Support agent config
```

## Workflow

1. **Architect** creates a decision record in `/decisions`
2. **Specs** creates detailed specifications in `/specs` based on decisions
3. **Dev** implements and documents the actual implementation in `/dev`
4. **Support** investigates issues and documents resolutions in `/support`

Each agent can reference documents from other directories to maintain context and traceability.

**Support investigations** often reference:
- Implementation notes from `/dev` to understand how systems work
- Specs from `/specs` to verify expected behavior
- Decisions from `/decisions` to understand architectural choices

## Source Control & Team Sharing

### ✅ What Should Be Committed

**All agent configurations should be committed to source control** so the entire team benefits:

- ✅ `.cursorrules` files in `/decisions`, `/specs`, `/dev`, and `/support` directories
- ✅ `AGENT_CONFIGURATION.md` documentation
- ✅ README files in each directory
- ✅ Example files (as templates for the team)
- ✅ All decision records, specs, implementation notes, and support investigations

### ❌ What Should NOT Be Committed

- ❌ `.claude/settings.local.json` - Personal/local Claude settings (if using Claude)
- ❌ `.cursor/` directory - Cursor's local workspace settings
- ❌ Any personal preferences or local configurations

### Setting Up for New Team Members

When a new team member clones the repository:

1. The `.cursorrules` files will automatically configure Cursor agents
2. They can start using the agents immediately by navigating to the directories
3. They should read `AGENT_CONFIGURATION.md` to understand the workflow
4. Example files serve as templates for creating their own documentation

### Benefits of Committing Agent Configurations

- **Consistency**: Everyone uses the same agent behaviors and templates
- **Onboarding**: New team members get productive faster
- **Standards**: Ensures documentation follows consistent formats
- **Collaboration**: Team members can improve and refine agent configurations together
- **Knowledge Sharing**: Agent configurations encode team knowledge and best practices

### Updating Agent Configurations

If you improve an agent configuration:

1. Update the `.cursorrules` file in the appropriate directory
2. Update `AGENT_CONFIGURATION.md` if workflows change
3. Commit and push the changes
4. Team members will get the updates on their next pull

### Local Overrides

If you need personal overrides (not recommended for team consistency):

- Cursor respects directory-level `.cursorrules` files
- You can create local `.cursorrules.local` files (add to `.gitignore`)
- Consider discussing improvements with the team instead of local overrides