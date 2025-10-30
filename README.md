# UserActivityReportBundle for Kimai

UserActivityReportBundle is a Kimai plugin that adds a new report to
Kimai which let's you summarize the users activities. This especially helps
you if you have global activities over a lot of different projects and if
you want to have an sum of activities per user.

## Screenshot

![Screenshot](./screenshot-user-activity.png?raw=true)

## Requirements

* Kimai >= 2.17
* PHP >= 8.0

## Installation

1. **Copy the plugin**

```bash
cd var/plugins/
git clone https://github.com/eudo1111/kimai-user-activity-report.git UserActivityReportBundle
```

2. **Clear the cache**

From your Kimai root directory, run:

```bash
bin/console kimai:reload
```

## License

MIT
