# Canyon Framework Upgrade Plan
**Source:** https://github.com/SunilSherkhane/Canyon-Framework  
**Local Path:** `/home/sunilsherkhane/htdocs/canyon`  
**Current Branch:** `master`  
**Current Status:** `master` is in sync with `origin/master` (0 commits ahead/behind)  
**Local Customizations:** `public/install.php` (security hardening — var_export escaping)

---

## Executive Summary

The Canyon Framework repository is currently **up-to-date** with `origin/master`. There is **1 pending local modification** (`public/install.php`) that must be preserved during any upgrade. This plan outlines a **zero-downtime, git-based upgrade workflow** that pulls upstream changes while preserving local customizations, with minimal file modifications.

---

## Pre-Upgrade Audit

### Current State
| Metric | Value |
|--------|-------|
| Remote URL | `https://github.com/SunilSherkhane/Canyon-Framework.git` |
| Active branch | `master` |
| Commits ahead of remote | **0** |
| Commits behind remote | **0** |
| Uncommitted changes | **1 file** (`public/install.php`) |
| Tagged releases | **None** (upgrade via branch HEAD) |
| Related apps | `minervaone` (uses Canyon via symlink `minerone`) |

### Local Customizations to Preserve
1. **`public/install.php`** — Security hardening: replaced `sanitizeDefine()` with `canyon_safe_str()` using `var_export()` to prevent RCE via config injection during installation.

---

## Upgrade Strategy: Git-Based Pull with Customization Preservation

### Phase 1: Preparation
1. **Create a backup branch** from current `master`:
   ```bash
   git checkout -b backup/pre-upgrade-$(date +%Y%m%d)
   git push origin backup/pre-upgrade-$(date +%Y%m%d)
   ```
2. **Stash uncommitted changes**:
   ```bash
   git stash push -m "preserve-install-php-security-fix"
   ```
3. **Verify clean working tree**:
   ```bash
   git status
   ```

### Phase 2: Pull Upstream Updates
4. **Fetch latest from remote**:
   ```bash
   git fetch origin
   ```
5. **Check for new commits**:
   ```bash
   git rev-list master..origin/master --count
   ```
   - If `0`: No new commits. Skip to Phase 4.
   - If `>0`: Proceed to merge.

6. **Merge remote master into local master**:
   ```bash
   git merge origin/master --no-commit --no-ff
   ```
   - If conflicts arise, resolve **only** in files that are NOT local customizations.
   - For `public/install.php`: **keep local version** (ours).

7. **Run dependency check**:
   ```bash
   composer install --no-dev --optimize-autoloader
   ```
   - If `composer` is not installed, skip this step.

### Phase 3: Reapply Local Customizations
8. **Apply stashed security fix**:
   ```bash
   git stash pop
   ```
   - If conflicts occur in `public/install.php`, accept **local changes** (the security fix).

9. **Verify customizations**:
   ```bash
   git diff public/install.php
   ```
   - Ensure `canyon_safe_str()` and `var_export()` escaping are present.

### Phase 4: Validation & Testing
10. **Syntax check all PHP files**:
    ```bash
    find . -name "*.php" -not -path "./vendor/*" -exec php -l {} \; 2>&1 | grep -v "No syntax errors"
    ```
    - All files must pass.

11. **Run framework CLI diagnostics**:
    ```bash
    php canyon info
    php canyon config
    ```
    - Confirm framework version and config load correctly.

12. **Test application boot**:
    ```bash
    php -r "require 'bootstrap.php'; echo 'OK';"
    ```
    - Or serve via `php canyon serve 8083` and verify homepage loads.

13. **Check related apps** (if `minervaone` is active):
    ```bash
    cd /home/sunilsherkhane/htdocs/minervaone
    # Verify symlink integrity
    ls -la public/minerone 2>/dev/null || echo "No symlink"
    ```
    - If `minerone` symlink points to `minervaone/public`, verify it still resolves.

