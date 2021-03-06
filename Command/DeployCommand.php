<?php
/**
 * Created by PhpStorm.
 * User: max
 * Date: 18.05.16
 * Time: 18:19
 */

namespace SN\DeployBundle\Command;


use SN\DeployBundle\Exception\DeployException;
use SN\DeployBundle\Helper\ParametersHelper;
use SN\ToolboxBundle\Helper\CommandHelper;
use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpKernel\Kernel;
use vierbergenlars\SemVer\version;

class DeployCommand extends ContainerAwareCommand
{

    const RSYNC_EXCLUDE = '/tmp/sn-deploy-rsync.exclude';
    const RSYNC_INCLUDE = '/tmp/sn-deploy-rsync.include';

    protected $config        = null;
    protected $envConfig     = null;
    protected $env;
    protected $remoteVersion = null;
    protected $nextVersion   = null;
    protected $remoteParams  = true;
    protected $hotfix        = false;
    /**
     * @var OutputInterface
     */
    protected $output;
    /**
     * @var InputInterface
     */
    protected $input;

    /**
     * @var array
     */
    protected $version = array();

    protected function configure()
    {

        $this
            ->setName('sn:deploy')
            ->setDescription('Deploy project to server')
            ->addArgument('environment',
                null,
                'environment you watn to deploy',
                null)
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
            ->addOption('skip-parameter-check',
                null,
                InputOption::VALUE_NONE,
                'If set, no parameters.yml options will be compared'
            )
            ->addOption('skip-repo-clean-check',
                null,
                InputOption::VALUE_NONE,
                'If set, no <info>git status</info> check will be made'
            )
            ->addOption('countdown',
                null,
                InputOption::VALUE_OPTIONAL,
                'The time in seconds for a countdown, can be disabled with 0',
                10
            )
            ->addOption('confirm-upload',
                null,
                InputOption::VALUE_NONE,
                'If set, you will be promptet for the upload to start instead of a countdown'
            );
    }

    public function stopCommand()
    {
        $fs                = new Filesystem();
        $remote_parameters = sprintf('%s/config/parameters.yml.remote',
            $this->getContainer()->getParameter("kernel.root_dir"));
        if ($fs->exists($remote_parameters)) {
            $fs->remove($remote_parameters);
        }
    }

