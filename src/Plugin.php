<?php

namespace Igorgoroshit\Ppm;

use Composer\Composer;
use Composer\IO\IOInterface;
use Composer\Plugin\PluginInterface;

class Plugin implements PluginInterface 
{
    protected Composer $composer;
    protected IOInterface $io;


    public function activate(Composer $composer, IOInterface $io)
    {
        $config = $composer->getConfig()->get('ppm');

        if (!isset($config['enabled']) || $config['enabled']) {

            $this->composer = $composer;
            $this->io       = $io;

            $composer->getInstallationManager()
                ->addInstaller(
                    new NpmInstaller($this->io, $this->composer, 'npm-asset')
                );

            
            $composer->getRepositoryManager()
                ->addRepository(
                    new NpmRepository(
                        [],
                        $composer,
                        $io,
                        $composer->getConfig(),
                        $composer->getLoop()->getHttpDownloader(),
                        $composer->getEventDispatcher()
                    )
                );
    

            if (isset($config['repositories']) && is_array($config['repositories'])) 
            {
                foreach ($config['repositories'] as $repository) 
                {
                    $type = $repository['type'];
                    unset($repository['type']);

                    $repo = new NpmRepository(
                        $repository,
                        $composer,
                        $io,
                        $composer->getConfig(),
                        $composer->getLoop()->getHttpDownloader(),
                        $composer->getEventDispatcher()
                    );
                       
                    $composer->getRepositoryManager()->addRepository($repo);
                }
            }
        }
    }


    public function deactivate(Composer $composer, IOInterface $io)
    {
    }

    public function uninstall(Composer $composer, IOInterface $io)
    {
    }

}
