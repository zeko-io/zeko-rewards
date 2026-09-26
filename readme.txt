=== Zeko Rewards ===
Contributors: zeko
Tags: rewards, gamification, points, badges, leaderboard
Requires at least: 5.8
Tested up to: 7.1.2
Requires PHP: 7.4
Stable tag: 1.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Cross-module rewards engine for the Zeko ecosystem: activity points, achievement badges, tiers, streaks, leaderboards, and catalog redemptions — listening to every module and the theme.

== Description ==

Zeko Rewards is the gamification layer for the Zeko ecosystem. It listens to reward hooks emitted by every Zeko module and the theme, converting real engagement into points, badges, tiers, streaks, and leaderboard standing.

= Features =

* **Points Engine** — Awards points for meaningful actions across every module (`zeko_*` reward hooks), with a per-user/action/reference dedupe lock against double-awards
* **Badges** — Achievement badges for milestones
* **Tiers** — Bronze/Silver/Gold/Diamond-style tiers with rank change notifications
* **Streaks & Multipliers** — Daily streak tracking with bonus multipliers
* **Leaderboards** — Public leaderboard with REST API and shortcode
* **Catalog Redemption** — Redeem points into the Zeko Pay wallet or request catalog items with admin approve/complete/cancel workflow
* **Stock Management** — Atomic stock reservation so catalog items can never oversell
* **Referrals** — Referral rewards for bringing new members
* **Manual Adjustments** — Admin can grant or deduct points with reasons
* **CSV Export** — Export points, events, and redemptions
* **Notifications** — In-app reward notifications

= Shortcodes =

* `[zeko_rewards]` — Rewards hub with balance and history
* `[zeko_rewards_badges]` — Badge showcase
* `[zeko_rewards_catalog]` — Redemption catalog
* `[zeko_rewards_leaderboard]` — Public leaderboard

== Installation ==

1. Upload the `zeko-rewards` folder to `/wp-content/plugins/`
2. Activate the plugin through the 'Plugins' menu in WordPress
3. Activate Zeko Pay to enable wallet redemptions
4. Create pages and add the shortcodes above

== Frequently Asked Questions ==

= How do users earn points? =

Every Zeko module fires `zeko_*` hooks (for example course completion, job posting, accepted answers, contract milestones, date completion). Zeko Rewards listens to these hooks and awards configured point values.

= What happens on redemption? =

Catalog redemptions route through Zeko Pay's SDK, so points convert to wallet credit with a full ledger trail. Admins can also fulfil physical catalog requests manually.

== Dependencies ==

Zeko Rewards runs standalone as a cross-module points engine. Zeko Pay is required to convert points into wallet credit and is used by the catalog redemption flow.

== Privacy ==

Data stored includes each user's points balance, point/event history, badges, tiers, streaks, referrals, redemption requests, and in-app notifications. A deleted user's rows are purged so leaderboards stay clean.

== External Services ==

Zeko Rewards makes no outbound requests. When a redemption converts points to credit, the wallet transaction is handled locally through Zeko Pay's SDK.

== Uninstall ==

Deleting the plugin drops all Zeko Rewards tables, clears the expiry cron, removes only the pages it created, and deletes rewards-scoped user meta (`zeko_rewards_%`) and options.

== Troubleshooting ==

* Points missing? Confer the claim via `Tools > Erase Personal Data` scope or check the event log for dedupe — the same user/action/reference only awards once.
* Redemption stuck? Confirm Zeko Pay is active and the catalog item is in stock; stock is reserved atomically at request time.
* Leaderboard outdated? Recheck the per-user event index queries or cache settings.

== Screenshots ==

1. Rewards dashboard with points and leaderboard


== Changelog ==

= 1.1.0 =
* Dedupe race fix — per-user/action/reference MySQL lock around award check-and-insert
* Tier downgrade no longer fires a false "reached tier" notification
* Balance clamped at zero when spends outlive their earning events
* Full uninstall (tables, cron, options, pages, rewards-scoped usermeta) and delete-user purge
* New reward hooks: QA accepted answers, questions, answers, and AI conversations
* Notification retention cap and faster leaderboard queries (module index)

= 1.0.0 =
* Initial release
* Points engine, badges, tiers, streaks, leaderboards
* Catalog redemption to wallet via Zeko Pay
* Referrals, manual adjustments, CSV export, notifications

== Upgrade Notice ==

= 1.1.0 =
Reliability and cleanup fixes. Please upgrade as soon as possible.
