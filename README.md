# SNDeployBundle
## Installation

app/config.yml:

    sn_deploy:
        composer: "/usr/bin/composer"
        environments: 
            prod:
                host: "example.com"
                user: "ftp-user"
                webroot: "/var/www/html"
                branch: "production"
                versionCheck: true
                preUpload: []
                postUpload: []
