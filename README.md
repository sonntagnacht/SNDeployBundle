# SNDeployBundle
## Installation
    composer require sonntagnacht/deploy-bundle "master-dev"

## Configuration

Main Configuration for SNDeployBundle

app/config.yml:
```yaml
sn_deploy:
    composer: "/usr/bin/composer"
    default: "prod"                     # default null
    environments:
        # environments can be prod, test and dev 
        prod:
            # host address of webserver
            host: example.com
            # ssh-user for webserver
            user: ftp-user
            # absolute path of webservice
            webroot: /var/www/html
            # to permit deploy only from one branch
            branch: "production"        # default null
            # repository version has to be newer then remote version 
            check_version: false        # default false  
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
            sn_deploy: "sn_deploy.twig"

## Usages

### Commands

Use `sn:deploy [prod|test|dev]` to deploy your project to your webserver.

If you want to upload a bugfix without a new Version use `sn:deploy [prod|test|dev] --hotfix`

### Twig-Variables

Get current deployed version use `{{ sn_deploy.version }}`

Get timestamp of last update use `{{ sn_deploy.timestamp|date('Y/m/d H:i') }}`

Get current deployed commit use `{{ sn_deploy.commit }}`