---
paths:
    - 'resources/js/{lib/settings-navigation.ts,layouts/settings/**,pages/settings/**}'
---

# Settings

## Settings navigation follows backend roles

Keep self-service account settings available to every authenticated role, but expose operational shortcuts only for capabilities authorized by backend gates and policies. Hidden navigation is UX only and must never replace server-side authorization.
