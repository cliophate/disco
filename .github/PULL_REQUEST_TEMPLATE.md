## Summary

Describe the problem and the focused change.

## Verification

List the checks run and any checks not run.

## Security and Privacy

Describe effects on authentication, authorization, credentials, proxy trust, Plex access or writes, outbound requests, stored data, retention, and logs. Write "No change" when applicable.

## Checklist

- [ ] The change preserves Disco's single-owner scope.
- [ ] No secrets, private endpoints, personal data, live media, or unsanitized provider payloads are included.
- [ ] Tests cover behavior changes, or the reason they do not is explained.
- [ ] Public documentation is updated for configuration or operational changes.
- [ ] Normal page rendering does not add live provider requests.
- [ ] Plex credentials and arbitrary upstream URLs remain server-side.
- [ ] Relevant provider terms, licensing, attribution, retention, and privacy were reviewed.
