# #!/bin/bash

# set -e

# echo "Starting RehabiAnex API..."

# mkdir -p storage/app/firebase
# mkdir -p storage/framework/cache
# mkdir -p storage/framework/sessions
# mkdir -p storage/framework/views
# mkdir -p bootstrap/cache

# chown -R www-data:www-data storage bootstrap/cache
# chmod -R 775 storage bootstrap/cache

# php artisan optimize:clear

# if [ "$APP_ENV" = "production" ]; then
#     php artisan config:cache
#     php artisan route:cache
# fi

# apache2-foreground


#!/bin/sh
set -e

echo "Starting RehabiAnex API ..."

php artisan optimize:clear
php artisan config:clear
php artisan route:clear
php artisan cache:clear
php artisan view:clear

apache2-foreground
