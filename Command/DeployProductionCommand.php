<?php

namespace SN\DeployBundle\Command;

use SN\ToolboxBundle\Helper\CommandHelper;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Yaml\Yaml;
use vierbergenlars\SemVer\version;


class DeployProductionCommand extends DeployCommand
{

    /**
     * @var OutputInterface
     */
    protected $output;

    protected function configure()
    {
        parent::configure();

        $this
            ->setName('deploy:production')
            ->setDescription('deploy to production');
    }

    /**
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return null
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->env = "prod";

        parent::execute($input, $output);

        $this->output = $output;

        $hotfix = $input->getOption('hotfix');
        $noLock = $input->getOption('no-lock');
        $skipDB = $input->getOption('skip-db');

        $this->checkRepoClean($input, $output);

        $this->composerInstall($output);

        CommandHelper::writeHeadline($output, "performing preflight checks");

        $branch = CommandHelper::executeCommand("git symbolic-ref --short HEAD", $output);
        if (!$hotfix && $this->envConfig['branch'] !== null && $branch !== $this->envConfig['branch']) {
            $this->resetBranch($output);
            throw new \Exception(sprintf("can only deploy when on branch [%s]",
                $this->envConfig['branch']));
        }

        //check remote version number
        $currentVersionNumber = $this->executeRemoteCommand("cat production-version.txt", $output);
        $currentVersion       = new version($currentVersionNumber);
        //check current git tag for next version number
        $nextVersionNumber = CommandHelper::executeCommand("git describe --tags", $output);
        $nextVersion       = new version($nextVersionNumber);

        if ($hotfix) {
            CommandHelper::writeHeadline(
                $output,
                sprintf(
                    'WARNING: Deploying untagged version from branch [%s] from version [%s] to version [%s]',
                    $branch,
                    $currentVersionNumber,
                    $nextVersionNumber
                ),
                '<question>%s</question>'
            );
            CommandHelper::countdown($output, 5);
        }

        if (substr_count($nextVersion->getVersion(), '-') > 1 && !$hotfix) {
            throw new AccessDeniedException(
                sprintf("cannot deploy untagged revision %s", $nextVersion->getVersion())
            );
        }

        //check for valid version
        if (version::cmp($nextVersion, ">", $currentVersion) !== true) {
            if (!$hotfix) {
                throw new AccessDeniedException(
                    sprintf(
                        "cannot deploy local version [%s]. The version [%s] on the server [%s] is more up-to-date.",
                        $nextVersion,
                        $currentVersion,
                        $this->envConfig["host"]
                    )
                );
            }
        }

        // check remote parameters.yml
        $remoteParams = $this->executeRemoteCommand("cat app/config/parameters.yml", $output, false);

        $localParams = file_get_contents(sprintf('%s/config/parameters.yml',
            $this->getContainer()->getParameter('kernel.root_dir')
        ));

        CommandHelper::compareParametersYml($this->output, Yaml::parse($remoteParams), Yaml::parse($localParams));

        $output->writeln(sprintf('<info>remote [parameters.yml] is not missing any keys</info>'));

        $this->rebuildAssets($output);

        CommandHelper::writeHeadline(
            $output,
            sprintf(
                "ready to update [%s] from %s to %s",
                $this->env,
                $currentVersion,
                $nextVersion
            )
        );

        CommandHelper::countdown($output, 10);

        //operation source dir
        if ($input->getOption('source-dir') == null) {
            $sourceDir = realpath(sprintf("%s/..", $this->getContainer()->getParameter("kernel.root_dir")));
        } else {
            $sourceDir = $input->getOption('source-dir');
        }

        if (!$noLock) {
            $this->executeRemoteCommand(
                "php bin/console lexik:maintenance:lock -n",
                $output
            );
        }

        //sync files




        $this->executeRemoteCommand("rm -rf var/cache/*", $output);
        if (!$skipDB) {
            $this->upgradeRemoteDatabase($output);
        }
        //clear cache
        $this->executeRemoteCommand(
            "php bin/console cache:clear --env=prod",
            $output
        );

        //write version number to remote rev file
        $this->executeRemoteCommand(
            sprintf('echo "%s" > %s-version.txt', $nextVersion->getVersion(), self::ENV),
            $output
        );

        $this->replaceRemoteVersion($nextVersion, $output);

        //clear cache
        $this->executeRemoteCommand(
            "php bin/console cache:clear --env=prod",
            $output
        );

        if (!$noLock) {
            $this->executeRemoteCommand(
                "php bin/console lexik:maintenance:unlock -n",
                $output
            );
        }

        CommandHelper::executeCommand('sh bin/scripts/dump_assets.sh', $output);

        return null;
    }
}