# ProjectContextReportBundle for Kimai

ProjectContextReportBundle is a Kimai plugin that adds a new report to
Kimai which gives you another overview over a project.

* Project description
* Doughnut of activities (as known in project-details -> activities)
* List of users with the sum of worked activities

Please also have a look on my other UserActivity Report:
[User Activity Report](https://github.com/eudo1111/kimai-user-activity-report.git)

## Permissions

This report is available for all users with the standard **view_reporting** role.

## Screenshot

![Screenshot](./screenshot-project-context.png?raw=true)

## Requirements

* Kimai >= 2.17
* PHP >= 8.0

## Installation

1. **Copy the plugin**

```bash
cd var/plugins/
git clone https://github.com/eudo1111/kimai-project-context-report.git ProjectContextReportBundle
```

2. **Clear the cache**

From your Kimai root directory, run:

```bash
bin/console kimai:reload
```

## License

MIT
