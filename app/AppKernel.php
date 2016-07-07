<?php

/**
 * This file is part of contao/contao-manager.
 *
 * (c) Christian Schiffler <c.schiffler@cyberspectrum.de>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * This project is provided in good faith and hope to be usable by anyone.
 *
 * @package    contao/contao-manager
 * @author     Christian Schiffler <c.schiffler@cyberspectrum.de>
 * @author     Yanick Witschi <yanick.witschi@terminal42.ch>
 * @copyright  2015 Christian Schiffler <c.schiffler@cyberspectrum.de>
 * @license    https://github.com/contao/contao-manager/blob/master/LICENSE MIT
 * @link       https://github.com/contao/contao-manager
 * @filesource
 */

use AppBundle\AppBundle;
use Doctrine\Common\Annotations\AnnotationRegistry;
use Nelmio\ApiDocBundle\NelmioApiDocBundle;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\MonologBundle\MonologBundle;
use Symfony\Bundle\SecurityBundle\SecurityBundle;
use Symfony\Component\Filesystem\Exception\IOException;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpKernel\Config\EnvParametersResource;
use Symfony\Component\HttpKernel\DependencyInjection\AddClassesToCachePass;
use Symfony\Component\HttpKernel\Kernel;
use Symfony\Component\Config\Loader\LoaderInterface;
use Tenside\CoreBundle\TensideCoreBundle;

/**
 * This class is the main kernel for the application.
 */
class AppKernel extends Kernel
{
    /**
     * The dependency container.
     *
     * @var Symfony\Component\DependencyInjection\Container
     */
    protected $container;

    /**
     * {@inheritDoc}
     *
     * @throws \LogicException When the container is not present yet.
     *
     * @throws IOException When the directory is not writable.
     */
    public function getLogDir()
    {
        if (!$this->container) {
            throw new \LogicException('The container has not been set yet.');
        }

        $logDir = $this->container->get('tenside.home')->homeDir() . '/tenside/logs';
        if (!is_dir($logDir)) {
            $fileSystem = new Filesystem();
            $fileSystem->mkdir($logDir);
        }

        if (!is_writable($logDir)) {
            throw new IOException(sprintf('The directory "%s" is not writable.', $logDir), 0, null, $logDir);
        }

        return $this->container->get('tenside.home')->homeDir() . '/tenside/logs';
    }

    /**
     * {@inheritDoc}
     */
    public function registerBundles()
    {
        $bundles = [
            new SecurityBundle(),
            new TensideCoreBundle(),
            new FrameworkBundle(),
            new MonologBundle(),
            new AppBundle()
        ];

        if ('phar' !== $this->getEnvironment()) {
            $bundles[] = new NelmioApiDocBundle();
            $bundles[] = new \Symfony\Bundle\TwigBundle\TwigBundle();

            // Load the annotation if it get's mentioned, Doctrine does not try to autoload it via plain PHP.
            AnnotationRegistry::registerLoader(
                function ($class) {
                    if (0 === strcmp('Nelmio\\ApiDocBundle\\Annotation\\ApiDoc', $class)) {
                        class_exists('Nelmio\\ApiDocBundle\\Annotation\\ApiDoc');
                        return true;
                    }

                    return false;
                }
            );
        }

        return $bundles;
    }

    /**
     * {@inheritDoc}
     *
     * Overridden to get rid of 'kernel.logs_dir'.
     */
    protected function getKernelParameters()
    {
        $bundles = array();
        foreach ($this->bundles as $name => $bundle) {
            $bundles[$name] = get_class($bundle);
        }

        return array_merge(
            array(
                'kernel.root_dir' => realpath($this->rootDir) ?: $this->rootDir,
                'kernel.environment' => $this->environment,
                'kernel.debug' => $this->debug,
                'kernel.name' => $this->name,
                'kernel.cache_dir' => realpath($this->getCacheDir()) ?: $this->getCacheDir(),
                'kernel.bundles' => $bundles,
                'kernel.charset' => $this->getCharset(),
                'kernel.container_class' => $this->getContainerClass(),
            ),
            $this->getEnvParameters()
        );
    }

    /**
     * {@inheritDoc}
     *
     * Overridden to get rid of 'logs' writable check.
     *
     * @throws \RuntimeException When the cache directory is not writable.
     */
    protected function buildContainer()
    {
        foreach (['cache' => $this->getCacheDir()] as $name => $dir) {
            if (!is_dir($dir)) {
                // @codingStandardsIgnoreStart - allow silencing here.
                if (false === @mkdir($dir, 0777, true) && !is_dir($dir)) {
                    // @codingStandardsIgnoreEnd
                    throw new \RuntimeException(sprintf("Unable to create the %s directory (%s)\n", $name, $dir));
                }
            } elseif (!is_writable($dir)) {
                throw new \RuntimeException(sprintf("Unable to write in the %s directory (%s)\n", $name, $dir));
            }
        }

        $container = $this->getContainerBuilder();
        $container->addObjectResource($this);
        $this->prepareContainer($container);

        if (null !== $cont = $this->registerContainerConfiguration($this->getContainerLoader($container))) {
            $container->merge($cont);
        }

        $container->addCompilerPass(new AddClassesToCachePass($this));
        $container->addResource(new EnvParametersResource('SYMFONY__'));

        return $container;
    }

    /**
     * {@inheritDoc}
     */
    public function registerContainerConfiguration(LoaderInterface $loader)
    {
        // The container is already built, therefore it should be safe to omit loading from phar.
        if (!\Phar::running()) {
            $loader->load(__DIR__ . '/config/config_' . $this->getEnvironment() . '.yml');
        }
    }
}