    public function __destruct()
    {
        $this->stopCommand();
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {

        $this->output = $output;
        $this->input  = $input;

        $this->hotfix = $input->getOption('hotfix');
        $this->env    = $input->getArgument('environment');
        $config       = $this->getContainer()->getParameter('sn_deploy.environments');

        if ($this->env == null) {
            if (!key_exists("default_environment", $this->getContainer()->getParameter('sn_deploy'))) {
                throw new DeployException(sprintf("Missing argument"));
            }

            $this->env = $this->getContainer()->getParameter('sn_deploy')["default_environment"];
            if (!$this->env) {
                throw new DeployException(sprintf("Missing argument"));
            }
        }


        if (!key_exists($this->env, $config)) {
            throw new DeployException(sprintf("Configuration for %s not found.", $this->env));
        }

        $this->envConfig = $config[$this->env];
        $this->config    = $this->getContainer()->getParameter('sn_deploy');

        if (false === $input->getOption('skip-repo-clean-check')) {
            $this->checkRepoClean();
        }
        $this->checkBranch();
        $this->checkVersion();
        if (false === $input->getOption('skip-parameter-check')) {
            $this->checkRemoteParameters();
        }

        $commit     = $this->getContainer()->get('sn_deploy.twig')->getCommit();
        $commitLong = $this->getContainer()->get('sn_deploy.twig')->getCommit(false);
        $version    = $this->nextVersion->getVersion();

        $this->version = array(
            "commit"      => $commit,
            "commit_long" => $commitLong,
            "version"     => $version,
            "timestamp"   => time(),
        );

        $this->composerInstall();
        $this->cacheClear();
        $this->createIncludeFile();
        $this->createExcludeFile();
        $this->preUploadCommand();

        CommandHelper::writeHeadline(
            $output,
            sprintf(
                "ready to update [%s] from %s to %s",
                $this->env,
                $this->getRemoteVersion(),
                $this->getNextVersion()
            )
        );

        if ($input->getOption('confirm-upload')) {
            $helper   = $this->getHelper('question');
            $question = new ConfirmationQuestion('<question>Proceed with upload? (Y/n)</question>', true);

            if (!$helper->ask($input, $output, $question)) {
                throw new \Exception('Deployment aborted.');
            }
        } else {
            CommandHelper::countdown($output, intval($input->getOption('countdown')));
        }

        if ($input->getOption('source-dir') == null) {
            $sourceDir = realpath(sprintf("%s/..", $this->getContainer()->getParameter("kernel.root_dir")));
        } else {
            $sourceDir = $input->getOption('source-dir');
        }

        $this->preUploadRemoteCommand();
        $this->upload($sourceDir);
        $this->copyRemoteParameters();
        $this->executeRemoteCommand("rm -rf var/cache/*", false);
        $this->executeRemoteCommand("rm -rf app/cache/*", false);
        $this->remoteCacheClear();

        $this->setRemoteVersion();
        $this->postUploadRemoteCommand();

        $this->remoteCacheClear();
        $this->postUploadCommand();

        $output->writeln('');
        $output->writeln(sprintf('<info>done.</info>'));
        $output->writeln('');
    }

    protected function preUploadRemoteCommand()
    {
        if (isset($this->envConfig["pre_upload_remote"]) === false) {
            return;
        }
        foreach ($this->envConfig["pre_upload_remote"] as $cmd) {
            $this->executeRemoteCommand($cmd, true);
        }
    }

    protected function postUploadRemoteCommand()
    {
        if (isset($this->envConfig["post_upload_remote"]) === false) {
            return;
        }
        foreach ($this->envConfig["post_upload_remote"] as $cmd) {
            $this->executeRemoteCommand($cmd, true);
        }
    }

    protected function setRemoteVersion()
    {
        $this->version['timestamp'] = time();

        $this->executeRemoteCommand(
            sprintf("echo \"%s\" > deploy.json", addslashes(json_encode($this->version))),
            false);
    }

    protected function copyRemoteParameters()
    {
        $this->executeRemoteCommand("mv app/config/parameters.yml.remote app/config/parameters.yml", false);
    }

    /**
     * @deprecated since symfony 3.4
     * see https://github.com/symfony/symfony/issues/25280
     */
    protected function cacheClear()
    {
        $cacheClear = $this->envConfig["cache_clear"];

        foreach ($cacheClear as $cmd) {
            CommandHelper::execute($cmd);
        }
    }

    protected function remoteCacheClear()
    {
        $cacheClear = $this->envConfig["cache_clear"];

        foreach ($cacheClear as $cmd) {
            $this->executeRemoteCommand($cmd, false);
        }
    }

    protected function createIncludeFile()
    {
        $include = array();
        if (!empty($this->envConfig["include"])) {
            $include = $this->envConfig["include"];
        }

        CommandHelper::execute(
            sprintf("touch %s", self::RSYNC_INCLUDE),
            ['output' => $this->output, 'print_output' => true]
        );

        foreach ($include as $file) {
            CommandHelper::execute(
                sprintf("echo '%s' >> %s", $file, self::RSYNC_INCLUDE),
                ['output' => $this->output, 'print_output' => true]
            );
        }
    }

    protected function createExcludeFile()
    {
        $exclude = array();
        if (!empty($this->envConfig["exclude"])) {
            $exclude = $this->envConfig["exclude"];
        }

        CommandHelper::execute(
            sprintf("echo 'app/config/parameters.yml' > %s", self::RSYNC_EXCLUDE),
            ['output' => $this->output, 'print_output' => true]
        );

        foreach ($exclude as $file) {
            CommandHelper::execute(
                sprintf("echo '%s' >> %s", $file, self::RSYNC_EXCLUDE),
                ['output' => $this->output, 'print_output' => true]
            );
        }
    }

    protected function getRemoteVersion()
    {

        if ($this->remoteVersion === null) {
            $deployJSON = json_decode($this->executeRemoteCommand("cat deploy.json", false), true);
            if (empty($deployJSON["version"])) {
                $this->remoteVersion = new version("0.0.0");
            } else {
                $this->remoteVersion = new version($deployJSON["version"]);
            }
        }

        return $this->remoteVersion;
    }

    protected function getNextVersion()
    {
        if ($this->nextVersion === null) {
            $nextVersionNumber = CommandHelper::execute("git describe --tags");
            $this->nextVersion = new version($nextVersionNumber);
        }

        return $this->nextVersion;
    }

    protected function getBranch()
    {
        return CommandHelper::execute("git symbolic-ref --short HEAD");

    }

    protected function checkVersion()
    {

        $output = $this->output;

        $currentVersion = $this->getRemoteVersion();
        $branch         = $this->getBranch();
        //check current git tag for next version number
        $nextVersion = $this->getNextVersion();

        if (
            (isset($this->envConfig['check_version']) && false === $this->envConfig['check_version']) ||
            true === $this->hotfix
        ) {
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

            CommandHelper::countdown($this->output, 5);

            return;
        }

        if (substr_count($nextVersion->getVersion(), '-') > 1) {
            throw new DeployException(
                sprintf("cannot deploy untagged revision %s", $nextVersion->getVersion())
            );
        }

        //check for valid version
        if (version::cmp($nextVersion, ">", $currentVersion) !== true) {
            throw new DeployException(
                sprintf(
                    "cannot deploy local version [%s]. The version [%s] on the server [%s] is more up-to-date.",
                    $nextVersion,
                    $currentVersion,
                    $this->envConfig["ssh_host"]
                )
            );
        }
    }

    protected function checkRemoteParameters()
    {
        $parameterHelper = new ParametersHelper($this->input, $this->output, $this->getHelper('question'));

        // Download paramters.yml -> parameters.yml.remote
        $remoteParams = $this->executeRemoteCommand("cat app/config/parameters.yml", false);
        $fs           = new Filesystem();
        $fs->dumpFile('app/config/parameters.yml.remote', $remoteParams);

        // Compare parameters.yml -> parameters.yml.remote
        $parameterHelper->processFile(array(
            "file"      => "app/config/parameters.yml.remote",
            "dist-file" => "app/config/parameters.yml"
        ));
    }

    protected function checkBranch()
    {
        if (null === $this->envConfig['branch'] || true === $this->hotfix) {
            return;
        }
        CommandHelper::writeHeadline($this->output, "performing preflight checks");

        $branch = CommandHelper::execute("git symbolic-ref --short HEAD",
            ['output' => $this->output, 'print_output' => true]);
        if ($branch !== $this->envConfig['branch']) {
            throw new DeployException(
                sprintf("can only deploy when on branch [%s]", $this->envConfig['branch'])
            );
        }
    }

    protected function upload($sourceDir)
    {
        $rsyncCommand = sprintf(
            "rsync %s --include-from=%s --exclude-from=%s --rsh='ssh -p %s' %s/ %s@%s:%s",
            $this->envConfig["rsync_options"],
            self::RSYNC_INCLUDE,
            self::RSYNC_EXCLUDE,
            $this->envConfig["ssh_port"],
            $sourceDir,
            $this->envConfig["ssh_user"],
            $this->envConfig["ssh_host"],
            $this->envConfig["remote_app_dir"]
        );

        CommandHelper::execute($rsyncCommand, ['output' => $this->output, 'print_output' => true]);
    }

    protected function preUploadCommand()
    {
        if (isset($this->envConfig["pre_upload"]) === false) {
            return;
        }
        foreach ($this->envConfig["pre_upload"] as $cmd) {
            CommandHelper::execute($cmd, ['output' => $this->output, 'print_output' => true]);
        }
    }

    protected function postUploadCommand()
    {
        if (isset($this->envConfig["post_upload"]) === false) {
            return;
        }
        foreach ($this->envConfig["post_upload"] as $cmd) {
            CommandHelper::execute($cmd, ['output' => $this->output, 'print_output' => true]);
        }
    }

    /**
     * @param String $command
     * @param boolean $write
     * @return string
     */
    public function executeRemoteCommand($command, $write = true)
    {
        $cmd = sprintf(
            'ssh %s@%s -p%s "cd %s; %s"',
            $this->envConfig["ssh_user"],
            $this->envConfig["ssh_host"],
            $this->envConfig["ssh_port"],
            $this->envConfig["remote_app_dir"],
            addslashes($command)
        );

        if ($write) {
            return CommandHelper::execute($cmd, ['output' => $this->output, 'print_output' => $write]);
        } else {
            return CommandHelper::execute($cmd);
        }

    }


    public function composerInstall()
    {
        $output = $this->output;
        CommandHelper::execute(sprintf("%s install", $this->config["composer"]),
            ['output' => $this->output, 'print_output' => true]);
    }

    public function checkRepoClean()
    {
        $output = $this->output;
        $input  = $this->input;

        CommandHelper::writeHeadline($output, "checking git status");
        CommandHelper::execute("git status", ['output' => $this->output, 'print_output' => true]);
        $output->writeln("");
        $helper   = $this->getHelper('question');
        $question = new ConfirmationQuestion('<question>Does that look OK to you? (Y/n)</question>', true);

        if (!$helper->ask($input, $output, $question)) {
            throw new DeployException(sprintf("better fix it then..."));
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
            CommandHelper::execute(sprintf("rm -rf %s*", $stageDir),
                ['output' => $this->output, 'print_output' => true]);
        }

        $output->writeln(sprintf('Copying <info>%s</info> to <comment>%s</comment>', $webDir, $stageDir));

        $files->mirror($webDir, $stageDir, null, array('override' => true, 'delete' => true));

        return $stageDir;
    }

}
