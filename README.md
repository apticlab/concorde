# Concorde

## Versioning and releases

Published versions use Git tags such as `v1.2.13`. Composer selects a version
according to the consuming application's version constraint and lock file; a
commit on `master` alone does not create a tagged release. The `bump-release.sh`
script accepts `major`, `minor` or `patch`, creates a tag, pushes the current
branch and pushes all local tags. Review the commits and tags before running it.

## Upgrading to version 2

The resource persistence API has changed. Applications must migrate their resource
models, controllers, requests and policies together before upgrading. In particular,
controllers now expose concrete write actions that call `storeResource()` and
`updateResource()`, while models explicitly declare writable attributes and managed
relations. Review the behavior of every resource and its authorization rules before
deploying the upgrade.

## Resource persistence

Resource models extend `Aptic\Concorde\Models\ResourceModel`. Authenticatable models
keep their Laravel parent and use `HasResourceRelations`. Eloquent's lifecycle,
events, timestamps and soft deletes are unchanged.

Each model declares its normal `$fillable` attributes and only the relationships
that graph persistence is allowed to handle:

```php
protected array $resourceRelations = [
    'exercises' => 'owned',
    'difficulty' => 'reference',
];
```

`owned` records are created or updated through the parent's relationship. A supplied
existing child ID must belong to that parent. Omitting a collection preserves it;
submitting `[]` removes it with normal model delete events. `reference` selects an
existing record through its local `visibleTo($user)` scope and never edits its
submitted attributes. Undeclared relationships are not written. New records are
populated before their first insert; temporary `NEW_` IDs are local to one operation.

Concrete controllers accept their own Laravel Form Requests and pass `validated()`
data to the base controller's protected `storeResource()` / `updateResource()`
handlers. Requests contain native Laravel validation rules, including nested fields.
The base controller uses Laravel Gate/Policies for authorization and local Eloquent
visibility scopes for queries. Field and role rules live with the application;
Concorde has no registry of application model classes or authorization cases.

Pre-hooks receive validated input and explicitly assign server-controlled values.
Post-hooks return the persisted model by default. Existing specialized workflows can
keep their own controller actions and use the same Form Requests and Policies.

`ResourceWriter` handles BelongsTo, HasOne, HasMany and reference-only BelongsToMany
relationships. It batches existing-child reads, avoids unnecessary saves and does
not load default display relations during persistence. Unsupported relationship types
require an explicit implementation. Cyclic temporary references and duplicate child
IDs fail validation.

CSV parsing and images remain small, separate helpers. CSV import is disabled unless
the model overrides `resourceImportColumns()` and its controller supplies `importRules()`
using the create request's rules. Row errors roll back the whole import and report
row numbers. New image files are removed on rollback; previous files are removed only
after commit. The byte limit defaults to 10 MiB (`concorde.maxImageBytes`).

Normal list pagination and `no_paginate` array responses retain their original
behavior. There is no lookup protocol or automatic 1000-row limit.

## Changes in this refactor

- Cards: declarations beside the card/exercise/series models; relationship ownership
  checks and batched reads retained, without a central write policy.
- Accounts: Laravel requests validate user/profile input; hooks prepare passwords and
  role/ownership fields before insertion. User keeps Authenticatable inheritance.
- Other resources: native scopes and explicitly registered Policies replace controller
  role filters. Courses and statistics retain their specialized operations.
- Removed: the central model registry, custom write-policy resolver, lookup integration
  and separate ordering service. Ordering is contained in the base controller.

This refactor intentionally makes legacy-only assignments of timestamps and other
excluded fields explicit with `forceFill()` on trusted arrays. It never disables
mass-assignment protection globally and requires no database migration or import run.
