<?php
/**
 * Created by PhpStorm.
 * User: max
 * Date: 18.05.16
 * Time: 18:19
 */

namespace SN\DeployBundle\Command;


use SN\ToolboxBundle\Helper\CommandHelper;
use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\File\Exception\AccessDeniedException;
use Symfony\Component\HttpKernel\Kernel;
use Symfony\Component\Yaml\Yaml;
use vierbergenlars\SemVer\version;

class DeployCommand extends ContainerAwareCommand
{

    protected $config = null;
    protected $envConfig = null;
    protected $env;
    protected $remoteVersion = null;
    protected $nextVersion = null;
    protected $remoteParams = true;
    /**
     * @var OutputInterface
     */
    protected $output;
    /**
     * @var InputInterface
     */
    protected $input;

    protected function configure()
    {
        $this
            ->setName('sn:deploy')
            ->setDescription('Deploy project to server')
            ->addArgument('environment',
                null,
                'environment you will deployed',
                'prod')
            ->addOption(
                'source-dir',
                null,
                InputOption::VALUE_OPTIONAL,
                'the directory to start the rsync transmission'
            )
            ->addOption('hotfix',
                null,
                InputOption::VALUE_NONE,
                'Skips semver checks to perform a hotfix quick\'n\'dirty')
            ->addOption('no-lock', null, InputOption::VALUE_NONE, 'Skips locking of app')
            ->addOption('skip-db', null, InputOption::VALUE_NONE, 'Skips db upgrades');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->output = $output;
        $this->input  = $input;


        $hotfix    = $input->getOption('hotfix');
        $noLock    = $input->getOption('no-lock');
        $skipDB    = $input->getOption('skip-db');
        $this->env = $input->getArgument('environment');

        $config = $this->getContainer()->getParameter('sn_deploy.environments');

        if (!key_exists($this->env, $config)) {
            throw new \Exception(sprintf("Configuration for %s not found.", $this->env));
        }

        $this->envConfig = $config[$this->env];
        $this->config    = $this->getContainer()->getParameter('sn_deploy');

        $this->checkRepoClean();
        $this->branchCheck();
        $this->checkVersion();
        $this->checkRemoteParameters();
        $this->rebuildAssets();
        $this->createExculdeFile();

        CommandHelper::writeHeadline(
            $output,
            sprintf(
                "ready to update [%s] from %s to %s",
                $this->env,
                $this->getRemoteVersion(),
                $this->getNextVersion()
            )
        );

        CommandHelper::countdown($output, 10);

        if ($input->getOption('source-dir') == null) {
            $sourceDir = realpath(sprintf("%s/..", $this->getContainer()->getParameter("kernel.root_dir")));
        } else {
            $sourceDir = $input->getOption('source-dir');
        }

        $this->upload($sourceDir);

        $this->copyDistParameters();

        $this->executeRemoteCommand("rm -rf var/cache/*", $this->output);
        if (!$skipDB) {
            $this->upgradeRemoteDatabase($this->output);
        }
        //clear cache
        $this->executeRemoteCommand(
            "php bin/console cache:clear --env=prod",
            $this->output
        );

        //write version number to remote rev file
        $this->executeRemoteCommand(
            sprintf('echo "%s" > version.txt', $this->nextVersion->getVersion()),
            $this->output
        );

        $this->replaceRemoteVersion($this->nextVersion, $this->output);

        //clear cache
        $this->executeRemoteCommand(
            "php bin/console cache:clear --env=prod",
            $this->output
        );

    }

    protected function createExculdeFile()
    {
        $exclude = array();
        if (!empty($this->envConfig["exclude"])) {
            $exclude = $this->envConfig["exclude"];
        }

        CommandHelper::executeCommand("echo 'app/config/parameters.yml' > /tmp/rsyncexclude.txt", $this->output, false);

        foreach ($exclude as $file) {
            CommandHelper::executeCommand(sprintf("echo '%s' > /tmp/rsyncexclude.txt", $file), $this->output, false);
        }

    }

    protected function getRemoteVersion()
    {

        if ($this->remoteVersion === null) {
            $currentVersionNumber = $this->executeRemoteCommand("cat version.txt", $this->output);
            if (empty($currentVersionNumber)) {
                $this->remoteVersion = new version("0.0.0");
            } else {
                $this->remoteVersion = new version($currentVersionNumber);
            }
        }

        return $this->remoteVersion;
    }

    protected function getNextVersion()
    {
        if ($this->nextVersion === null) {
            $nextVersionNumber = CommandHelper::executeCommand("git describe --tags", $this->output);
            $this->nextVersion = new version($nextVersionNumber);
        }

        return $this->nextVersion;
    }

    protected function copyDistParameters()
    {
        if (!$this->remoteParams) {
            $copyParameters = sprintf(
                "cp app/config/parameters.yml.dist app/config/parameters.yml"
            );

            $this->executeRemoteCommand($copyParameters);
        }
    }

