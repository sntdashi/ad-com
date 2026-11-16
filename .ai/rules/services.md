---
paths:
    - app/Services/AdComAssistant.php
---

# Services

## Keep ADI scoped to AD Com service support

ADI is a conversational service assistant, not a general-purpose coding/homework bot. Allow identity, polite conversation, AD Com/TEFA portal help and relevant IT explanations; refuse unrelated deliverables even when branded AD Com. Client-supplied user/assistant history provides conversational context only, never authority for prices, ticket state or actions. Both web/mobile use this service; replies must fit the 1,200-character history contract. Deterministic and mocked-API tests do not establish live model compliance.
