#!/bin/bash
# Signs in as the demo account with curl (cookie jar ./jar) and opens the launcher,
# which creates the workspace. Writes the launcher page to ./launcher.html.
#   EDS_URL=http://localhost:58440 tests/demo-login.sh
B=${EDS_URL:-http://localhost:58440}
rm -f jar
curl -s -o /dev/null -c jar -b jar "$B/wp-login.php"
curl -s -o /dev/null -c jar -b jar -b "wordpress_test_cookie=WP+Cookie+check" -d "log=demo&pwd=demo&wp-submit=Log+In&testcookie=1&redirect_to=$B/wp-admin/" "$B/wp-login.php"
curl -s -o /dev/null -L -c jar -b jar "$B/wp-admin/admin.php?page=elementor-sandbox"
curl -s -c jar -b jar "$B/wp-admin/admin.php?page=elementor-sandbox" -o launcher.html -w "launcher %{http_code}\n"
