# WayAlong — Client Administration and Treatment boundaries (design baseline)

## Central identity, separate workspaces
One organization-owned client identity with facility enrollments. **Client Administration** covers demographic details, guardians, enrollment, administrative documents, insurance and billing. **Client Treatment** covers alerts, End of Shift Notes, care instructions, medical data, treatment plans and therapy documentation. Never duplicate the client record.

## Authorization requirements for the next implementation slice
- Organization and explicit facility membership are mandatory gates, but not sufficient on their own for sensitive clinical access.
- Clinical client assignment controls default caseload visibility; for role-specific emergency coverage or shift coverage, design explicit grants and audit the reason for access.
- Introduce independent *read / create / update / resolve / history* abilities for alerts and *read / write / history* for End of Shift Notes. Separate medical, treatment-plan and therapy-session privileges; restricted psychotherapy notes require a stricter category and authorization policy.
- Floor staff: may need assigned-client alerts, instructions and shift notes, not therapy-session notes.
- Therapist: clinical access appropriate to assignments and authorizations; do not automatically grant finance access.
- Organization owners, billing staff and administrators: do not inherit clinical read access solely from their administrative role.
- Enforce permissions in routes/controllers/services and query scoping, not merely hidden UI links. Add negative tests for unauthorized roles, facilities, organizations and assignments.
- Quick View: only load/render data that a specific authenticated user may see. Never send prohibited medical/therapy data in Inertia props.
- Recording alert changes: append protected event records with actor/time, with mandatory resolution reason and nondeletable audit trail. Old pre-history alerts may not have a creation event: this feature does not fabricate missing events.

## Status of this initial delivery
Implemented: append-only creation/resolution events for new alerts, required resolution explanation, encrypted reason storage and authorized Quick View history (existing assigned clinician permission model). **Not yet implemented:** independent floor staff/therapist/nurse grants, separate treatment routes/pages, medical/therapy documentation policies, update/reopen workflow. Existing alert-management eligibility is unchanged until those policies are tested and rolled out.

## Next build
Create explicit treatment abilities and client treatment workspace before adding End of Shift Notes. Review minimum-necessary access, operational emergency access and applicable clinical record privacy obligations with the deploying organization.
