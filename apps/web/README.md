# Web application

The browser application uses React, TypeScript and Vite as accepted in ADR-0002.

## Commands

```bash
npm install
npm run dev
npm run format:check
npm run lint
npm run typecheck
npm test
npm run test:visual
npm run build
```

The application health view is available at `/health`.

`npm run test:visual` compares the shared production UI primitives in Light and
Dark themes at desktop, tablet and mobile viewport sizes. Review intentional
changes before regenerating baselines with `npm run test:visual:update`.
