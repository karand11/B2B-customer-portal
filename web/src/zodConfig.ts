import { z } from 'zod';

// Zod 4 probes `new Function('')` to decide whether it may compile fast parsers. Under the
// enforced CSP (no 'unsafe-eval') that probe is blocked and reported as a
// securitypolicyviolation even though Zod catches the throw. `jitless` skips the probe
// (allowsEval returns false first). It is cached on first use, so this module must be the
// first import of main.tsx, before any schema is created.
z.config({ jitless: true });
