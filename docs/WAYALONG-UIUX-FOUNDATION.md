# WayAlong navigation and dashboard foundation — Phase 1

## Navigation hierarchy

1. **My WayAlong** — a user's primary landing screen; task-first, filtered to authorized organizations, facilities, and role.
2. **Main navigation** — stable work areas: Clients & Care, Insurance & Billing, Organization, and Administration. Hide links when authorization is absent; hiding a link never changes authorization.
3. **Within-record navigation** — Overview first, then focused left-side section navigation for multi-section workflows, as established in Client Billing.

## My Clients first

- When the current organization has an authorized clinician facility assignment, put **My Clients** at the top of the personal dashboard.
- Use actual `client_staff_assignments` and active `client_enrollments`. Do not substitute every client accessible in a facility for a user's assigned caseload.
- Show concise name/facility/status, a quick contact-section action, and a full-record action.
- Only render permitted clinical data. Switching facility must pass through the existing authorized facility-switch endpoint.
- Future: reusable medical / behavioral / miscellaneous alert infrastructure with severity and details. Do not infer medical or behavioral alerts from free-text notes.
- Future: searching, sorting, paging and a compact client summary; first slice caps the dashboard at 100.

## Finance workspaces

- Billing and claims navigation belongs to appropriately authorized financial roles; do not grant clinicians finance privileges through dashboard shortcuts.
- Finance overview should prioritize outstanding amounts, claims needing review, rejected coverage, and reconciliation problems.
- Existing organization Owner/Admin finance access remains as implemented; a dedicated finance permission/role should be a subsequent security design step.

## Reusable interaction conventions

- Overview-first left-side navigation for complex records; preserve selected section on refresh or return.
- Red = high-priority notice; yellow = warning; gray = follow-up. Show highest-priority icon in lists, details in the record.
- Prefer application dialogs to native prompt/confirm for destructive financial actions.
- Dashboard cards are navigation and information surfaces only, not sources of permission.

## Scope of first implementation

The first package implements the authorized **My Clients** section on My WayAlong. It does not create clinical alert data, add new roles, or reorganize existing finance pages. These will be designed incrementally after testing this slice.