    protected function checkVersion()
    {
        $hotfix = $this->input->getOption('hotfix');
        $output = $this->output;
        $branch = CommandHelper::executeCommand("git symbolic-ref --short HEAD", $this->output);


        $currentVersion = $this->getRemoteVersion();
        //check current git tag for next version number
        $nextVersion = $this->getNextVersion();

        if ($hotfix) {
            CommandHelper::writeHeadline(
                $output,
                sprintf(
                    'WARNING: Deploying untagged version from branch [%s] from version [%s] to version [%s]',
                    $branch,
                    $this->getRemoteVersion(),
                    $nextVersion
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
    }

    protected function checkRemoteParameters()
    {
        // check remote parameters.yml
        $remoteParams = $this->executeRemoteCommand("cat app/config/parameters.yml", $this->output, false);

        if (empty($remoteParams)) {
            $this->remoteParams = false;
            $this->output->writeln(sprintf('<info>remote [parameters.yml] is not existing yet.</info>'));

        } else {

            $localParams = file_get_contents(sprintf('%s/config/parameters.yml',
                $this->getContainer()->getParameter('kernel.root_dir')
            ));

            CommandHelper::compareParametersYml($this->output, Yaml::parse($remoteParams), Yaml::parse($localParams));

            $this->output->writeln(sprintf('<info>remote [parameters.yml] is not missing any keys</info>'));

        }
    }

    protected function branchCheck()
    {

        CommandHelper::writeHeadline($this->output, "performing preflight checks");

        $branch = CommandHelper::executeCommand("git symbolic-ref --short HEAD", $this->output);
        if (!$this->input->getOption('hotfix') && $this->envConfig['branch'] !== null && $branch !== $this->envConfig['branch']) {
            $this->resetBranch($this->output);
            throw new \Exception(sprintf("can only deploy when on branch [%s]",
                $this->envConfig['branch']));
        }
    }

    protected function resetBranch(OutputInterface $output)
    {
        CommandHelper::executeCommand(sprintf("%s install", $this->config["composer"]), $output, false);
    }

    protected function upload($sourceDir)
    {
        $rsyncCommand = sprintf(
            "rsync --info=progress2 -r --links --exclude-from /tmp/rsyncexclude.txt --rsh='ssh' %s/ %s@%s:%s",
            $sourceDir,
            $this->envConfig["user"],
            $this->envConfig["host"],
            $this->envConfig["webroot"]
        );

        CommandHelper::executeCommand($rsyncCommand, $this->output);
    }

    protected function preUploadCommand()
    {
        if (isset($this->envConfig["preUpload"]) === false) {
            return;
        }
        foreach ($this->envConfig["preUpload"] as $cmd) {
            CommandHelper::executeCommand($cmd, $this->output);
        }
    }

    protected function postUploadCommand()
    {
        if (isset($this->envConfig["postUpload"]) === false) {
            return;
        }
        foreach ($this->envConfig["postUpload"] as $cmd) {
            CommandHelper::executeCommand($cmd, $this->output);
        }
    }

    public function upgradeRemoteDatabase(OutputInterface $output)
    {
        //migrate db
        $this->executeRemoteCommand(
            "php bin/console doctrine:migrations:migrate --env=prod",
            $output
        );
        //update db schema
        $this->executeRemoteCommand(
            "php bin/console doctrine:schema:update --dump-sql --force --env=prod",
            $output
        );
    }


    /**
     * @param String $command
     * @param boolean $write
     * @return string
     */
    public function executeRemoteCommand($command,
                                         $write = true)
    {
        $cmd = sprintf(
            'ssh %s@%s "cd %s; %s"',
            $this->envConfig["user"],
            $this->envConfig["host"],
            $this->envConfig["webroot"],
            $command
        );

        return CommandHelper::executeCommand($cmd, $this->output, $write);
    }


    public function rebuildAssets()
    {
        $output = $this->output;
        CommandHelper::executeCommand(sprintf("%s install --optimize-autoloader", $this->config["composer"]), $output);
        CommandHelper::executeCommand('rm -rf web/bundles/*', $output);
        CommandHelper::executeCommand('rm -rf web/js/*', $output);
        CommandHelper::executeCommand('rm -rf web/css/*', $output);
        CommandHelper::executeCommand('php bin/console cache:clear --env=dev', $output);
        CommandHelper::executeCommand('php bin/console cache:clear --env=prod', $output);
        CommandHelper::executeCommand('php bin/console assets:install', $output);
    }

    public function replaceRemoteVersion($nextVersion, OutputInterface $output)
    {
        //replace version in login layout
        $this->executeRemoteCommand(
            sprintf(
                "sed -i 's/~~version~~/%s/g' app/config/version.yml",
                $nextVersion
            ),
            $output
        );
//        $this->executeRemoteCommand(
//            sprintf(
//                "sed -i 's/@@version@@/%s/g' src/UO/Bundle/ChangeListBundle/Resources/views/version.html.twig",
//                $nextVersion
//            ),
//            $output
//        );
    }

    public function checkRepoClean()
    {
        $output = $this->output;
        $input  = $this->input;

        CommandHelper::writeHeadline($output, "checking git status");
        CommandHelper::executeCommand("git status", $output);
        $output->writeln("");
        $helper   = $this->getHelper('question');
        $question = new ConfirmationQuestion('<question>Does that look OK to you? (y/n)</question>', false);

        if (!$helper->ask($input, $output, $question)) {
            throw new \Exception(sprintf("better fix it then..."));
        }

    }

    public function stage()
    {
        $output = $this->output;
        CommandHelper::writeHeadline($output, sprintf('Copying to Staging Directory'));

        $files = new Filesystem();
        // using new vars because Filesystem component automatically detects the correct paths
        /**
         * @var $kernel Kernel
         */
        $kernel   = $this->getContainer()->get('kernel');
        $webDir   = sprintf('%s/../', $kernel->getRootDir());
        $stageDir = sprintf('%s/../../stage/', $kernel->getRootDir());

        //create stageDir if not exists
        if ($files->exists($stageDir) == false) {
            $files->mkdir($stageDir);
        } //otherwise clear it
        else {
            CommandHelper::executeCommand(sprintf("rm -rf %s*", $stageDir), $output);
        }

        $output->writeln(sprintf('Copying <info>%s</info> to <comment>%s</comment>', $webDir, $stageDir));

        $files->mirror($webDir, $stageDir, null, array('override' => true, 'delete' => true));

        return $stageDir;
    }

}