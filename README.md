<!--
  - SPDX-FileCopyrightText: 2019 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
# 👤🗑 Account retention (formerly User retention)

[![REUSE status](https://api.reuse.software/badge/github.com/nextcloud/user_retention)](https://api.reuse.software/info/github.com/nextcloud/user_retention)

Accounts are disabled or deleted when they did not log in within the given number of days. In case of deletion, this will also delete all files and other data associated with the account.

* 🛂 Different retention possible for normal accounts and accounts of the [guests app](https://apps.nextcloud.com/apps/guests)
* ⛔ Exclude accounts based on group memberships (default: admin group)
* 🔑 Exclude accounts that never logged in (default: enabled)

As an administrator, click on your user icon, then navigate to 
**Administration Settings** -> **Basic settings** -> **Account retention**.

![Screenshot of the admin settings](docs/screenshot.png)

## Configuration options

There are a few configuration options to be aware of, which can be set and 
retrieved via the occ command.

For example, to disable users after 10 days of inactivity:
 
```shell
occ config:app:set user_retention user_days_disable --type=integer --value=10
```

| Configuration key          | Type    | Default value      | Description                                                                                                 |
|----------------------------|---------|--------------------|-------------------------------------------------------------------------------------------------------------|
| `user_days_disable`        | integer | `0`                | If greater than `0`, disables users who have been inactive for the specified number of days.                |
| `user_days`                | integer | `0`                | If greater than `0`, deletes users who have been inactive for the specified number of days.                 |
| `guest_days_disable`       | integer | `0`                | If greater than `0`, disables guest users who have been inactive for the specified number of days.          |
| `guest_days`               | integer | `0`                | If greater than `0`, deletes guest users who have been inactive for the specified number of days.           |
| `reminder_days`            | string  | `''` (empty value) | Comma-separated list of days before which reminder emails are sent about upcoming deactivation or deletion. |
| `keep_users_without_login` | boolean | `yes`              | When set to `yes`, preserves users who have never logged in.                                                |
| `excluded_groups`          | array   | `["admin"]`        | List of groups whose members are excluded from deactivation and deletion policies.                          |

## Further examples

### 🔐 Accounts that never logged in

By default, accounts that have never logged in at all, will be spared from removal.

In this case the number of days will start counting from the day on which the account has been seen for the first time by the app (first run of the background job after the account was created).

#### Example

Retention set to 30 days:

| Account created | Account logged in | `keep_users_without_login` | Cleaned up after |
|-----------------|-------------------|----------------------------|------------------|
| 7th June        | 14th June         | yes/default                | 14th July        |
| 7th June        | 14th June         | no                         | 14th July        |
| 7th June        | -                 | yes/default                | -                |
| 7th June        | -                 | no                         | 7th July         |

### 📬 Sending reminders

It is also possible to send an email reminder to accounts (when an email is configured).
To send a reminder **14 days after** the last activity:

```shell
occ config:app:set user_retention reminder_days --value='14'
```

You can also provide multiple reminder days as a comma separated list:
```shell
occ config:app:set user_retention reminder_days --value='14,21,28'
```

*Note:* There is no validation of the reminder days against the retention days.
