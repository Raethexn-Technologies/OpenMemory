## Summary

Explain what changed and why. Include any context a reviewer needs to understand the intent of this pull request.

## Type

Select one:

- [ ] Bug fix
- [ ] Feature
- [ ] Docs
- [ ] Refactor
- [ ] Connector (new or updated transport bridge)

## Testing

Describe how you tested this change. Specify whether you used mock mode, a live ICP canister, or a real connector (Discord, etc.). Include any commands you ran.

## Checklist

- [ ] Code follows the ESM module style used in the rest of the project.
- [ ] No secrets, tokens, or credentials are present in any committed file.
- [ ] Any new environment variables are documented in the relevant `.env.example`.
- [ ] Relevant documentation has been updated to reflect the change.
- [ ] All shell commands in the agent use the whitelist path and do not introduce `--force` or `--no-verify` flags.
