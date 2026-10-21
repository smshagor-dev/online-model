# Deploy the OpenMindAI frontend to Vercel

The Git repository contains two distinct applications: the Laravel backend at
the repository root and the Next.js application in **frontend/**. Deploy only
the `frontend` directory to Vercel. Laravel remains hosted separately at
https://cloud.openmindai.org.

## Vercel project settings

| Setting | Value |
|---|---|
| Repository | `smshagor-dev/online-model` |
| Framework Preset | Next.js |
| Root Directory | `frontend` |
| Install Command | `npm ci` |
| Build Command | `npm run typecheck && npm run build` |
| Output Directory | Next.js default (leave override empty) |
| Node.js Version | 22.x |
| Functions | Node.js with Fluid Compute |
| Function max duration | 300 seconds (API route) |
| Production Branch | `main` |

The `frontend/vercel.json` file stores the install/build settings. The
`frontend/src/app/api/backend/[...path]/route.ts` handler sets its own
`maxDuration` to 300 seconds, compatible with the Hobby plan's five-minute
Fluid Compute maximum. Backend and model request timeouts may still be shorter.
Vercel's function duration is **not** a background-video queue: long-running
provider requests should eventually move to a durable Laravel worker.

## Environment variables

Set in Vercel Project > Settings > Environment Variables:

| Variable | Value | Scope |
|---|---|---|
| `LARAVEL_API_URL` | `https://cloud.openmindai.org/api/v1` | Production + Preview |
| `CHAT_PROVIDER` | `nvidia` | Production + Preview |
| `NVIDIA_API_KEY` | Secret provider key, encrypted/sensitive and **server-side only** | Production + Preview |
| `FRONTEND_CHAT_BRIDGE_KEY` | The **same existing 32+ character secret** in Laravel's `.env` | Production + Preview |
| `NVIDIA_ROUTER_CLASSIFIER` | `true` (optional) | Production + Preview |

Set `NVIDIA_API_KEY` as a Vercel **server-side secret**. Never prefix it with
`NEXT_PUBLIC_` or expose it in browser code. Do not add Laravel's `APP_KEY` to
Vercel. Laravel's separate Developer API routes may use their own server key. `FRONTEND_URL` can be omitted
from Vercel: Vercel's authenticated API route validates each deployment against
its own request origin, so Preview and Production logins both work.
`FRONTEND_URL` is still used for locally self-hosted Next.js outside Vercel.

Keep Preview deployments protected as appropriate. Their server-side gateway
uses the same authenticated Laravel bridge, and deployment URLs alone are not
authorizations to invoke provider models.

## Deployment workflow

1. Link the Git repository to a Vercel project using **Root Directory =
   frontend**. Never set the root to the Laravel repository root.
2. Add the NVIDIA API key privately to Vercel and the existing shared bridge
   secret (identical on both servers). Do not paste either into a public issue.
3. Merge code into main. Vercel builds the frontend on Git push; installing
   dependencies uses the pinned `frontend/package-lock.json`.
4. Verify that the Vercel deployment is Ready and the site opens.
5. Visit the live site, sign in, load models and generate an answer. The browser
   should call only `/api/backend/*`. The Next.js server calls NVIDIA directly
   for hosted inference and calls Laravel for authentication, user quotas,
   history and privately stored output.

## Troubleshooting

- **Missing dependency or wrong framework:** Check Root Directory is
  `frontend`, Node 22.x, and framework is Next.js. Do not use a static export.
- **403 Invalid request origin on Preview:** Deploy the latest frontend API
  handler; it understands Vercel-generated Preview URLs. Outside Vercel, set
  `FRONTEND_URL` to the actual frontend origin.
- **503 Hosted chat is not configured:** Check `NVIDIA_API_KEY` on the
  Vercel Next.js server and matching 32+ character `FRONTEND_CHAT_BRIDGE_KEY`
  on Next.js and Laravel. Redeploy after changing environment variables.
- **Model disabled:** Check model availability and `api_ready` in Laravel's
  administrative model catalog.
- **FUNCTION_INVOCATION_TIMEOUT:** Vercel Hobby Fluid Compute supports up to
  300 seconds. Long research jobs and media generation need durable backend
  processing if they exceed request limits.
- **Provider API unreachable:** Check TLS/DNS/outbound HTTPS from the
  Vercel Next.js server to NVIDIA. Check Laravel separately for authenticated
  persistence and private media delivery.

This configuration establishes a reproducible build; actual authenticated
provider connectivity and media file delivery require post-deployment tests.
