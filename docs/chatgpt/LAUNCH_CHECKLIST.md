# IdeaTub ChatGPT App Launch Checklist

## Infrastructure

-   [ ] Public HTTPS MCP endpoint: `https://ideatub.com/api/mcp`
-   [ ] SSE or streaming HTTP works reliably
-   [ ] Production uptime monitoring enabled
-   [ ] Structured logs and correlation IDs enabled
-   [ ] Rate limiting enabled
-   [ ] Audit logging enabled for write tools

## OAuth

-   [ ] OAuth flow works end-to-end in ChatGPT Developer Mode
-   [ ] Protected resource metadata exposed at well-known URL
-   [ ] Bearer tokens validated on every protected request
-   [ ] Token issuer / audience / expiry checks enforced
-   [ ] ChatGPT account-linking UI triggers correctly
-   [ ] Disconnect / expired-token flows tested

## Tool Metadata

-   [ ] `securitySchemes` declared per tool
-   [ ] Tool names are clear and distinct
-   [ ] Tool descriptions explicitly state when to use each tool
-   [ ] Input schemas are strict and minimal
-   [ ] Output payloads are concise and structured
-   [ ] Destructive tools require explicit intent

## Security

-   [ ] No query-param API keys used for ChatGPT production
-   [ ] No cross-user access possible server-side
-   [ ] Model inputs treated as untrusted
-   [ ] Prompt-injection risks reviewed
-   [ ] Write actions tested for accidental invocation

## Testing

-   [ ] Golden prompts created for read flows
-   [ ] Golden prompts created for write flows
-   [ ] OAuth failure cases tested
-   [ ] Invalid argument cases tested
-   [ ] Timeout / partial failure cases tested
-   [ ] Screenshots captured from ChatGPT for submission

## OpenAI Submission

-   [ ] Organization verification complete
-   [ ] Submitter has Owner role
-   [ ] App name finalized
-   [ ] Logo finalized
-   [ ] Description finalized
-   [ ] Company URL available
-   [ ] Privacy policy URL available
-   [ ] CSP defined for exact fetch domains
-   [ ] Test prompts and expected behavior documented
-   [ ] App submitted for review
