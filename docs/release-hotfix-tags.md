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

## Native-agent self-update compatibility

Native agents starting with `v0.4.15.3` accept four-component update targets such
as `v0.4.17.1`. Older native updaters validate only the normal three-component
`vX.Y.Z` form and must not be sent a four-component target directly.

The MirvMon server therefore treats `v0.4.15.3` as the minimum updater version for
a four-component target. If the currently bundled agent is a hotfix release and a
server still reports an older updater, remote update is withheld instead of
creating a command that the agent cannot accept. Normal three-component targets
remain available to those older agents and can be used as a bridge before the
hotfix deployment.

Operationally, before deploying a four-component MirvMon release it is preferable
to bring any reachable agents older than `v0.4.15.3` to the current normal
three-component release first. The server-side guard remains the safety net for
hosts that were offline or otherwise missed that bridge update.
