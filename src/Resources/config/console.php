<?php

namespace Symfony\Component\DependencyInjection\Loader\Configurator;

use STM\ElasticaFastPopulateBundle\Command\FastPopulateCommand;

return static function (ContainerConfigurator $container) {
    $container->services()
        ->set('console.command.fast_populate', FastPopulateCommand::class)
            ->tag('console.command')
    ;
};
