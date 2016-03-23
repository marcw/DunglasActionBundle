<?php

/*
 * (c) Kévin Dunglas <dunglas@gmail.com>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace Dunglas\ActionBundle\DependencyInjection;

use Symfony\Component\Config\FileLocator;
use Symfony\Component\Config\Resource\DirectoryResource;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\XmlFileLoader;
use Symfony\Component\Finder\Finder;
use Symfony\Component\HttpKernel\DependencyInjection\Extension;

/**
 * {@inheritdoc}
 *
 * @author Kévin Dunglas <dunglas@gmail.com>
 */
class DunglasActionExtension extends Extension
{
    /**
     * {@inheritdoc}
     */
    public function load(array $configs, ContainerBuilder $container)
    {
        $configuration = new Configuration();
        $config = $this->processConfiguration($configuration, $configs);
        $kernelRootDir = $container->getParameter('kernel.root_dir');

        $scannedDirectories = [];
        foreach ($config['directories'] as $pattern) {
            list($classes, $directories) = $this->getClasses($kernelRootDir.DIRECTORY_SEPARATOR.$pattern);
            $scannedDirectories = array_merge($scannedDirectories, $directories);

            foreach ($classes as $class) {
                $this->registerClass($container, $class, $config['tags']);
            }
        }

        foreach ($scannedDirectories as $directory => $v) {
            $container->addResource(new DirectoryResource($directory));
        }

        $container->setParameter('dunglas_action.directories', $scannedDirectories);

        if (class_exists('Symfony\Component\Routing\Loader\AnnotationDirectoryLoader')) {
            $loader = new XmlFileLoader($container, new FileLocator(__DIR__.'/../Resources/config'));
            $loader->load('routing.xml');
        }
    }

    /**
     * Gets the list of class names in the given directory.
     *
     * @param string $directory
     *
     * @return array
     */
    private function getClasses($directory)
    {
        $classes = [];
        $scannedDirectories = [];
        $includedFiles = [];

        $finder = new Finder();
        try {
            $finder->in($directory)->files()->name('*.php');
        } catch (\InvalidArgumentException $e) {
            return [[], []];
        }

        foreach ($finder as $file) {
            $scannedDirectories[$file->getPath()] = true;
            $sourceFile = $file->getRealpath();
            if (!preg_match('(^phar:)i', $sourceFile)) {
                $sourceFile = realpath($sourceFile);
            }

            require_once $sourceFile;
            $includedFiles[$sourceFile] = true;
        }

        $declared = get_declared_classes();
        foreach ($declared as $className) {
            $reflectionClass = new \ReflectionClass($className);
            $sourceFile = $reflectionClass->getFileName();

            if ($reflectionClass->isAbstract()) {
                continue;
            }

            if (isset($includedFiles[$sourceFile])) {
                $classes[$className] = true;
            }
        }

        return [array_keys($classes), $scannedDirectories];
    }

    /**
     * Registers an action in the container.
     *
     * @param ContainerBuilder $container
     * @param string           $className
     * @param array            $tags
     */
    private function registerClass(ContainerBuilder $container, $className, array $tags)
    {
        if ($container->has($className)) {
            return;
        }

        $definition = $container->register($className, $className);
        $definition->setAutowired(true);

        foreach ($tags as $tagClassName => $classTags) {
            if (!is_a($className, $tagClassName, true)) {
                continue;
            }

            foreach ($classTags as $classTag) {
                $definition->addTag($classTag[0], $classTag[1]);
            }
        }
    }
}
