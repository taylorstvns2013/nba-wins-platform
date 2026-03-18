# League Hub Feature — File Reference & Rollout Plan

## New Files Created

### 1. `core/LeagueManager.php`
**Purpose:** Core business logic class for all league operations.

**What it does:**
- `createLeague()` — Creates a new league, assigns the creator as commissioner and first participant, generates a unique 6-character alphanumeric PIN (e.g. `BHY2YK`)
- `joinLeague()` — Validates a PIN code, checks capacity and duplicate membership, adds user to the league
- `getCommissionerLeagues()` — Returns leagues where a user is the commissioner with participant counts
- `getLeagueMembers()` — Returns full member list for a league with commissioner flag
- `getUserLeaguesWithDetails()` — Returns all leagues a user belongs to with participant counts and commissioner status
- `updateDraftDate()` — Allows commissioners to set or change the draft date
- `generateUniquePIN()` — Generates random 6-char PINs excluding confusing characters (I, O, 0, 1)

**Dependencies:** Requires `$pdo` (PDO database connection)

---

### 2. `auth/register_v2.php`
**Purpose:** New registration page — account creation is decoupled from league joining.

**What it does:**
- Collects: display name, username, email, password, security question/answer
- Does NOT require a league PIN at registration time
- After successful registration, auto-logs in and redirects to League Hub
- Uses the same password hashing and security question system as the original

**Replaces:** `auth/register.php` (original stays untouched for now)

---

### 3. `auth/login_v2.php`
**Purpose:** New login page that handles users with no league membership.

**What it does:**
- Same login UI as original
- Fixes the `current_league_id` bug — properly sends `NULL` instead of empty string when user has no leagues
- Smart redirect: users with leagues go to dashboard, users without leagues go to League Hub
- Register link points to `register_v2.php`

**Replaces:** `auth/login.php` (original stays untouched for now)

---

### 4. `auth/league_hub.php`
**Purpose:** Central hub for league management — join, create, and manage leagues.

**What it does:**
- Three tabbed sections: **My Leagues** / **Join League** / **Create League**
- My Leagues tab: shows all leagues with member count, draft date, commissioner/member badge
- Commissioner view: shows PIN code (with copy button), member list with open slots, inline draft date editor
- Join tab: PIN input field, validates and adds user
- Create tab: league name, size (5 or 6), optional draft date/time
- Updates session `current_league_id` when a user with no league joins or creates one

**Dependencies:** `LeagueManager.php`, `UserAuthentication.php`

---

### 5. `auth/draft_lobby.php`
**Purpose:** Pre-draft waiting room with countdown timer.

**What it does (based on draft state):**
- **Draft date in future:** Live countdown timer (days/hours/minutes/seconds) with color coding — orange under 24hrs, red under 1hr. Auto-reloads when countdown hits zero.
- **Draft date passed, not started:** "Draft Day Has Arrived" banner, commissioner gets button to open draft admin
- **Draft in progress:** "Draft In Progress" banner with link to draft room
- **Draft completed:** Trophy banner with link to dashboard
- **No draft date set:** Prompts commissioner to set one via League Hub
- All states show participant roster with open slots and PIN share for commissioners

**Dependencies:** `LeagueManager.php`, `UserAuthentication.php`

---

### 6. `api/league_api.php`
**Purpose:** JSON API endpoints for league operations (for future AJAX/frontend use).

**Endpoints:**
- `POST ?action=create_league` — Create a new league
- `POST ?action=join_league` — Join by PIN
- `GET ?action=my_leagues` — List user's leagues
- `GET ?action=league_members&league_id=X` — Get league members
- `POST ?action=update_draft_date` — Update draft date (commissioner only)

**Dependencies:** `LeagueManager.php`, `UserAuthentication.php`

---

### 7. `config/migration_league_hub.sql`
**Purpose:** Database migration to add the `draft_date` column.

**SQL:**
```sql
ALTER TABLE leagues ADD COLUMN draft_date DATETIME NULL DEFAULT NULL AFTER draft_completed;
```

This is the only schema change needed. All other required columns (`commissioner_user_id`, `pin_code`, `user_limit`, `league_participants` table) already exist.

---

## Rollout Plan

### Phase 1: Database Migration (do this first)
1. Run the SQL migration on the production database:
   ```sql
   ALTER TABLE leagues ADD COLUMN draft_date DATETIME NULL DEFAULT NULL AFTER draft_completed;
   ```
2. Verify: `DESCRIBE leagues;` — confirm `draft_date` column exists

### Phase 2: Deploy New Files
1. Upload all 6 new PHP files to the server in their respective directories
2. No existing files are modified — everything runs side by side
3. Verify file permissions match existing files