### Phase 5: Commit & Finalize
14. **Commit merged changes** (if any):
    ```bash
    git add -A
    git commit -m "chore: merge upstream Canyon Framework updates"
    ```
15. **Push to remote**:
    ```bash
    git push origin master
    ```
16. **Tag the upgrade** (optional but recommended):
    ```bash
    git tag -a v0.4.1-upgrade-$(date +%Y%m%d) -m "Upgrade to latest upstream"
    git push origin v0.4.1-upgrade-$(date +%Y%m%d)
    ```

---

## Conflict Resolution Matrix

| File / Path | Conflict Source | Resolution | Reason |
|-------------|----------------|------------|--------|
| `public/install.php` | Local security fix | **Keep local** | Prevents RCE via config injection |
| `config.php` | Upstream changes | **Keep upstream** unless it breaks local `.env` | Framework defaults should be respected |
| `app/Controllers/*` | Upstream changes | **Keep upstream** | Core MVC — local logic should extend, not override |
| `resources/views/*` | Upstream changes | **Keep upstream** | Theme/layout updates should flow from framework |
| `vendor/*` | Composer lock | Run `composer install` | Dependency resolution is automatic |
| `.env` | Upstream changes | **Keep local** | Environment-specific values must not be overwritten |

---

## Rollback Plan

If the upgrade breaks the application:

1. **Restore backup branch**:
   ```bash
   git checkout backup/pre-upgrade-$(date +%Y%m%d)
   ```
2. **Force push to master** (if already pushed):
   ```bash
   git checkout master
   git reset --hard backup/pre-upgrade-$(date +%Y%m%d)
   git push origin master --force
   ```
3. **Verify application**:
   ```bash
   php canyon info
   ```

---

## Delegation Matrix

| Task | Owner | Priority | Dependencies |
|------|-------|----------|--------------|
| Create backup branch | **Vance** (GitOps) | P0 | None |
| Stash local customizations | **Vance** (GitOps) | P0 | None |
| Fetch & merge upstream | **Vance** (GitOps) | P0 | Backup complete |
| Composer dependency update | **Rex** (DevOps) | P1 | Merge complete |
| PHP syntax validation | **Soren** (QA) | P1 | Merge complete |
| Application smoke test | **Soren** (QA) | P1 | Syntax pass |
| MinervaOne compatibility check | **Maya** (Product) | P2 | Smoke test pass |
| Commit & tag upgrade | **Vance** (GitOps) | P0 | All tests pass |
| Notify Sherry of completion | **Jagga** (Orchestrator) | P0 | Final commit |

---

## Immediate Action Steps

1. **Create backup branch** now to snapshot current state.
2. **Stash** the `public/install.php` security fix.
3. **Fetch** and **merge** `origin/master` into local `master`.
4. **Run** `composer install` to sync dependencies.
5. **Pop stash** and reapply the install.php fix.
6. **Validate** PHP syntax and framework boot.
7. **Commit**, **push**, and **tag** the upgrade.
8. **Report** status to Sherry.

---

## Risk Assessment

| Risk | Likelihood | Impact | Mitigation |
|------|------------|--------|------------|
| Merge conflicts in core files | Medium | Medium | Conflict matrix defined; prioritize upstream |
| Composer dependency breaks | Low | High | Test in isolated environment first |
| Local app (minervaone) incompatibility | Medium | High | Check symlink and routes post-upgrade |
| Uncommitted customizations lost | Low | High | Stash + backup branch + conflict matrix |

---

## Notes

- **No tags exist** on the remote repository; upgrades track `origin/master` HEAD.
- The `main` branch contains only initial backup commits and is not used for active development.
- The `minerone` symlink points to `minervaone/public`; ensure it remains intact after upgrade.
- Framework CLI version is `v0.4.0`; post-upgrade version will reflect latest upstream HEAD.
