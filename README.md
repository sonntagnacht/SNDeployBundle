# SNDeployBundle
## Installation
    $ composer require sonntagnacht/deploy-bundle 1.0.1

## Configuration

Main Configuration for SNDeployBundle

app/config.yml:
```yaml
sn_deploy:
    composer: "/usr/bin/composer"
    default_environment: "prod"                     # default null
    environments:
        prod:
            # host address of webserver
            ssh_host: example.com
            # ssh-user for webserver
            ssh_user: ftp-user
            # ssh-port for rsync
            ssh_port: 22                            # default 22
            # absolute path of web app on remote server
            remote_app_dir: /var/www/html
            # to permit deploy only from one branch
            branch: "production"                    # default null
            # repository version has to be newer then remote version - uses semver
            check_version: true                     # default true
            # exclude these files & directories, creates a rsync.exclude file in /tmp/sn-deploy-rsync.exclude
            # which is used for deployment
            exclude: []     
            # cache clear commands
            cache_clear:
                - "php bin/console cache:clear --prod"
            # local commands before project will upload
            pre_upload: []
            # local commands after project has been uploaded
            post_upload: []
            # remote commands before project will upload
            pre_upload_remote: []
            # remote commands after project has been uploaded
            post_upload_remote: []
```

To get version informations in twig, you have to add `sn_deploy.twig` service in twig globals.

    twig:
        globals:
            sn_deploy: "@sn_deploy.twig"

## Usages

### Commands

Use `sn:deploy [prod|test|dev]` to deploy your project to your webserver.

If you want to upload a bugfix without a new Version use `sn:deploy [prod|test|dev] --hotfix`

### Full Configuration Example

```yaml
sn_deploy:
    composer: "/usr/local/bin/composer"
    environments:
        prod:
            branch: "production"
            ssh_host: 12.34.56.78
            ssh_user: www-data
            remote_app_dir: /var/www/vhosts/mydomain.com
            check_version: true
            cache_clear:
                - "php bin/console redis:flushall -n"
                - "php bin/console cache:clear"
                - "php bin/console cache:clear -e prod"
            exclude:
                - ".DS_Store"
                - "data"
                - "var/cache"
                - "var/logs"
                - "var/sessions"
            pre_upload:
                - "bin/scripts/install_assets.sh"
                - "bin/scripts/generate_assets.sh"
            pre_upload_remote:
                - "/opt/plesk/php/7.0/bin/php bin/console lexik:maintenance:lock"
            post_upload_remote:
                - "/opt/plesk/php/7.0/bin/php bin/console doctrine:migrations:migrate"
                - "/opt/plesk/php/7.0/bin/php bin/console doctrine:schema:update --dump-sql --force"
                - "/opt/plesk/php/7.0/bin/php bin/console fos:elastica:populate"
                - "/opt/plesk/php/7.0/bin/php bin/console lexik:maintenance:unlock"
                - "cat web/.htaccess_prod >> web/.htaccess"
        test:
            ssh_host: 12.34.56.78
            ssh_user: www-data
            remote_app_dir: /var/www/vhosts/test.mydomain.com
            check_version: false
            cache_clear:
                - "php bin/console redis:flushall -n"
                - "php bin/console cache:clear"
                - "php bin/console cache:clear -e prod"
            exclude:
                - ".DS_Store"
                - "data"
                - "var/cache"
                - "var/logs"
                - "var/sessions"
            pre_upload:
                - "bin/scripts/install_assets.sh"
                - "bin/scripts/generate_assets.sh"
            pre_upload_remote:
                - "/opt/plesk/php/7.0/bin/php bin/console lexik:maintenance:lock"
            post_upload_remote:
                - "/opt/plesk/php/7.0/bin/php bin/console doctrine:migrations:migrate"
                - "/opt/plesk/php/7.0/bin/php bin/console doctrine:schema:update --dump-sql --force"
                - "/opt/plesk/php/7.0/bin/php bin/console fos:elastica:populate"
                - "/opt/plesk/php/7.0/bin/php bin/console lexik:maintenance:unlock"
                - "cat web/.htaccess_test >> web/.htaccess"
```

### Twig-Variables

Get current deployed version use `{{ sn_deploy.version }}`

Get timestamp of last update use `{{ sn_deploy.timestamp|date('Y/m/d H:i') }}`

Get current deployed commit use `{{ sn_deploy.commit }}`