### Phase 3: Test on Production
1. Visit `auth/login_v2.php` — login with an existing account, confirm redirect to dashboard
2. Visit `auth/register_v2.php` — create a test account, confirm redirect to League Hub
3. Visit `auth/league_hub.php` — test creating a league, verify PIN generation
4. From a second account, join the league using the PIN
5. Visit `auth/draft_lobby.php` — verify countdown displays correctly
6. Test edge cases: full league join attempt, duplicate join, invalid PIN

### Phase 4: Integration (when ready to go live)
These are the changes needed to replace the old flow with the new one:

#### 4a. Fix the original login (optional but recommended)
In `core/UserAuthentication.php`, line 160, change:
```php
$defaultLeague = $stmt->fetchColumn();
```
to:
```php
$result = $stmt->fetchColumn();
$defaultLeague = ($result !== false) ? $result : null;
```
This fixes the root cause of the `current_league_id` empty string bug for the original login page too.

#### 4b. Swap registration page
In `auth/login.php`, change the register link:
```html
<!-- Old -->
<a href="register.php">Register here</a>
<!-- New -->
<a href="register_v2.php">Register here</a>
```
Or rename `register_v2.php` to `register.php` (after backing up the original).

#### 4c. Swap login page
Either:
- Rename `login_v2.php` to `login.php` (backup original first)
- Or update all references that point to `login.php`

#### 4d. Add League Hub link to main navigation
Add a link to `auth/league_hub.php` in your dashboard navigation so users can manage leagues after login. Example placement in the LeagueSwitcher or main nav.

#### 4e. Add Draft Lobby link
Add a link to `auth/draft_lobby.php` in the dashboard or league view so users can see their draft countdown. Could be a button next to the league name or in a sidebar.

#### 4f. Update index.php redirect
In `index.php`, add a check for users with no league:
```php
if (!isset($_SESSION['current_league_id']) || empty($_SESSION['current_league_id'])) {
    header('Location: /nba-wins-platform/auth/league_hub.php');
    exit;
}
```

### Phase 5: Cleanup (end of season / next season)
1. Remove old `auth/register.php` once `register_v2.php` is confirmed stable
2. Remove old `auth/login.php` once `login_v2.php` is confirmed stable
3. Update any hardcoded references to old login/register paths
4. Consider migrating existing league PINs from `PIN001` format to the new random format (optional, both work)

---

## File Location Summary

| File | Path | New? |
|------|------|------|
| LeagueManager | `core/LeagueManager.php` | New |
| Register V2 | `auth/register_v2.php` | New |
| Login V2 | `auth/login_v2.php` | New |
| League Hub | `auth/league_hub.php` | New |
| Draft Lobby | `auth/draft_lobby.php` | New |
| League API | `api/league_api.php` | New |
| DB Migration | `config/migration_league_hub.sql` | New |

**No existing files were modified.** All 7 files are additive and run alongside the current system.

## Prevention Strategies

Good thinking. Here are the common strategies, roughly in order of what would make sense for your platform:

**Low effort, high impact:**
- **Rate limiting** — Cap league creation per user (e.g. max 2-3 leagues per account). You already have `commissioner_user_id` so a simple COUNT check in `createLeague()` would handle this.
- **Account limits** — One account per email. You already enforce this. Could also add a cooldown on registration (e.g. 1 account per IP per hour) to prevent spam signups.
- **Commissioner-only cost** — Creating a league is free, but it requires effort (fill out details, set draft date). This natural friction already filters out most casual abuse.

**Medium effort:**
- **Email verification** — Require users to verify their email before they can create a league or join one. This is the single biggest deterrent to spam accounts. You'd send a code or link after registration, and mark the account as `verified` before unlocking league features. Your current system skips email (using security questions instead), so this would be a bigger change but very effective.
- **League expiration** — Auto-deactivate leagues that never fill up or never draft after 30-60 days. A simple cron job checking `created_at` and `draft_completed` would clean up abandoned leagues.
- **Invite-only period** — New leagues start in a "pending" state and only become fully active once they have a minimum number of members (e.g. 3 of 5). Prevents someone from creating 20 empty leagues.

**What most platforms do:**
- **Discord/Slack approach** — Anyone can create a server/workspace, but there's email verification + rate limits. Abandoned ones just die naturally.
- **Fantasy sports apps (ESPN, Yahoo, Sleeper)** — Email verification required, one click league creation but leagues auto-delete if the draft never happens before the season starts. Commissioners can delete their own leagues.

**My recommendation for your scale:** Start with just two things next season:
1. A league creation cap per user (3 max)
2. A cleanup cron that deactivates leagues with no draft after 60 days

That keeps it simple and handles 95% of the problem. Email verification is the gold standard but it's a bigger lift given your current security-question-only approach. You could always add it later if abuse becomes an issue.
