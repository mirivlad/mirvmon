# Four-component hotfix releases

MirvMon accepts release tags in the normal `vX.Y.Z` form and emergency hotfix
releases in the `vX.Y.Z.N` form.

For a hotfix such as `v0.4.15.2`, the release workflow publishes the same native
amd64/arm64 multi-arch image under these aliases:

- `0.4.15.2`
- `0.4.15`
- `0.4`
- `0`
- `latest`
- the normal `sha-*` tag

The application and bundled agents still receive the full Git tag through
`APP_VERSION`, so the UI reports the exact hotfix build that produced the image.
