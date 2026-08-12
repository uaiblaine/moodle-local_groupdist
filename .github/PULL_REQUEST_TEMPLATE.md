## Summary

Describe the goal of this change.

## Change type

- [ ] fix
- [ ] feature
- [ ] refactor
- [ ] tests
- [ ] docs
- [ ] ci
- [ ] security

## Risk areas

- [ ] capabilities / permissions
- [ ] database schema / upgrade path
- [ ] web services
- [ ] privacy provider
- [ ] UI / templates / JS
- [ ] settings / configuration
- [ ] install / upgrade

## Validation

- [ ] `mdl ci <repo>` passes locally (or targeted `--only` runs for the touched gates)
- [ ] Relevant PHPUnit run (`mdl phpunit <stack> <component>`)
- [ ] Manual validation of the changed flow on a local stack
- [ ] `lang/en` and `lang/pt_br` in sync and alphabetically sorted
- [ ] `version.php` bumped if JS, `db/services.php` or DB schema changed
- [ ] `amd/build` rebuilt and committed with any `amd/src` change
- [ ] `CHANGELOG.md` updated

## Notes

Include any compatibility, migration or rollout considerations.
