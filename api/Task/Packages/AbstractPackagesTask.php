<?php

/*
 * This file is part of Contao Manager.
 *
 * (c) Contao Association
 *
 * @license LGPL-3.0-or-later
 */

namespace Contao\ManagerApi\Task\Packages;

use Contao\ManagerApi\Composer\Environment;
use Contao\ManagerApi\I18n\Translator;
use Contao\ManagerApi\System\ServerInfo;
use Contao\ManagerApi\Task\AbstractTask;
use Contao\ManagerApi\Task\TaskConfig;
use Symfony\Component\Filesystem\Filesystem;

abstract class AbstractPackagesTask extends AbstractTask
{
    /**
     * @var Environment
     */
    protected $environment;

    /**
     * @var Filesystem
     */
    protected $filesystem;

    /**
     * @var ServerInfo
     */
    private $serverInfo;

    /**
     * Constructor.
     *
     * @param Environment $environment
     * @param ServerInfo  $serverInfo
     * @param Filesystem  $filesystem
     * @param Translator  $translator
     */
    public function __construct(Environment $environment, ServerInfo $serverInfo, Filesystem $filesystem, Translator $translator)
    {
        $this->environment = $environment;
        $this->serverInfo = $serverInfo;
        $this->filesystem = $filesystem;

        parent::__construct($translator);
    }

    /**
     * {@inheritdoc}
     */
    public function create(TaskConfig $config)
    {
        return parent::create($config)->setAudit(!$config->getOption('dry_run', false))->setCancellable(true);
    }

    /**
     * {@inheritdoc}
     */
    public function update(TaskConfig $config)
    {
        $this->createBackup($config);

        $status = parent::update($config);

        if (!$this->environment->useCloudResolver() && ($status->hasError() || $status->isStopped())) {
            $this->restoreBackup($config);
        }

        return $status;
    }

    /**
     * {@inheritdoc}
     */
    public function abort(TaskConfig $config)
    {
        $status = parent::abort($config);

        if ($status->hasError() || $status->isStopped()) {
            $this->restoreBackup($config);
        }

        return $status;
    }

    /**
     * @return int|null
     */
    protected function getInstallTimeout()
    {
        $timeout = null;
        $serverConfig = $this->serverInfo->getServerConfig();

        if (isset($serverConfig['timeout']) && $serverConfig['timeout'] > 0) {
            $timeout = (int) $serverConfig['timeout'];

            if (null !== $this->logger) {
                $this->logger->info(sprintf('Configured install timeout of %s seconds for server "%s".', $timeout, $serverConfig['name']));
            }
        }

        return $timeout;
    }

    /**
     * Creates a backup of the composer.json and composer.lock file.
     *
     * @param TaskConfig $config
     */
    protected function createBackup(TaskConfig $config)
    {
        if ($config->getState('backup-created', false)) {
            return;
        }

        if (!$this->filesystem->exists($this->environment->getJsonFile())) {
            if (null !== $this->logger) {
                $this->logger->info('Cannot create composer file backup, source JSON does not exist', ['file' => $this->environment->getJsonFile()]);
            }

            return;
        }

        if (null !== $this->logger) {
            $this->logger->info('Creating backup of composer files');
        }

        foreach ($this->getBackupPaths() as $source => $target) {
            if ($this->filesystem->exists($source)) {
                $this->filesystem->copy($source, $target, true);

                if (null !== $this->logger) {
                    $this->logger->info(sprintf('Copied "%s" to "%s"', $source, $target));
                }
            } elseif (null !== $this->logger) {
                $this->logger->info(sprintf('File "%s" does not exist', $source));
            }
        }

        $config->setState('backup-created', true);
    }

    /**
     * Restores the backup files if a backup was created within this task.
     *
     * @param TaskConfig $config
     */
    protected function restoreBackup(TaskConfig $config)
    {
        if ($config->getState('backup-created', false) && !$config->getState('backup-restored', false)) {
            if (null !== $this->logger) {
                $this->logger->info('Restoring backup of composer files');
            }

            foreach (array_flip($this->getBackupPaths()) as $source => $target) {
                if ($this->filesystem->exists($source)) {
                    $this->filesystem->copy($source, $target, true);
                    $this->filesystem->remove($source);

                    if (null !== $this->logger) {
                        $this->logger->info(sprintf('Copied "%s" to "%s"', $source, $target));
                    }
                } elseif (null !== $this->logger) {
                    $this->logger->info(sprintf('File "%s" does not exist', $source));
                }
            }

            $config->setState('backup-restored', true);
        }
    }

    /**
     * Gets source and backup paths for composer.json and composer.lock.
     *
     * @return array
     */
    private function getBackupPaths()
    {
        return [
            $this->environment->getJsonFile() => sprintf('%s/%s~', $this->environment->getBackupDir(), basename($this->environment->getJsonFile())),
            $this->environment->getLockFile() => sprintf('%s/%s~', $this->environment->getBackupDir(), basename($this->environment->getLockFile())),
        ];
    }
}
