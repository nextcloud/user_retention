# 👤 🗑 User retention

Users are deleted when they did not log into their account within the given number of days. This will also delete all files of the affected users.

> ![Screenshot of the admin settings](docs/screenshot.png)

## Users who did not log in

By default users who have not logged in will be spared from removal. To also take them into consideration, set the config flag accordingly:

`occ config:app:set user_retention keep_users_without_login --value=no`
